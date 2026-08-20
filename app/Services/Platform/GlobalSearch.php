<?php

namespace App\Services\Platform;

use App\Models\BuyerRequest;
use App\Models\Course;
use App\Models\JobListing;
use App\Models\MentorProfile;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * One search across everything the platform lists publicly.
 *
 * Five types, one box. Somebody looking for "broiler" might want feed, a course
 * about broilers, a mentor who knows them, a farm hiring someone to raise them,
 * or another buyer wanting to buy some — and asking them to guess which of five
 * search boxes to use is asking them to know the platform's internal shape.
 *
 * Two rules run through all of it.
 *
 * Every query starts from that type's own public-visibility scope, never from
 * an unscoped model. Search is the easiest place in an application to leak a
 * draft, an unapproved listing or a suspended seller, because it touches
 * everything and its results are a list of fragments nobody looks at closely.
 *
 * And no worker profiles. The worker directory is deliberately not searchable
 * from here: those records exist to be found by employers who have registered
 * and accepted a rate limit, and a global search box is precisely the harvesting
 * route the jobs module was built to close. Job LISTINGS are searchable — they
 * are farms advertising, not people.
 */
class GlobalSearch
{
    /**
     * Rows per type. Enough to see whether the right thing is in there.
     */
    private const PER_TYPE = 6;

    /**
     * Shorter than this and a search matches half the database.
     */
    public const MIN_LENGTH = 2;

    /**
     * @return array<int, string>
     */
    public static function types(): array
    {
        return ['products', 'courses', 'mentors', 'jobs', 'requests'];
    }

    /**
     * @param  array<int, string>  $only  Restrict to these types, or all of them.
     * @return array<string, mixed>
     */
    public function search(string $term, array $only = []): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return ['term' => $term, 'tooShort' => true, 'groups' => [], 'total' => 0];
        }

        $wanted = $only === [] ? self::types() : array_values(array_intersect(self::types(), $only));

        $groups = array_values(array_filter(array_map(
            fn (string $type): ?array => $this->group($type, $term),
            $wanted,
        )));

        return [
            'term' => $term,
            'tooShort' => false,
            'groups' => $groups,
            'total' => array_sum(array_column($groups, 'total')),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function group(string $type, string $term): ?array
    {
        $group = match ($type) {
            'products' => $this->products($term),
            'courses' => $this->courses($term),
            'mentors' => $this->mentors($term),
            'jobs' => $this->jobs($term),
            'requests' => $this->requests($term),
            default => null,
        };

        // An empty group is dropped rather than rendered as a heading with
        // "nothing here" under it, five times over.
        return $group === null || $group['total'] === 0 ? null : $group;
    }

    /**
     * @return array<string, mixed>
     */
    private function products(string $term): array
    {
        $query = Product::query()
            ->visible()
            ->with(['seller'])
            ->where(fn (Builder $inner) => $inner
                ->where('products.name', 'like', "%{$term}%")
                ->orWhere('products.description', 'like', "%{$term}%"));

        return $this->build('products', __('In the market'), $query, route('catalogue.home', ['q' => $term]),
            fn (Product $product): array => [
                'title' => $product->name,
                'meta' => $product->seller?->business_name,
                'amount' => Money::fromKobo($product->price_kobo),
                'href' => route('catalogue.product', $product),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function courses(string $term): array
    {
        $query = Course::query()
            ->published()
            ->where(fn (Builder $inner) => $inner
                ->where('title', 'like', "%{$term}%")
                ->orWhere('summary', 'like', "%{$term}%"));

        return $this->build('courses', __('Training'), $query, route('academy.catalogue', ['q' => $term]),
            fn (Course $course): array => [
                'title' => $course->title,
                'meta' => $course->summary,
                'amount' => $course->price_kobo > 0 ? Money::fromKobo($course->price_kobo) : __('Free'),
                'href' => route('academy.course', $course),
            ]);
    }

    /**
     * Mentors, by what they know rather than who they are.
     *
     * Nothing here reaches a contact detail: a mentor's phone arrives with a
     * paid engagement, and the profile page this links to enforces that.
     *
     * @return array<string, mixed>
     */
    private function mentors(string $term): array
    {
        $query = MentorProfile::query()
            ->bookable()
            ->with(['user', 'specialisations'])
            ->where(fn (Builder $inner) => $inner
                ->where('headline', 'like', "%{$term}%")
                ->orWhere('bio', 'like', "%{$term}%")
                ->orWhereHas('specialisations', fn (Builder $tags) => $tags
                    ->where('name', 'like', "%{$term}%")));

        return $this->build('mentors', __('Mentors'), $query, route('mentors.find'),
            fn (MentorProfile $mentor): array => [
                'title' => $mentor->user?->displayName() ?? __('A mentor'),
                'meta' => $mentor->headline,
                'href' => route('mentors.show', $mentor),
            ]);
    }

    /**
     * Farms advertising work. Not workers — see the class note.
     *
     * @return array<string, mixed>
     */
    private function jobs(string $term): array
    {
        $query = JobListing::query()
            ->onBoard()
            ->with(['employer'])
            ->where(fn (Builder $inner) => $inner
                ->where('title', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%"));

        return $this->build('jobs', __('Farm jobs'), $query, route('jobs.index', ['q' => $term]),
            fn (JobListing $listing): array => [
                'title' => $listing->title,
                'meta' => trim(($listing->employer?->business_name ?? '').' · '.($listing->state ?? ''), ' ·'),
                'href' => route('jobs.show', $listing),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function requests(string $term): array
    {
        $query = BuyerRequest::query()
            ->publiclyVisible()
            ->where(fn (Builder $inner) => $inner
                ->where('title', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%"));

        return $this->build('requests', __('People wanting to buy'), $query, route('requests.index'),
            fn (BuyerRequest $request): array => [
                'title' => $request->title,
                // delivery_state, not state: a buyer request names where the
                // goods have to GET to, which is not necessarily where the
                // buyer is.
                'meta' => $request->delivery_state,
                'href' => route('requests.show', $request),
            ]);
    }

    /**
     * Count once, take a page, shape the rows.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<string, mixed>
     */
    private function build(string $key, string $label, Builder $query, string $allHref, callable $row): array
    {
        /*
         * The count runs before the limit, on a clone. The total is what makes
         * "6 of 148" possible, and taking it from the limited result would show
         * "6 of 6" on every search that matched anything at all.
         */
        $total = (clone $query)->toBase()->getCountForPagination();

        return [
            'key' => $key,
            'label' => $label,
            'total' => $total,
            'allHref' => $allHref,
            'rows' => $query->limit(self::PER_TYPE)->get()->map($row)->all(),
        ];
    }
}

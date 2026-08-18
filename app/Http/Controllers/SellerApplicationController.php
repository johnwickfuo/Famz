<?php

namespace App\Http\Controllers;

use App\Enums\BusinessType;
use App\Http\Requests\SellerApplicationRequest;
use App\Models\Category;
use App\Models\SellerProfile;
use App\Services\Sellers\SellerApplicationService;
use App\Support\Nigeria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public route into selling. Anyone with an account can apply; an
 * administrator decides.
 */
class SellerApplicationController extends Controller
{
    public function create(Request $request): Response
    {
        $seller = $request->user()->sellerProfile;

        return Inertia::render('Seller/Apply', [
            'application' => $seller === null ? null : [
                'status' => $seller->status->value,
                'status_label' => $seller->status->label(),
                'review_notes' => $seller->review_notes,
                'is_open' => $seller->status->isOpenToApplicant(),
                'submitted_at' => $seller->submitted_at?->toDateString(),
                'business_name' => $seller->business_name,
                'cac_number' => $seller->cac_number,
                'business_type' => $seller->business_type->value,
                'address' => $seller->address,
                'state' => $seller->state,
                'lga' => $seller->lga,
                'phone' => $seller->phone,
                'whatsapp' => $seller->whatsapp,
                'description' => $seller->description,
                'categories' => $seller->categories()->pluck('categories.id')->all(),
                'has_id_document' => filled($seller->id_document),
                'logo_url' => $seller->logoUrl(),
            ],
            'businessTypes' => collect(BusinessType::cases())
                ->map(fn (BusinessType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ])->all(),
            'states' => Nigeria::states(),
            'categoryGroups' => $this->categoryGroups(),
        ]);
    }

    public function store(SellerApplicationRequest $request, SellerApplicationService $applications): RedirectResponse
    {
        $attributes = $request->profileAttributes();
        $disk = config('filesystems.default');

        if ($request->hasFile('id_document')) {
            $attributes['id_document'] = $request->file('id_document')->store('seller-documents', ['disk' => $disk]);
        }

        if ($request->hasFile('logo')) {
            $existing = $request->existingProfile()?->logo;

            if (filled($existing)) {
                Storage::disk($disk)->delete($existing);
            }

            $attributes['logo'] = $request->file('logo')->store('seller-logos', ['disk' => $disk]);
        }

        $applications->submit($request->user(), $attributes, $request->categoryIds());

        return redirect()
            ->route('seller-application.create')
            ->with('success', __('Your application is in. We will email you when it has been reviewed.'));
    }

    /**
     * Top-level categories with their immediate children, which is the shape
     * the category picker on the form needs.
     *
     * @return array<int, array{id: int, name: string, children: array<int, array{id: int, name: string}>}>
     */
    private function categoryGroups(): array
    {
        return Category::query()
            ->active()
            ->roots()
            ->ordered()
            ->with(['children' => fn ($query) => $query->where('is_active', true)])
            ->get()
            ->map(fn (Category $root): array => [
                'id' => $root->id,
                'name' => $root->name,
                'children' => $root->children
                    ->map(fn (Category $child): array => ['id' => $child->id, 'name' => $child->name])
                    ->all(),
            ])
            ->all();
    }
}

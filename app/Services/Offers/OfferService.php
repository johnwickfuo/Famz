<?php

namespace App\Services\Offers;

use App\Enums\BuyerRequestStatus;
use App\Enums\OfferStatus;
use App\Models\BuyerRequest;
use App\Models\NegotiatedPurchase;
use App\Models\Offer;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One haggle, whatever it is about.
 *
 * A buyer arguing over a listing and a seller answering a wanted ad are the
 * same transaction from opposite ends: somebody proposes a price and a
 * quantity, somebody else says yes, no, or how about this. Writing that twice
 * would eventually give two different answers to "what did we agree", so it is
 * written once and pointed at whatever is being haggled over.
 *
 * Nothing here rewrites an earlier offer. A counter is a new row pointing at
 * the one it answers, and the answered one is marked `countered` — so the
 * chain reads back as the argument actually happened.
 */
class OfferService
{
    public function __construct(private readonly OfferNotifier $notifier) {}

    /**
     * How long an offer stands before it lapses.
     */
    public function windowHours(): int
    {
        return max(1, (int) settings('offer_expiry_hours', 72));
    }

    /**
     * How long an accepted offer's private checkout link is good for.
     */
    public function checkoutWindowHours(): int
    {
        return max(1, (int) settings('negotiated_checkout_hours', 48));
    }

    // -----------------------------------------------------------------------
    // Making offers
    // -----------------------------------------------------------------------

    /**
     * A buyer proposes a price on a listing.
     */
    public function offerOnProduct(
        Product $product,
        User $buyer,
        int $quantity,
        int $unitPriceKobo,
        ?string $message = null,
    ): Offer {
        if (! $product->is_negotiable) {
            throw new RuntimeException(__('This seller does not take offers on this listing.'));
        }

        if (! $product->status->isPubliclyVisible() || ! $product->seller?->isApproved()) {
            throw new RuntimeException(__('That listing is not on sale.'));
        }

        if ($product->seller->user_id === $buyer->getKey()) {
            throw new RuntimeException(__('You cannot make an offer on your own listing.'));
        }

        if ($this->openOfferBetween($product, $buyer) !== null) {
            throw new RuntimeException(__('You already have an offer waiting on this listing.'));
        }

        $this->assertQuantity($quantity, $product->min_order_quantity, $product->availableStock());
        $this->assertPrice($unitPriceKobo);

        $offer = $this->create(
            offerable: $product,
            initiator: $buyer,
            responder: $product->seller->user,
            seller: $product->seller,
            quantity: $quantity,
            unitPriceKobo: $unitPriceKobo,
            message: $message,
        );

        $this->notifier->offerReceived($offer);

        return $offer;
    }

    /**
     * A seller answers a wanted ad.
     */
    public function offerOnRequest(
        BuyerRequest $request,
        // Nullable on purpose: somebody who is not a seller can reach the
        // endpoint, and they deserve a sentence rather than a 500.
        ?SellerProfile $seller,
        int $quantity,
        int $unitPriceKobo,
        ?string $message = null,
        ?int $deliveryDays = null,
    ): Offer {
        $this->assertSellerMayAnswer($request, $seller);

        if (! $request->isOpen()) {
            throw new RuntimeException(__('This request is no longer taking offers.'));
        }

        if ($request->user_id === $seller->user_id) {
            throw new RuntimeException(__('You cannot answer your own request.'));
        }

        if ($this->openOfferFromSeller($request, $seller) !== null) {
            throw new RuntimeException(__('You already have an offer waiting on this request.'));
        }

        // A buyer who said they will not split the job wants all of it or
        // none; offering half would waste both sides' time.
        $minimum = $request->accepts_partial_fulfilment ? 1 : $request->quantity;

        $this->assertQuantity($quantity, $minimum, $request->quantity);
        $this->assertPrice($unitPriceKobo);

        $offer = $this->create(
            offerable: $request,
            initiator: $seller->user,
            responder: $request->buyer,
            seller: $seller,
            quantity: $quantity,
            unitPriceKobo: $unitPriceKobo,
            message: $message,
            deliveryDays: $deliveryDays,
        );

        $this->notifier->offerReceived($offer);

        return $offer;
    }

    /**
     * Answer an offer with a different one.
     *
     * The old offer is marked `countered`, not rejected: it was superseded,
     * and the new row points back at it so the chain stays readable.
     */
    public function counter(
        Offer $offer,
        User $actor,
        int $quantity,
        int $unitPriceKobo,
        ?string $message = null,
        ?int $deliveryDays = null,
    ): Offer {
        $this->assertAnswerable($offer, $actor);
        $this->assertPrice($unitPriceKobo);

        if ($quantity < 1) {
            throw new RuntimeException(__('A counter has to be for at least one.'));
        }

        return DB::transaction(function () use ($offer, $actor, $quantity, $unitPriceKobo, $message, $deliveryDays): Offer {
            $offer->forceFill([
                'status' => OfferStatus::Countered,
                'responded_at' => now(),
            ])->save();

            $counter = $this->create(
                offerable: $offer->offerable,
                // The roles swap: whoever answers is now the one proposing.
                initiator: $actor,
                responder: $actor->is($offer->initiator) ? $offer->responder : $offer->initiator,
                seller: $offer->seller,
                quantity: $quantity,
                unitPriceKobo: $unitPriceKobo,
                message: $message,
                deliveryDays: $deliveryDays ?? $offer->delivery_days,
                parent: $offer,
            );

            $this->notifier->offerCountered($counter);

            return $counter;
        });
    }

    // -----------------------------------------------------------------------
    // Answering
    // -----------------------------------------------------------------------

    /**
     * Take the offer.
     *
     * On a listing this mints a private checkout link at the agreed price and
     * holds the stock for its lifetime. On a wanted ad it does the same, and
     * closes the request to further offers.
     */
    public function accept(Offer $offer, User $actor): Offer
    {
        $this->assertAnswerable($offer, $actor);

        return DB::transaction(function () use ($offer): Offer {
            $offer->forceFill([
                'status' => OfferStatus::Accepted,
                'responded_at' => now(),
            ])->save();

            $purchase = $this->mintPurchase($offer);

            // Everything else still waiting on the same thing is now moot.
            $this->closeSiblings($offer);

            if ($offer->offerable instanceof BuyerRequest) {
                $offer->offerable->forceFill([
                    'status' => BuyerRequestStatus::OfferAccepted,
                ])->save();
            }

            $this->notifier->offerAccepted($offer->refresh(), $purchase);

            return $offer;
        });
    }

    public function reject(Offer $offer, User $actor, ?string $reason = null): Offer
    {
        $this->assertAnswerable($offer, $actor);

        $offer->forceFill([
            'status' => OfferStatus::Rejected,
            'responded_at' => now(),
            'message' => $offer->message,
        ])->save();

        $this->notifier->offerRejected($offer, $reason);

        return $offer;
    }

    /**
     * Whoever made an offer takes it back.
     */
    public function withdraw(Offer $offer, User $actor): Offer
    {
        if (! $actor->is($offer->initiator)) {
            throw new RuntimeException(__('Only whoever made an offer can take it back.'));
        }

        if (! $offer->isOpen()) {
            throw new RuntimeException(__('This offer can no longer be taken back.'));
        }

        $offer->forceFill([
            'status' => OfferStatus::Withdrawn,
            'responded_at' => now(),
        ])->save();

        return $offer;
    }

    /**
     * Mark everything that ran out of time.
     *
     * Returns how many, for the scheduled command's output.
     */
    public function expireDue(): int
    {
        $expired = 0;

        Offer::query()->dueToExpire()->chunkById(200, function ($offers) use (&$expired): void {
            foreach ($offers as $offer) {
                $offer->forceFill(['status' => OfferStatus::Expired])->save();
                $expired++;
            }
        });

        return $expired;
    }

    // -----------------------------------------------------------------------

    /**
     * Whether this seller is allowed to answer this request at all.
     *
     * Approved, and trading in the category asked about — a wanted ad for
     * day-old chicks answered by somebody who sells tractors is noise the
     * buyer has to wade through.
     */
    public function assertSellerMayAnswer(BuyerRequest $request, ?SellerProfile $seller): void
    {
        if ($seller === null || ! $seller->canSell()) {
            throw new RuntimeException(__('Only approved sellers can answer requests.'));
        }

        if (! $this->sellerCoversCategory($seller, $request)) {
            throw new RuntimeException(__('This request is in a category you do not trade in.'));
        }
    }

    /**
     * Whether the seller's categories cover the one asked about.
     *
     * A seller registered for "Poultry" answers anything beneath it, because
     * nobody registers against every leaf of a tree this deep.
     */
    public function sellerCoversCategory(SellerProfile $seller, BuyerRequest $request): bool
    {
        $category = $request->category;

        if ($category === null) {
            return false;
        }

        $sellerCategoryIds = $seller->categories()->pluck('categories.id');

        if ($sellerCategoryIds->contains($category->getKey())) {
            return true;
        }

        // Anything the seller registered for that this category sits under.
        return $category->ancestors()
            ->pluck('id')
            ->intersect($sellerCategoryIds)
            ->isNotEmpty();
    }

    public function openOfferBetween(Model $offerable, User $user): ?Offer
    {
        return $offerable->morphMany(Offer::class, 'offerable')->getQuery()
            ->where('offerable_type', $offerable->getMorphClass())
            ->where('offerable_id', $offerable->getKey())
            ->where('initiator_id', $user->getKey())
            ->open()
            ->first();
    }

    public function openOfferFromSeller(BuyerRequest $request, SellerProfile $seller): ?Offer
    {
        return $request->offers()->where('seller_id', $seller->getKey())->open()->first();
    }

    // -----------------------------------------------------------------------

    private function create(
        Model $offerable,
        User $initiator,
        User $responder,
        ?SellerProfile $seller,
        int $quantity,
        int $unitPriceKobo,
        ?string $message,
        ?int $deliveryDays = null,
        ?Offer $parent = null,
    ): Offer {
        $offer = new Offer;

        $offer->forceFill([
            'offerable_type' => $offerable->getMorphClass(),
            'offerable_id' => $offerable->getKey(),
            'initiator_id' => $initiator->getKey(),
            'responder_id' => $responder->getKey(),
            'seller_id' => $seller?->getKey(),
            'quantity' => $quantity,
            'unit_price_kobo' => $unitPriceKobo,
            'message' => $message === null ? null : trim($message),
            'delivery_days' => $deliveryDays,
            'status' => OfferStatus::Pending,
            'parent_offer_id' => $parent?->getKey(),
            'expires_at' => $this->expiryFor($offerable),
        ])->save();

        return $offer->refresh();
    }

    /**
     * When an offer lapses.
     *
     * Never after the thing it is about closes: an offer standing on a wanted
     * ad that has already expired is an offer nobody can accept.
     */
    private function expiryFor(Model $offerable): Carbon
    {
        $window = now()->addHours($this->windowHours());

        if ($offerable instanceof BuyerRequest && $offerable->expires_at !== null) {
            return $window->min($offerable->expires_at);
        }

        return $window;
    }

    /**
     * Turn an accepted offer into the right to buy at that price.
     */
    private function mintPurchase(Offer $offer): NegotiatedPurchase
    {
        $offerable = $offer->offerable;

        $product = $offerable instanceof Product ? $offerable : null;
        $request = $offerable instanceof BuyerRequest ? $offerable : null;

        $purchase = new NegotiatedPurchase;
        $purchase->forceFill([
            'offer_id' => $offer->getKey(),
            'buyer_id' => $request !== null ? $request->user_id : $offer->initiator_id,
            'seller_id' => $offer->seller_id,
            'product_id' => $product?->getKey(),
            'buyer_request_id' => $request?->getKey(),
            'quantity' => $offer->quantity,
            'unit_price_kobo' => $offer->unit_price_kobo,
            'expires_at' => now()->addHours($this->checkoutWindowHours()),
        ])->save();

        if ($product !== null) {
            $this->reserveStock($purchase, $product);
        }

        return $purchase->refresh();
    }

    /**
     * Hold the goods for the buyer who won them.
     *
     * Only as much as is actually there: a seller who has sold most of the
     * batch since the offer was made cannot promise what is gone, and the
     * buyer is better told at checkout than sold air.
     */
    private function reserveStock(NegotiatedPurchase $purchase, Product $product): void
    {
        $reservable = min($purchase->quantity, $product->availableStock());

        if ($reservable < 1) {
            return;
        }

        $product->increment('reserved_quantity', $reservable);
        $purchase->forceFill(['reserved_quantity' => $reservable])->save();
    }

    /**
     * Everything else waiting on the same thing loses.
     */
    private function closeSiblings(Offer $accepted): void
    {
        $accepted->offerable
            ->morphMany(Offer::class, 'offerable')->getQuery()
            ->where('offerable_type', $accepted->offerable_type)
            ->where('offerable_id', $accepted->offerable_id)
            ->whereKeyNot($accepted->getKey())
            ->open()
            ->get()
            ->each(function (Offer $other): void {
                $other->forceFill([
                    'status' => OfferStatus::Rejected,
                    'responded_at' => now(),
                ])->save();

                $this->notifier->offerRejected($other, __('The buyer accepted another offer.'));
            });
    }

    private function assertAnswerable(Offer $offer, User $actor): void
    {
        if (! $actor->is($offer->responder)) {
            throw new RuntimeException(__('This offer is not yours to answer.'));
        }

        if ($offer->status !== OfferStatus::Pending) {
            throw new RuntimeException(__('This offer has already been answered.'));
        }

        if ($offer->hasExpired()) {
            throw new RuntimeException(__('This offer ran out of time.'));
        }
    }

    private function assertQuantity(int $quantity, int $minimum, int $maximum): void
    {
        if ($quantity < max(1, $minimum)) {
            throw new RuntimeException(trans_choice(
                'The smallest order is :count.|The smallest order is :count.',
                max(1, $minimum),
                ['count' => max(1, $minimum)],
            ));
        }

        if ($maximum > 0 && $quantity > $maximum) {
            throw new RuntimeException(__('Only :count are available.', ['count' => $maximum]));
        }
    }

    private function assertPrice(int $unitPriceKobo): void
    {
        if ($unitPriceKobo < 1) {
            throw new RuntimeException(__('Name a price.'));
        }

        if ($unitPriceKobo > 1_000_000_000_00) {
            throw new RuntimeException(__('That price is not realistic.'));
        }
    }
}

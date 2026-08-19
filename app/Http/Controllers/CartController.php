<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Cart\CartService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class CartController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function index(): Response
    {
        return Inertia::render('Cart/Index', [
            'groups' => $this->groups(),
            'subtotal' => Money::fromKobo($this->cart->subtotalKobo()),
            'subtotal_kobo' => $this->cart->subtotalKobo(),
            'count' => $this->cart->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        $product = Product::query()->with('priceTiers')->findOrFail($validated['product_id']);
        $variant = isset($validated['variant_id'])
            ? ProductVariant::query()->find($validated['variant_id'])
            : null;

        try {
            $this->cart->add($product, $validated['quantity'], $variant);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Added to your cart.'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);

        $this->cart->updateQuantity(
            $validated['product_id'],
            $validated['variant_id'] ?? null,
            $validated['quantity'],
        );

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
        ]);

        $this->cart->remove($validated['product_id'], $validated['variant_id'] ?? null);

        return back()->with('success', __('Removed from your cart.'));
    }

    /**
     * The cart grouped by seller, which is how it will be split into orders and
     * therefore how the buyer should see it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function groups(): array
    {
        return $this->cart->groupedBySeller()
            ->map(fn (array $group): array => [
                'seller' => [
                    'id' => $group['seller']->id,
                    'name' => $group['seller']->business_name,
                    'slug' => $group['seller']->slug,
                    'location' => $group['seller']->location(),
                ],
                'subtotal' => Money::fromKobo($group['subtotal_kobo']),
                'lines' => $group['lines']->map(fn ($line): array => [
                    'product_id' => $line->productId,
                    'variant_id' => $line->variantId,
                    'name' => $line->product->name,
                    'variant_name' => $line->variant?->name,
                    'slug' => $line->product->slug,
                    'image' => $line->product->images->first()?->url(),
                    'unit' => $line->product->unit_of_measure->shortLabel(),
                    'quantity' => $line->quantity,
                    'min_order_quantity' => $line->product->min_order_quantity,
                    'stock_quantity' => $line->variant?->stock_quantity ?? $line->product->stock_quantity,
                    'unit_price' => Money::fromKobo($line->unitPriceKobo),
                    'line_total' => Money::fromKobo($line->lineTotalKobo()),
                    'is_live_animal' => $line->product->is_live_animal,
                    'is_perishable' => $line->product->is_perishable,
                    // Surfaced so the buyer sees a moved price in the cart, not
                    // for the first time on the payment page.
                    'price_changed' => $line->priceHasChanged(),
                    'current_unit_price' => $line->priceHasChanged()
                        ? Money::fromKobo((int) $line->currentUnitPriceKobo())
                        : null,
                ])->all(),
            ])
            ->all();
    }
}

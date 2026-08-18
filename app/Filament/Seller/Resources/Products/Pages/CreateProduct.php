<?php

namespace App\Filament\Seller\Resources\Products\Pages;

use App\Enums\ProductStatus;
use App\Filament\Seller\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Photograph paths as uploaded, held between form state and the
     * product_images rows written after the product exists.
     *
     * @var array<int, string>
     */
    private array $uploadedImages = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->uploadedImages = array_values((array) ($data['images'] ?? []));
        unset($data['images']);

        // A seller must not be able to file a listing under somebody else, so
        // seller_id is dropped here and set from the session in
        // handleRecordCreation() below — it is deliberately not mass-assignable.
        unset($data['seller_id']);

        $data['price_kobo'] = Money::toKobo($data['price_naira'] ?? 0);
        $data['compare_at_price_kobo'] = filled($data['compare_at_price_naira'] ?? null)
            ? Money::toKobo($data['compare_at_price_naira'])
            : null;

        unset($data['price_naira'], $data['compare_at_price_naira']);

        // Everything starts as a draft — the model's own default. Submitting is
        // a separate, deliberate act; see the action on the edit page.
        unset($data['status']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $seller = ProductResource::currentSeller();

        abort_if($seller === null, 403, __('Only an approved seller can create a listing.'));

        $product = new Product($data);
        $product->seller_id = $seller->getKey();
        $product->status = ProductStatus::Draft;
        $product->save();

        return $product;
    }

    protected function afterCreate(): void
    {
        $this->syncImages($this->record);
    }

    private function syncImages(Product $product): void
    {
        foreach ($this->uploadedImages as $index => $path) {
            ProductImage::query()->create([
                'product_id' => $product->getKey(),
                'path' => $path,
                'sort_order' => $index,
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title(__('Saved as a draft'))
            ->body(__('Send it for review when you are ready.'))
            ->success();
    }
}

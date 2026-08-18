<?php

namespace App\Filament\Seller\Resources\Products\Pages;

use App\Enums\ProductStatus;
use App\Filament\Seller\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Catalogue\ProductPublisher;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * @var array<int, string>
     */
    private array $uploadedImages = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['images'] = $this->record->images->pluck('path')->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->uploadedImages = array_values((array) ($data['images'] ?? []));
        unset($data['images']);

        $data['price_kobo'] = Money::toKobo($data['price_naira'] ?? 0);
        $data['compare_at_price_kobo'] = filled($data['compare_at_price_naira'] ?? null)
            ? Money::toKobo($data['compare_at_price_naira'])
            : null;

        unset($data['price_naira'], $data['compare_at_price_naira']);

        // A seller may not move their own listing between statuses by editing;
        // that is what the submit action and the admin review are for.
        unset($data['status'], $data['seller_id']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->syncImages($this->record);
    }

    /**
     * Reconcile the uploaded set with what is stored, preserving the order the
     * seller dragged them into.
     */
    private function syncImages(Product $product): void
    {
        $existing = $product->images()->get()->keyBy('path');
        $keep = collect($this->uploadedImages);

        // Anything the seller removed goes from both the table and the disk.
        $existing
            ->reject(fn (ProductImage $image): bool => $keep->contains($image->path))
            ->each(function (ProductImage $image): void {
                Storage::disk(config('filesystems.default'))->delete($image->path);
                $image->delete();
            });

        $keep->each(function (string $path, int $index) use ($product, $existing): void {
            $image = $existing->get($path);

            if ($image === null) {
                ProductImage::query()->create([
                    'product_id' => $product->getKey(),
                    'path' => $path,
                    'sort_order' => $index,
                ]);

                return;
            }

            $image->update(['sort_order' => $index]);
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submitForReview')
                ->label(fn (Product $record): string => $record->seller->skipsProductReview()
                    ? __('Publish')
                    : __('Send for review'))
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading(fn (Product $record): string => $record->seller->skipsProductReview()
                    ? __('Publish this listing?')
                    : __('Send this listing for review?'))
                ->modalDescription(function (Product $record): string {
                    $remaining = app(ProductPublisher::class)->listingsUntilAutoApproval($record->seller);

                    if ($record->seller->skipsProductReview()) {
                        return __('It goes into the catalogue straight away.');
                    }

                    return trans_choice(
                        'Our team checks it first. After :count more approved listing your listings will go live straight away.|Our team checks it first. After :count more approved listings your listings will go live straight away.',
                        max(1, (int) $remaining),
                        ['count' => max(1, (int) $remaining)],
                    );
                })
                ->visible(fn (Product $record): bool => in_array(
                    $record->status,
                    [ProductStatus::Draft, ProductStatus::Rejected],
                    true,
                ))
                ->action(function (Product $record): void {
                    $status = app(ProductPublisher::class)->submit($record);

                    Notification::make()
                        ->title($status === ProductStatus::PendingReview
                            ? __('Sent for review')
                            : __('Your listing is live'))
                        ->success()
                        ->send();
                }),

            Action::make('backToDraft')
                ->label(__('Take offline'))
                ->icon('heroicon-o-eye-slash')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('Buyers will no longer see this listing. You can put it back any time.'))
                ->visible(fn (Product $record): bool => $record->status->isPubliclyVisible())
                ->action(function (Product $record): void {
                    $record->forceFill([
                        'status' => ProductStatus::Draft,
                        'published_at' => null,
                    ])->save();

                    Notification::make()->title(__('Listing taken offline'))->success()->send();
                }),

            DeleteAction::make(),
        ];
    }
}

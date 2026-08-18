<?php

namespace App\Filament\Admin\Resources\Products\Pages;

use App\Filament\Admin\Actions\ProductReviewActions;
use App\Filament\Admin\Resources\Products\ProductResource;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return ProductReviewActions::all();
    }
}

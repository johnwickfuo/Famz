<?php

namespace App\Filament\Mentor\Resources\Reviews\Pages;

use App\Filament\Mentor\Resources\Reviews\ReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListReviews extends ListRecords
{
    protected static string $resource = ReviewResource::class;
}

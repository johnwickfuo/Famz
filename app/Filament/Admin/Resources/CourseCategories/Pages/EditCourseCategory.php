<?php

namespace App\Filament\Admin\Resources\CourseCategories\Pages;

use App\Filament\Admin\Resources\CourseCategories\CourseCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCourseCategory extends EditRecord
{
    protected static string $resource = CourseCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

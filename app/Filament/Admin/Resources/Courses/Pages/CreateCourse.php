<?php

namespace App\Filament\Admin\Resources\Courses\Pages;

use App\Filament\Admin\Resources\Courses\CourseResource;
use App\Filament\Admin\Resources\Courses\Support\CoursePricing;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateCourse extends CreateRecord
{
    protected static string $resource = CourseResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return CoursePricing::toKobo($data);
    }

    /**
     * Straight to editing: the modules and lessons are the actual work, and
     * they live on the edit page because they need a course to hang from.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title(__('Saved as a draft'))
            ->body(__('Add the modules and lessons, then publish it from the course list.'))
            ->success();
    }
}

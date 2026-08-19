<?php

namespace App\Filament\Admin\Resources\Courses\Pages;

use App\Enums\CourseStatus;
use App\Filament\Admin\Resources\Courses\CourseResource;
use App\Filament\Admin\Resources\Courses\Support\CoursePricing;
use App\Models\Course;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCourse extends EditRecord
{
    protected static string $resource = CourseResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return CoursePricing::toKobo($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label(__('View in the academy'))
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (Course $record): string => route('academy.course', $record->slug))
                ->openUrlInNewTab(),

            Action::make('publish')
                ->label(__('Publish'))
                ->icon('heroicon-o-check-badge')
                ->requiresConfirmation()
                ->visible(fn (Course $record): bool => $record->status !== CourseStatus::Published)
                ->action(function (Course $record): void {
                    if ($record->lessons()->count() < 1) {
                        Notification::make()
                            ->title(__('This course has no lessons'))
                            ->body(__('Add at least one lesson before publishing.'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $record->publish();

                    Notification::make()->title(__('Published'))->success()->send();
                }),

            DeleteAction::make(),
        ];
    }
}

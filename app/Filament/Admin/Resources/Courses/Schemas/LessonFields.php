<?php

namespace App\Filament\Admin\Resources\Courses\Schemas;

use App\Enums\LessonType;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The fields of one lesson.
 *
 * The upload is the part worth reading. It writes to the private
 * `course-content` disk, which has no `url` key at all, so anything that tried
 * to turn a lesson file into a link would throw rather than quietly succeed.
 * That includes Filament itself: its own preview would call Storage::url(), so
 * previewing, opening and downloading are all switched off and the file's card
 * is built by hand from the name and size instead.
 */
class LessonFields
{
    public const DISK = 'course-content';

    /**
     * @return array<int, mixed>
     */
    public static function make(): array
    {
        return [
            TextInput::make('title')
                ->label(__('Lesson title'))
                ->required()
                ->maxLength(160)
                ->columnSpan(2),

            Select::make('type')
                ->label(__('Kind'))
                ->options(LessonType::options())
                ->default(LessonType::Text->value)
                ->required()
                ->live(),

            TextInput::make('duration_seconds')
                ->label(__('Length in seconds'))
                ->numeric()
                ->minValue(1)
                ->helperText(__('Shown on the curriculum.')),

            RichEditor::make('content')
                ->label(__('The reading'))
                ->visible(fn (Get $get): bool => $get('type') === LessonType::Text->value)
                ->required(fn (Get $get): bool => $get('type') === LessonType::Text->value)
                ->columnSpanFull(),

            FileUpload::make('file_path')
                ->label(fn (Get $get): string => $get('type') === LessonType::Video->value
                    ? __('Video file')
                    : __('Handout (PDF)'))
                ->visible(fn (Get $get): bool => in_array($get('type'), [
                    LessonType::Pdf->value,
                    LessonType::Video->value,
                ], true))
                ->disk(self::DISK)
                ->directory('lessons')
                ->visibility('private')
                ->storeFileNamesIn('file_name')
                ->acceptedFileTypes(fn (Get $get): array => $get('type') === LessonType::Video->value
                    ? ['video/mp4', 'video/webm', 'video/quicktime']
                    : ['application/pdf'])
                ->maxSize(fn (Get $get): int => $get('type') === LessonType::Video->value
                    ? 512 * 1024
                    : 32 * 1024)
                // All three of these would ask the disk for a URL it has no way
                // to produce — and must never produce.
                ->previewable(false)
                ->openable(false)
                ->downloadable(false)
                ->getUploadedFileUsing(fn (FileUpload $component, string $file, string|array|null $storedFileNames): array => [
                    'name' => is_string($storedFileNames) ? $storedFileNames : basename($file),
                    'size' => rescue(fn (): int => $component->getDisk()->size($file), 0, report: false),
                    'type' => null,
                    // Deliberately absent. There is no URL to a lesson file
                    // outside a signed, enrolment-checked link.
                    'url' => null,
                ])
                ->helperText(__('Stored privately. Students read it in the player; it is never offered as a download.'))
                ->columnSpanFull(),

            Toggle::make('is_preview')
                ->label(__('Free preview'))
                ->helperText(__('Anybody can play this one without buying the course.')),

            TextInput::make('sort_order')
                ->label(__('Order'))
                ->numeric()
                ->default(0),
        ];
    }
}

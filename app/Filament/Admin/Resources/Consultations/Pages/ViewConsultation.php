<?php

namespace App\Filament\Admin\Resources\Consultations\Pages;

use App\Filament\Admin\Resources\Consultations\ConsultationResource;
use App\Models\Consultation;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewConsultation extends ViewRecord
{
    protected static string $resource = ConsultationResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The promise'))
                ->columns(3)
                ->schema([
                    TextEntry::make('tier')
                        ->label(__('Service'))
                        ->badge()
                        ->state(fn (Consultation $record): string => $record->tier->label())
                        ->color(fn (Consultation $record): string => $record->tier->isUrgent() ? 'danger' : 'gray'),

                    TextEntry::make('response_due_at')
                        // No year: this is a deadline measured in hours, and the
                        // longer string is what pushes the badge past the edge
                        // of its column.
                        ->label(__('Response due'))
                        ->dateTime('j M, H:i')
                        ->badge()
                        ->color(fn (Consultation $record): string => match (true) {
                            $record->first_responded_at !== null => 'success',
                            $record->isOverdue() => 'danger',
                            default => 'gray',
                        }),

                    TextEntry::make('first_responded_at')
                        ->label(__('First answered'))
                        ->dateTime('j M Y, H:i')
                        ->placeholder(__('Not yet — this is the one to act on'))
                        // How long it actually took, which is the number worth
                        // knowing when somebody asks how the service is doing.
                        ->helperText(fn (Consultation $record): ?string => $record->responseTaken()),
                ]),

            Section::make(__('Who to ring'))
                ->columns(3)
                ->schema([
                    TextEntry::make('full_name')->label(__('Name')),
                    TextEntry::make('phone')->label(__('Phone'))->copyable(),
                    TextEntry::make('email')->label(__('Email'))->copyable(),
                    TextEntry::make('place')
                        ->label(__('Where'))
                        ->state(fn (Consultation $record): string => collect([$record->lga, $record->state])
                            ->filter()->implode(', ') ?: '—'),
                    TextEntry::make('user_id')
                        ->label(__('Account'))
                        // Guests are normal here, so it is stated rather than
                        // shown as a missing relation.
                        ->state(fn (Consultation $record): string => $record->user?->email
                            ?? __('Booked as a guest')),
                    TextEntry::make('created_at')->label(__('Booked'))->dateTime('j M Y, H:i'),
                ]),

            Section::make(__('What they said'))
                ->schema([
                    TextEntry::make('category')->label(__('About'))->placeholder('—'),
                    TextEntry::make('situation')
                        ->label(__('The problem'))
                        ->placeholder(__('Nothing written — everything will come from the call'))
                        ->columnSpanFull(),
                    TextEntry::make('farm')
                        ->label(__('The farm'))
                        ->state(fn (Consultation $record): string => collect([
                            $record->farm_type,
                            $record->animal_type,
                            $record->flock_size ? number_format($record->flock_size).' '.__('head') : null,
                        ])->filter()->implode(' · ') ?: '—'),
                    // Hidden rather than empty: a "Photographs" heading with
                    // nothing under it reads as a picture that failed to load.
                    ImageEntry::make('attachments')
                        ->label(__('Photographs'))
                        ->disk('public')
                        ->visible(fn (Consultation $record): bool => filled($record->attachments))
                        ->columnSpanFull(),
                ]),

            Section::make(__('Money'))
                ->columns(3)
                ->schema([
                    TextEntry::make('quoted_amount_kobo')
                        ->label(__('Quoted'))
                        ->state(fn (Consultation $record): string => $record->quotedAmount() ?? __('Not priced yet')),
                    TextEntry::make('quoter.name')->label(__('Quoted by'))->placeholder('—'),
                    TextEntry::make('paid_at')->label(__('Paid'))->dateTime('j M Y, H:i')->placeholder(__('Not paid')),
                    TextEntry::make('quote_note')->label(__('What it covers'))->placeholder('—')->columnSpanFull(),
                    TextEntry::make('order_reference')->label(__('Order'))->placeholder('—')->copyable(),
                ]),

            Section::make(__('Our notes'))
                ->collapsed()
                ->schema([
                    TextEntry::make('admin_notes')
                        ->label(__('Notes'))
                        // Appended with a name and a time on every action, so
                        // the next person to pick this up gets the history.
                        ->placeholder(__('Nothing recorded yet'))
                        ->columnSpanFull(),
                ]),
        ]);
    }
}

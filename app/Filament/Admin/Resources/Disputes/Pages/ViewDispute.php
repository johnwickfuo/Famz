<?php

namespace App\Filament\Admin\Resources\Disputes\Pages;

use App\Filament\Admin\Actions\DisputeActions;
use App\Filament\Admin\Resources\Disputes\DisputeResource;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Services\Disputes\DisputeService;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One dispute, with everything an arbitrator needs on one screen: the
 * complaint, the photographs, the whole thread, and where the money currently
 * sits.
 */
class ViewDispute extends ViewRecord
{
    protected static string $resource = DisputeResource::class;

    public function getTitle(): string|Htmlable
    {
        // "View Dispute" tells an arbitrator nothing. The order reference is
        // what they have in front of them when somebody rings up.
        return $this->getRecord()->subOrder->reference;
    }

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        return __(':reason — :amount at stake, raised :when', [
            'reason' => $record->reason->label(),
            'amount' => Money::fromKobo($record->amountAtStakeKobo()),
            'when' => $record->created_at->diffForHumans(),
        ]);
    }

    /**
     * Two buttons and a menu, not seven buttons.
     *
     * Laid out flat, the four resolutions ran off the right-hand edge at
     * 1280px — and the one that pushed furthest off was the full refund, which
     * is the one an arbitrator most needs to be able to reach.
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                DisputeActions::resolveForSeller(),
                DisputeActions::resolvePartially(),
                DisputeActions::resolveForBuyer(),
                DisputeActions::closeWithoutDecision(),
            ])
                ->label(__('Settle this'))
                ->icon(Heroicon::OutlinedScale)
                ->button()
                ->color('primary')
                // The page's own record, not an injected one: a group is not
                // record-bound the way the actions inside it are, so the
                // closure would be handed null.
                ->visible(fn (): bool => $this->getRecord()->isLive()),

            DisputeActions::takeUp(),
            DisputeActions::reply(),
            $this->internalNoteAction(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('dispute')->columnSpanFull()->tabs([
                Tab::make(__('The complaint'))->schema([
                    Section::make()->columns(2)->schema([
                        TextEntry::make('reason')
                            ->label(__('Complaint'))
                            ->formatStateUsing(fn ($state): string => $state->label()),

                        TextEntry::make('status')
                            ->label(__('Status'))
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state->label()),

                        TextEntry::make('description')
                            ->label(__('In the buyer\'s words'))
                            ->columnSpanFull()
                            ->prose(),

                        TextEntry::make('raiser.name')->label(__('Buyer')),
                        TextEntry::make('subOrder.seller.business_name')->label(__('Seller')),

                        TextEntry::make('created_at')
                            ->label(__('Raised'))
                            ->dateTime('j M Y, H:i'),

                        TextEntry::make('subOrder.delivered_at')
                            ->label(__('Delivered'))
                            ->dateTime('j M Y, H:i')
                            ->placeholder(__('Never marked delivered')),
                    ]),

                    Section::make(__('Photographs'))
                        ->visible(fn (Dispute $record): bool => filled($record->evidence_images))
                        ->schema([
                            ImageEntry::make('evidence_images')
                                ->hiddenLabel()
                                ->disk('public')
                                ->height(160)
                                ->square(false),
                        ]),
                ]),

                Tab::make(__('The money'))->schema([
                    Section::make()->columns(3)->schema([
                        TextEntry::make('at_stake')
                            ->label(__('The buyer paid'))
                            ->state(fn (Dispute $record): string => Money::fromKobo($record->amountAtStakeKobo()))
                            ->weight('bold'),

                        TextEntry::make('seller_holding')
                            ->label(__('Held for the seller'))
                            ->state(fn (Dispute $record): string => Money::fromKobo(
                                app(WalletService::class)->heldBalance($record->subOrder->seller->user_id)
                            )),

                        TextEntry::make('commission')
                            ->label(__('Commission on this order'))
                            ->state(fn (Dispute $record): string => Money::fromKobo(
                                $record->subOrder->commission_amount_kobo
                            )),

                        TextEntry::make('refund_amount_kobo')
                            ->label(__('Refunded'))
                            ->formatStateUsing(fn (int $state): string => Money::fromKobo($state)),

                        TextEntry::make('resolution_note')
                            ->label(__('The decision'))
                            ->columnSpanFull()
                            ->prose()
                            ->placeholder(__('Not decided yet')),
                    ]),
                ]),

                Tab::make(__('The conversation'))->schema([
                    TextEntry::make('thread')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->state(fn (Dispute $record): string => $record->messages
                            ->map(fn (DisputeMessage $message): string => sprintf(
                                '%s%s — %s%s%s',
                                $message->author?->displayName() ?? __('Somebody'),
                                $message->is_internal ? ' '.__('(internal note)') : '',
                                $message->created_at->format('j M, H:i'),
                                PHP_EOL,
                                $message->body,
                            ))
                            ->implode(PHP_EOL.PHP_EOL.'— — —'.PHP_EOL.PHP_EOL))
                        ->prose()
                        ->placeholder(__('Nothing said yet')),
                ]),
            ]),
        ]);
    }

    /**
     * A note for the platform's own file, which neither party sees.
     */
    private function internalNoteAction(): Action
    {
        return Action::make('internalNote')
            ->label(__('Note to file'))
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->schema([
                Textarea::make('body')
                    ->label(__('Note'))
                    ->rows(4)
                    ->required()
                    ->maxLength(2000),

                Toggle::make('internal')
                    ->label(__('Keep this to ourselves'))
                    ->default(true)
                    ->helperText(__('Off means the buyer and the seller both read it.')),
            ])
            ->action(function (array $data): void {
                app(DisputeService::class)->comment(
                    $this->getRecord(),
                    auth()->user(),
                    $data['body'],
                    internal: (bool) ($data['internal'] ?? true),
                );

                Notification::make()->title(__('Saved'))->success()->send();
            });
    }
}

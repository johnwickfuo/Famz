<?php

namespace App\Filament\Admin\Pages;

use App\Mail\MailTemplateRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Lists every mail the platform can send, and sends a real test copy of any of
 * them, so an administrator can check the branding against an actual inbox
 * rather than a screenshot.
 */
class MailTemplates extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?int $navigationSort = 91;

    public static function getNavigationLabel(): string
    {
        return __('Mail templates');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Mail templates');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Every message below is rendered with the current company name and logo. Send yourself a test to check it.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components(
            collect(app(MailTemplateRegistry::class)->all())
                ->map(fn (array $template) => Section::make($template['name'])
                    ->description($template['description'])
                    ->footerActions([
                        Action::make('sendTest')->arguments(['template' => $template['key']]),
                    ])
                    ->schema([
                        Text::make($template['transactional']
                            ? __('Transactional — no unsubscribe link.')
                            : __('Not transactional — sent with an unsubscribe link.')),
                    ]))
                ->all(),
        );
    }

    /**
     * One action, re-used by every section. Which template it sends comes from
     * the arguments the section passes in.
     */
    public function sendTestAction(): Action
    {
        return Action::make('sendTest')
            ->label(__('Send test email'))
            ->icon('heroicon-o-paper-airplane')
            ->modalHeading(fn (array $arguments): string => __('Send a test of ":name"', [
                'name' => $this->templateName($arguments['template'] ?? ''),
            ]))
            ->modalSubmitActionLabel(__('Send'))
            ->schema([
                TextInput::make('email')
                    ->label(__('Send to'))
                    ->email()
                    ->required()
                    ->default(fn (): ?string => config('platform.test_email_recipient') ?? auth()->user()?->email),
            ])
            ->action(function (array $arguments, array $data): void {
                $key = $arguments['template'] ?? '';
                $mailable = app(MailTemplateRegistry::class)->sample($key, auth()->user());

                if ($mailable === null) {
                    Notification::make()
                        ->title(__('Unknown template'))
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    Mail::to($data['email'])->send($mailable);
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title(__('Could not send the test email'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Test email queued'))
                    ->body(__('":name" is on its way to :email.', [
                        'name' => $this->templateName($key),
                        'email' => $data['email'],
                    ]))
                    ->success()
                    ->send();
            });
    }

    private function templateName(string $key): string
    {
        return app(MailTemplateRegistry::class)->find($key)['name'] ?? $key;
    }
}

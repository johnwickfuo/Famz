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
 * them so an administrator can check the branding against a real inbox rather
 * than a screenshot.
 */
class MailTemplates extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-envelope';

    protected static ?int $navigationSort = 91;

    public static function getNavigationLabel(): string
    {
        return __('Mail templates');
    }

    public function getTitle(): string | Htmlable
    {
        return __('Mail templates');
    }

    public function getSubheading(): string | Htmlable | null
    {
        return __('Every message below is rendered with the current company name and logo. Send yourself a test to check it.');
    }

    public function content(Schema $schema): Schema
    {
        $registry = app(MailTemplateRegistry::class);

        return $schema->components(
            collect($registry->all())
                ->map(fn (array $template) => Section::make($template['name'])
                    ->description($template['description'])
                    ->footerActions([$this->sendTestAction($template['key'], $template['name'])])
                    ->schema([
                        Text::make($template['transactional']
                            ? __('Transactional — no unsubscribe link.')
                            : __('Not transactional — sent with an unsubscribe link.')),
                    ]))
                ->all(),
        );
    }

    private function sendTestAction(string $key, string $name): Action
    {
        return Action::make("send_test_{$key}")
            ->label(__('Send test email'))
            ->icon('heroicon-o-paper-airplane')
            ->modalHeading(__('Send a test of ":name"', ['name' => $name]))
            ->modalSubmitActionLabel(__('Send'))
            ->schema([
                TextInput::make('email')
                    ->label(__('Send to'))
                    ->email()
                    ->required()
                    ->default(fn (): ?string => config('platform.test_email_recipient') ?? auth()->user()?->email),
            ])
            ->action(function (array $data) use ($key, $name): void {
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
                    ->body(__('":name" is on its way to :email.', ['name' => $name, 'email' => $data['email']]))
                    ->success()
                    ->send();
            });
    }
}

<?php

use App\Enums\NotificationCategory;
use App\Enums\RoleName;
use App\Mail\BrandedMailable;
use App\Mail\PlatformAnnouncementMail;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\PlatformNotification;
use App\Services\Notifications\NotificationPreferences;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;

/**
 * What people are told, and what they can switch off.
 *
 * The assertion that matters most here is the one about essential categories.
 * Everything else is a switch working; that one is the platform refusing to let
 * somebody silence a message about their own money.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    Cache::flush();

    $this->user = User::factory()->create();
    $this->preferences = app(NotificationPreferences::class);

    /**
     * A notification in whatever category the test needs.
     */
    $this->notificationIn = function (NotificationCategory $category): PlatformNotification {
        return new class($category) extends PlatformNotification
        {
            public function __construct(private readonly NotificationCategory $category) {}

            public function category(): NotificationCategory
            {
                return $this->category;
            }

            public function mailable(object $notifiable): BrandedMailable
            {
                return new PlatformAnnouncementMail(__('Test'), __('Body'));
            }

            public function toArray(object $notifiable): array
            {
                return ['kind' => 'test', 'title' => 'Test'];
            }
        };
    };
});

it('sends on both channels by default', function (): void {
    $notification = ($this->notificationIn)(NotificationCategory::Orders);

    expect($notification->via($this->user))->toBe(['database', 'mail']);
});

it('leaves announcements out of the inbox until somebody asks for them', function (): void {
    // Somebody who signed up to sell feed did not sign up for a newsletter,
    // and defaulting that to on is how a platform teaches people to filter its
    // mail — including the mail about their money.
    $notification = ($this->notificationIn)(NotificationCategory::Announcements);

    expect($notification->via($this->user))->toBe(['database']);
});

it('honours somebody switching email off', function (): void {
    $this->preferences->save($this->user, [
        NotificationCategory::Orders->value => ['database' => true, 'email' => false],
    ]);

    expect(($this->notificationIn)(NotificationCategory::Orders)->via($this->user))->toBe(['database']);
});

it('honours somebody switching a category off entirely', function (): void {
    $this->preferences->save($this->user, [
        NotificationCategory::Jobs->value => ['database' => false, 'email' => false],
    ]);

    expect(($this->notificationIn)(NotificationCategory::Jobs)->via($this->user))->toBe([]);
});

it('will not let anybody silence email about their own money', function (NotificationCategory $category): void {
    // Written straight to the table, bypassing the service, because that is
    // what a determined person posting the form would achieve.
    NotificationPreference::query()->create([
        'user_id' => $this->user->id,
        'category' => $category->value,
        'database' => false,
        'email' => false,
    ]);

    Cache::flush();

    expect(($this->notificationIn)($category)->via($this->user))->toContain('mail');
})->with([
    'payouts' => NotificationCategory::Payouts,
    'disputes' => NotificationCategory::Disputes,
    'account security' => NotificationCategory::Account,
]);

it('refuses to store an opt-out for an essential category', function (): void {
    $this->preferences->save($this->user, [
        NotificationCategory::Payouts->value => ['database' => true, 'email' => false],
    ]);

    // Loose: the column comes back as 1 on one driver and true on another,
    // and the claim being made is "it was stored as on", not "it was stored as
    // this particular PHP type".
    expect((bool) NotificationPreference::query()
        ->where('user_id', $this->user->id)
        ->where('category', NotificationCategory::Payouts->value)
        ->value('email'))->toBeTrue();
});

it('sends mail and nothing else to somebody with no account', function (): void {
    // A guest who booked a consultation has no preferences to consult and no
    // database to store a row against.
    $guest = new class
    {
        public function routeNotificationFor(string $driver): string
        {
            return 'guest@example.test';
        }
    };

    expect(($this->notificationIn)(NotificationCategory::Consultations)->via($guest))->toBe(['mail']);
});

it('stamps the category onto the stored row', function (): void {
    $notification = ($this->notificationIn)(NotificationCategory::Quotations);

    expect($notification->toDatabase($this->user))
        ->toHaveKey('category', NotificationCategory::Quotations->value);
});

it('shows every category on the preferences screen, essential ones marked', function (): void {
    $summary = $this->preferences->summaryFor($this->user);

    expect($summary)->toHaveCount(count(NotificationCategory::cases()));

    $payouts = collect($summary)->firstWhere('category', NotificationCategory::Payouts->value);
    $jobs = collect($summary)->firstWhere('category', NotificationCategory::Jobs->value);

    expect($payouts['essential'])->toBeTrue()
        ->and($jobs['essential'])->toBeFalse();
});

it('saves preferences from the account screen', function (): void {
    actingAs($this->user)
        ->put(route('account.notifications.update'), [
            'preferences' => [
                NotificationCategory::Academy->value => ['database' => true, 'email' => false],
            ],
        ])
        ->assertRedirect();

    expect($this->preferences->for($this->user->fresh(), NotificationCategory::Academy)['email'])
        ->toBeFalse();
});

it('ignores a posted key that is not a category', function (): void {
    actingAs($this->user)
        ->put(route('account.notifications.update'), [
            'preferences' => [
                'not-a-category' => ['database' => false, 'email' => false],
            ],
        ])
        ->assertRedirect();

    expect(NotificationPreference::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('caches a lookup and busts it on save', function (): void {
    $this->preferences->all($this->user);

    $this->preferences->save($this->user, [
        NotificationCategory::Orders->value => ['database' => true, 'email' => false],
    ]);

    // Read straight after the write must reflect it, not the cached blank.
    expect($this->preferences->for($this->user, NotificationCategory::Orders)['email'])->toBeFalse();
});

it('queues everything it sends', function (): void {
    /*
     * Asserted on the base class rather than through Notification::fake,
     * which matches on the concrete class name — an anonymous test double has
     * none to match. What is being claimed is a property of every notification
     * the platform sends, so checking the base contract is both simpler and a
     * stronger statement than faking one send.
     */
    expect(is_subclass_of(PlatformNotification::class, \Illuminate\Contracts\Queue\ShouldQueue::class))
        ->toBeTrue();

    // And every real one inherits it.
    $concrete = collect(\Illuminate\Support\Facades\File::files(app_path('Notifications')))
        ->map(fn ($file): string => 'App\\Notifications\\'.$file->getFilenameWithoutExtension())
        ->filter(fn (string $class): bool => class_exists($class) && is_subclass_of($class, PlatformNotification::class));

    expect($concrete)->not->toBeEmpty();

    foreach ($concrete as $class) {
        expect(is_subclass_of($class, \Illuminate\Contracts\Queue\ShouldQueue::class))->toBeTrue($class);
    }
});

<?php

namespace App\Enums;

/**
 * The kinds of thing the platform tells people about.
 *
 * Every notification declares one of these, and it does two jobs: it is what a
 * user switches off when they no longer want a certain kind of email, and it is
 * how the notification centre groups a list that would otherwise be a hundred
 * undifferentiated rows.
 *
 * The important distinction here is `isEssential`. A farmer may reasonably
 * decide they do not want an email every time a course is published. Nobody may
 * decide, on a Tuesday, that they will not be told a dispute has been raised
 * against them, that money has left the platform towards their bank, or that
 * somebody changed their password. Those arrive regardless, and the preferences
 * screen says so plainly rather than showing a switch that quietly does
 * nothing.
 */
enum NotificationCategory: string
{
    case Orders = 'orders';

    case Payouts = 'payouts';

    case Disputes = 'disputes';

    case Offers = 'offers';

    case Academy = 'academy';

    case Mentorship = 'mentorship';

    case Consultations = 'consultations';

    case Quotations = 'quotations';

    case Jobs = 'jobs';

    case Account = 'account';

    case Announcements = 'announcements';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Orders => __('Orders and delivery'),
            self::Payouts => __('Money and payouts'),
            self::Disputes => __('Disputes'),
            self::Offers => __('Offers and requests'),
            self::Academy => __('Training'),
            self::Mentorship => __('Mentorship'),
            self::Consultations => __('Consultations'),
            self::Quotations => __('Farm setup quotations'),
            self::Jobs => __('Farm jobs'),
            self::Account => __('Account and security'),
            self::Announcements => __('News from the platform'),
        };
    }

    /**
     * Written for the preferences screen, in the second person.
     */
    public function description(): string
    {
        return match ($this) {
            self::Orders => __('An order is placed, paid, sent or delivered.'),
            self::Payouts => __('A withdrawal is requested, approved or paid into your bank.'),
            self::Disputes => __('Somebody opens a dispute, or one is decided.'),
            self::Offers => __('You get an offer, a counter-offer, or a reply to a request you posted.'),
            self::Academy => __('A course you bought is updated, or your certificate is ready.'),
            self::Mentorship => __('A mentorship session is booked, changed or finished.'),
            self::Consultations => __('Your consultation is quoted, started or answered.'),
            self::Quotations => __('Your farm setup proposal is sent, revised or about to expire.'),
            self::Jobs => __('Somebody applies to your listing, or a farm replies to your application.'),
            self::Account => __('Your password, email or sign-in details change.'),
            self::Announcements => __('Occasional news about what is new here.'),
        };
    }

    /**
     * Whether this kind of message arrives whatever the preferences say.
     *
     * Money, disputes and account security. The common thread is that not
     * knowing costs the person something real and irreversible: a payout they
     * cannot trace, a dispute answered against them by default, a stranger in
     * their account. An unread announcement costs nobody anything.
     */
    public function isEssential(): bool
    {
        return match ($this) {
            self::Payouts, self::Disputes, self::Account => true,
            default => false,
        };
    }

    /**
     * Whether email is on for somebody who has never touched the preferences.
     *
     * Everything except the announcements. Somebody who signed up to sell feed
     * did not sign up for a newsletter, and defaulting that to on is how a
     * platform teaches people to filter its mail into the bin — including the
     * mail about their money.
     */
    public function emailByDefault(): bool
    {
        return $this !== self::Announcements;
    }

    /**
     * The in-app list defaults to everything. It costs nothing to store and a
     * row nobody reads does no harm.
     */
    public function databaseByDefault(): bool
    {
        return true;
    }

    /**
     * The order the preferences screen lists them in: money and safety first.
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::Account => 10,
            self::Payouts => 20,
            self::Disputes => 30,
            self::Orders => 40,
            self::Offers => 50,
            self::Consultations => 60,
            self::Quotations => 70,
            self::Mentorship => 80,
            self::Academy => 90,
            self::Jobs => 100,
            self::Announcements => 110,
        };
    }

    /**
     * @return array<int, self>
     */
    public static function sorted(): array
    {
        $cases = self::cases();

        usort($cases, fn (self $a, self $b): int => $a->sortOrder() <=> $b->sortOrder());

        return $cases;
    }
}

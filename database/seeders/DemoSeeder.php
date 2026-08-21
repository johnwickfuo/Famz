<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Reporting\ReconciliationReport;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The whole platform, with something on every screen.
 *
 * One command, so a client can be walked through all eight modules in one
 * session without anybody first having to remember six seeder names and the
 * order they go in — which matters, because the order is not optional: trade
 * needs a catalogue, offers need buyers, and services need accounts to hang
 * from.
 *
 *     php artisan db:seed --class=DemoSeeder
 *
 * Every module is left mid-flight rather than pristine. An order waiting on a
 * seller, one in transit, one disputed and frozen, a payout part-way through,
 * an offer chain waiting on a buyer, a consultation nobody has answered yet, a
 * proposal on the builder and another already with the client. A demonstration
 * where every row reads "new" shows the intake forms and nothing about the
 * work.
 *
 * Not wired into DatabaseSeeder: a production deployment must never grow eight
 * invented sellers and a buyer whose password is "password".
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('Not in production. This creates accounts with known passwords.');

            return;
        }

        $this->ensureAnAdministrator();

        $this->call([
            // Order matters. Trade needs a catalogue to buy from, offers need
            // the buyers trade creates, and the services hang off the same
            // accounts.
            DemoCatalogueSeeder::class,
            DemoAcademySeeder::class,
            DemoMentorSeeder::class,
            DemoTradeSeeder::class,
            DemoOfferSeeder::class,
            DemoLearningSeeder::class,
            DemoServiceSeeder::class,
            DemoJobSeeder::class,
        ]);

        $this->proveTheBooks();
        $this->printTheTour();
    }

    /**
     * A demonstration needs somebody to sign in as.
     *
     * SuperAdminSeeder deliberately creates nothing when SUPER_ADMIN_EMAIL and
     * SUPER_ADMIN_PASSWORD are unset — an install without them is safer than
     * one with a default password. That is right for a deployment and wrong
     * here: half the seeders below need an administrator to approve, quote and
     * send things, and without one they silently skip every one of those steps.
     * The result looked like a working demonstration with no proposals in it.
     */
    private function ensureAnAdministrator(): User
    {
        $existing = User::query()->role(RoleName::Admin->value)->first();

        if ($existing !== null) {
            return $existing;
        }

        $admin = User::query()->firstOrCreate(
            ['email' => 'demo-admin@example.test'],
            [
                'name' => 'Demonstration Administrator',
                'password' => Hash::make('password'),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );

        $admin->assignRole(RoleName::Admin->value);

        $this->command?->warn('No administrator existed, so one was created: demo-admin@example.test / password');

        return $admin;
    }

    /**
     * Reconcile before saying anything is ready.
     *
     * The demonstration data is put in through the real services — the cart,
     * the order builder, the payment processor, the settlement driver — and
     * this is what proves it. Rows written by hand would look right on every
     * screen and be wrong in the ledger, and the client would find out during
     * the part of the tour where the reconciliation report is the headline.
     */
    private function proveTheBooks(): void
    {
        $result = app(ReconciliationReport::class)->run();

        if ($result['clean']) {
            $this->command?->info('The seeded books reconcile.');

            return;
        }

        $this->command?->warn('The seeded books do NOT reconcile — the demonstration data is wrong:');

        foreach ($result['findings'] as $finding) {
            $this->command?->warn('  · '.$finding['message']);
        }
    }

    private function printTheTour(): void
    {
        $rows = [
            ['Administrator', $this->emailFor(RoleName::Admin), '/admin'],
            ['Buyer', 'buyer@example.test', '/dashboard'],
            ['Seller', $this->emailFor(RoleName::Seller), '/seller'],
            ['Mentor', $this->emailFor(RoleName::Mentor), '/mentor'],
            ['Employer', 'odeda-layer-farms@example.test', '/jobs/hiring'],
            ['Worker', 'segun-adeyemi@example.test', '/jobs/my-applications'],
        ];

        $this->command?->newLine();
        $this->command?->table(['Sign in as', 'Email', 'Where to go'], $rows);
        $this->command?->line('  Every demonstration account uses the password <info>password</info>.');
        $this->command?->line('  The administrator uses whatever SUPER_ADMIN_PASSWORD was set to.');
        $this->command?->newLine();

        $this->command?->line('  Worth showing, in this order:');
        $this->command?->line('   1. <info>/</info> — the eight services, then <info>/market</info> and a listing');
        $this->command?->line('   2. Sign in as the buyer: an order in escrow, one shipped, one disputed');
        $this->command?->line('   3. Sign in as the seller: the same orders from the other side, and the earnings screen');
        $this->command?->line('   4. <info>/admin</info> → Money → the reconciliation report, and Settings → Company & branding');
        $this->command?->line('   5. Rename the company in Settings and reload the site — it is live on the next request');
        $this->command?->line('   6. <info>/jobs/workers/{worker}</info> as the buyer: 403. As the employer: the number, and');
        $this->command?->line('      a row in the release log. Three conditions at once, all checked server-side');
        $this->command?->newLine();
    }

    private function emailFor(RoleName $role): string
    {
        return User::query()->role($role->value)->value('email') ?? '(none seeded)';
    }
}

<?php

namespace Database\Seeders;

use App\Enums\QuotationPowerSituation;
use App\Enums\QuotationProjectType;
use App\Enums\QuotationRequestStatus;
use App\Enums\QuotationScope;
use App\Enums\QuotationWaterSource;
use App\Enums\RoleName;
use App\Models\Quotation;
use App\Models\QuotationRequest;
use App\Models\User;
use App\Services\Consultations\ConsultationCheckout;
use App\Services\Consultations\ConsultationService;
use App\Services\Payments\PaymentProcessor;
use App\Services\Quotations\QuotationService;
use App\Services\Quotations\StudyFeeCheckout;
use App\Services\Quotations\StudyFeeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The two services the company sells directly: consultations and farm-setup
 * quotations.
 *
 * Both are a queue somebody works through, so both are seeded across the states
 * of that queue — waiting, contacted, quoted, paid, done. A demonstration where
 * every row says "new" shows the intake form and nothing about the work.
 *
 * The situations below are the ones people actually write in with. A booking
 * that reads "test test test" tells the client nothing about whether the form
 * captures enough to act on.
 */
class DemoServiceSeeder extends Seeder
{
    /**
     * @var array<int, array{tier: string, category: string, situation: string, state: string, lga: string, flock: int|null}>
     */
    private const BOOKINGS = [
        [
            'tier' => 'urgent',
            'category' => 'Disease and mortality',
            'situation' => 'I am losing about forty birds a day since Tuesday. They are three weeks old, drooping, and there is greenish droppings. I have not changed the feed. Vaccination was done at day one and day ten.',
            'state' => 'Ogun',
            'lga' => 'Ifo',
            'flock' => 2000,
        ],
        [
            'tier' => 'standard',
            'category' => 'Feed and nutrition',
            'situation' => 'My layers are at 62 percent production at 32 weeks and I expected better. Feed is bought, not mixed. I want to know whether to change the feed or whether something else is wrong.',
            'state' => 'Oyo',
            'lga' => 'Egbeda',
            'flock' => 3500,
        ],
        [
            'tier' => 'standard',
            'category' => 'Housing and equipment',
            'situation' => 'I am converting a block building into a deep litter house for 1,500 birds. I want to know about ventilation before I start knocking out walls.',
            'state' => 'Kaduna',
            'lga' => 'Chikun',
            'flock' => 1500,
        ],
        [
            'tier' => 'urgent',
            'category' => 'Water and sanitation',
            'situation' => 'The borehole water has gone brackish and the birds are drinking less. Catfish ponds on the same water are fine so far.',
            'state' => 'Delta',
            'lga' => 'Ughelli North',
            'flock' => 800,
        ],
        [
            'tier' => 'standard',
            'category' => 'Starting out',
            'situation' => 'I have a plot in my village and I want to start with broilers. I have never kept birds. I want to know what it costs and whether it is worth it before I spend anything.',
            'state' => 'Enugu',
            'lga' => 'Nsukka',
            'flock' => null,
        ],
    ];

    /**
     * @var array<int, array{type: string, farm: string, capacity: int, unit: string, state: string, lga: string, budget: array{int, int}, notes: string}>
     */
    private const PROJECTS = [
        [
            'type' => 'new_build',
            'farm' => 'Layer farm',
            'capacity' => 5000,
            'unit' => 'birds',
            'state' => 'Ogun',
            'lga' => 'Odeda',
            'budget' => [25_000_000_00, 40_000_000_00],
            'notes' => 'Two acres, family land, no title document yet. Road access is poor in the rains. I want a proposal I can take to the bank.',
        ],
        [
            'type' => 'expansion',
            'farm' => 'Catfish',
            'capacity' => 20000,
            'unit' => 'fingerlings',
            'state' => 'Delta',
            'lga' => 'Ughelli North',
            'budget' => [8_000_000_00, 15_000_000_00],
            'notes' => 'Already running eight earthen ponds. Want to add concrete tanks and a recirculating system for the dry season.',
        ],
        [
            'type' => 'new_build',
            'farm' => 'Mixed livestock',
            'capacity' => 300,
            'unit' => 'goats',
            'state' => 'Kaduna',
            'lga' => 'Giwa',
            'budget' => [12_000_000_00, 20_000_000_00],
            'notes' => 'Grazing land is available but fencing and water are the problem. Nearest borehole is 400 metres away.',
        ],
    ];

    public function run(): void
    {
        Mail::fake();
        Notification::fake();

        $clients = $this->clients();

        if ($clients->isEmpty()) {
            // Warn and stop rather than dividing by zero on the first line.
            // A seeder that crashes takes the rest of the demonstration with
            // it, and the person setting it up finds out at the meeting.
            $this->command?->warn('No demo clients. Run DemoTradeSeeder first.');

            return;
        }

        $admin = User::query()->role(RoleName::Admin->value)->first();

        $this->bookConsultations($admin, $clients);
        $this->requestQuotations($admin, $clients);

        $this->command?->info('Demo consultations and quotations seeded.');
    }

    private function bookConsultations(?User $admin, $clients): void
    {
        $service = app(ConsultationService::class);

        foreach (self::BOOKINGS as $index => $row) {
            $client = $clients[$index % $clients->count()];

            try {
                $consultation = $service->book([
                    'full_name' => $client->name,
                    'phone' => self::phone(),
                    'email' => $client->email,
                    'tier' => $row['tier'],
                    'category' => $row['category'],
                    'situation' => $row['situation'],
                    'state' => $row['state'],
                    'lga' => $row['lga'],
                    'flock_size' => $row['flock'],
                ], $client);

                if ($admin === null) {
                    continue;
                }

                /*
                 * Spread across the queue. The urgent one at index 0 is left
                 * untouched on purpose: an overdue urgent booking is the single
                 * most useful thing on the admin queue, and a demonstration
                 * where everything is handled hides what the clock is for.
                 */
                match ($index % 5) {
                    0 => null,
                    1 => $service->recordContact($consultation, $admin, 'Spoke to her. Sending the water test list.'),
                    2 => $service->quote($consultation, $admin, 35_000_00, 'Two hours on site plus a written report.'),
                    3 => $this->quoteAndPay($service, $consultation, $admin),
                    default => $this->quotePayAndFinish($service, $consultation, $admin),
                };
            } catch (Throwable $exception) {
                $this->command?->warn('Booking skipped: '.$exception->getMessage());
            }
        }
    }

    /**
     * Quoted, then paid the way a real client pays.
     *
     * Through the checkout and the payment processor rather than by calling
     * markPaid directly. Calling it directly is what this seeder did first, and
     * it produced a consultation the platform had been paid ₦50,000 for with no
     * ledger entry anywhere — the exact failure the rest of this seeder exists
     * to avoid, and one the aggregate on the reconciliation report then showed
     * as a phantom difference nobody could explain.
     */
    private function quoteAndPay(ConsultationService $service, $consultation, User $admin): void
    {
        $service->quote($consultation, $admin, 50_000_00, 'Site visit, soil and water samples, written findings.');

        $client = $consultation->user;

        if ($client === null) {
            return;
        }

        $order = app(ConsultationCheckout::class)
            ->begin($client, $consultation->refresh());

        app(PaymentProcessor::class)
            ->markPaid($order, 'paystack', 'DEMO-'.$order->reference);
    }

    private function quotePayAndFinish(ConsultationService $service, $consultation, User $admin): void
    {
        $this->quoteAndPay($service, $consultation, $admin);
        $service->start($consultation->refresh());
        $service->complete($consultation->refresh());
    }

    private function requestQuotations(?User $admin, $clients): void
    {
        $quotations = app(QuotationService::class);
        $fees = app(StudyFeeService::class);

        foreach (self::PROJECTS as $index => $row) {
            $client = $clients[$index % $clients->count()];

            try {
                $request = new QuotationRequest;
                $request->forceFill([
                    // A quotation request always belongs to an account: the
                    // study fee has to be paid before anything happens, and
                    // paying means checking out.
                    'user_id' => $client->getKey(),
                    'project_type' => QuotationProjectType::from($row['type']),
                    'farm_type' => $row['farm'],
                    'target_capacity' => $row['capacity'],
                    'capacity_unit' => $row['unit'],
                    'owns_land' => true,
                    'land_size' => random_int(1, 6),
                    'land_unit' => 'acres',
                    'state' => $row['state'],
                    'lga' => $row['lga'],
                    'budget_range_min_kobo' => $row['budget'][0],
                    'budget_range_max_kobo' => $row['budget'][1],
                    'currency' => 'NGN',
                    'scope_wanted' => [
                        QuotationScope::Construction->value,
                        QuotationScope::EquipmentSupply->value,
                        QuotationScope::Stocking->value,
                    ],
                    'power_situation' => QuotationPowerSituation::cases()[0],
                    'water_source' => QuotationWaterSource::cases()[0],
                    'additional_notes' => $row['notes'],
                    'status' => QuotationRequestStatus::Submitted,
                ])->save();

                if ($admin === null || $index === 0) {
                    // The first is left at the gate: the study fee is unpaid,
                    // which is the state that shows what the gate is for.
                    continue;
                }

                $fee = $fees->raiseFor($request->refresh());

                // Paid through the checkout, for the same reason as the
                // consultation above: markPaid alone moves the gate without
                // recording the money.
                $feeOrder = app(StudyFeeCheckout::class)
                    ->begin($client, $request->refresh());

                app(PaymentProcessor::class)
                    ->markPaid($feeOrder, 'paystack', 'DEMO-'.$feeOrder->reference);

                $quotation = $quotations->startDraft($request->refresh(), $admin, [
                    'title' => $row['farm'].', '.number_format($row['capacity']).' '.$row['unit'],
                ]);

                $this->priceIt($quotation, $row);

                if ($index === 1) {
                    // A priced draft, sitting on the builder — which is where
                    // an administrator actually spends their time.
                    continue;
                }

                $quotations->send($quotation->refresh(), $admin);
            } catch (Throwable $exception) {
                $this->command?->warn('Quotation skipped: '.$exception->getMessage());
            }
        }
    }

    /**
     * Priced lines, in the sections a real proposal is broken into.
     *
     * Written out rather than generated: a proposal is the document that goes
     * to a bank, and a demonstration full of "Item 1 — ₦100,000" shows the
     * layout without showing that the layout carries a real argument.
     *
     * @param  array{farm: string, capacity: int, unit: string, budget: array{int, int}}  $row
     */
    private function priceIt(Quotation $quotation, array $row): void
    {
        // Roughly the low end of the client's own budget, distributed across
        // the sections in proportions that hold for this kind of build.
        $budget = $row['budget'][0];

        $lines = [
            ['Site works', 'Clearing, levelling and access road', 0.10, 1, 'lot'],
            ['Buildings', $row['farm'].' house, '.number_format($row['capacity']).' '.$row['unit'].' capacity', 0.42, 1, 'lot'],
            ['Water', 'Borehole, overhead tank and reticulation', 0.14, 1, 'lot'],
            ['Power', 'Solar array with battery backup and changeover', 0.12, 1, 'lot'],
            ['Equipment', 'Feeders, drinkers and handling equipment', 0.14, 1, 'set'],
            ['Stocking', 'First stock, feed to point of lay, and vaccines', 0.08, 1, 'lot'],
        ];

        foreach ($lines as $order => [$section, $description, $share, $quantity, $unit]) {
            $quotation->lineItems()->create([
                'section' => $section,
                'description' => $description,
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_price_kobo' => (int) round($budget * $share),
                'sort_order' => $order,
            ]);
        }

        $quotation->refresh()->recalculate();
    }

    /**
     * @return Collection<int, User>
     */
    private function clients()
    {
        return User::query()
            ->whereIn('email', ['buyer@example.test', 'musa@example.test', 'tunde@example.test'])
            ->get();
    }

    /**
     * 0700 is reserved and unassigned in the Nigerian numbering plan, so a
     * demonstration screenshot cannot show a stranger's phone number.
     */
    private static function phone(): string
    {
        return '0700'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
    }
}

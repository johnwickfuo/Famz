<?php

use App\Enums\RoleName;
use App\Filament\Admin\Pages\AssistantUsage;
use App\Filament\Admin\Resources\BreedStandards\Pages\CreateBreedStandard;
use App\Filament\Admin\Resources\BreedStandards\Pages\EditBreedStandard;
use App\Filament\Admin\Resources\BreedStandards\Pages\ListBreedStandards;
use App\Models\BreedStandard;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Ai\Chat\ChatGuard;
use App\Services\Ai\Chat\IntentClassifier;
use App\Services\Ai\Retrieval\FeedCalculatorService;
use Database\Seeders\BreedStandardSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(BreedStandardSeeder::class);

    Cache::flush();
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleName::Admin->value);
    $this->actingAs($this->admin);
});

it('lists the feeding tables the assistant quotes from', function (): void {
    livewire(ListBreedStandards::class)
        ->assertSuccessful()
        // Filtered, because eighty-odd rows do not fit on one page and the
        // point of the assertion is that the rows are reachable, not that the
        // paginator works.
        ->filterTable('breed', 'Ross 308')
        ->assertCanSeeTableRecords(BreedStandard::query()->where('breed', 'Ross 308')->orderBy('week_number')->get());
});

it('lets an administrator correct a figure the assistant is quoting', function (): void {
    $row = BreedStandard::query()->forBreed('Ross 308')->where('week_number', 1)->firstOrFail();

    livewire(EditBreedStandard::class, ['record' => $row->getRouteKey()])
        ->fillForm([
            'avg_feed_g_per_bird_per_day' => 20,
            'source' => 'Local correction, Oyo trial 2026',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $row->refresh();

    expect($row->avg_feed_g_per_bird_per_day)->toBe(20.0);

    /*
     * The point of the screen. An edit here changes the answer a farmer gets,
     * and the new source is what gets cited underneath it.
     */
    $calculation = app(FeedCalculatorService::class)->forWeek('Ross 308', 500, 1);

    expect($calculation->weeks[0]['g_per_bird_per_day'])->toBe(20.0)
        ->and($calculation->sources)->toContain('Local correction, Oyo trial 2026');
});

it('teaches the assistant a new breed without a code change', function (): void {
    // The classifier reads its vocabulary out of this table, so adding a row is
    // what makes the assistant recognise the name in a question.
    expect(app(IntentClassifier::class)->classify('feed for 200 Hubbard Flex at week 4')->slot('breed'))
        ->toBeNull();

    livewire(CreateBreedStandard::class)
        ->fillForm([
            'species' => 'Chicken',
            'breed' => 'Hubbard Flex',
            'production_type' => 'Broiler',
            'week_number' => 4,
            'avg_feed_g_per_bird_per_day' => 90,
            'water_multiplier' => 2.0,
            'source' => 'Hubbard broiler management guide',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(app(IntentClassifier::class)->classify('feed for 200 Hubbard Flex at week 4')->slot('breed'))
        ->toBe('Hubbard Flex');
});

it('stops quoting a row an administrator turns off', function (): void {
    $row = BreedStandard::query()->forBreed('Cobb 500')->where('week_number', 1)->firstOrFail();

    livewire(EditBreedStandard::class, ['record' => $row->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    // Week 1 is gone, so a week 1–2 question now reads week 2 only.
    $calculation = app(FeedCalculatorService::class)->forFlock('Cobb 500', 100, 1, 2);

    expect(collect($calculation->weeks)->pluck('read_from_week')->unique()->all())->not->toContain(1);
});

it('shows the usage dashboard', function (): void {
    livewire(AssistantUsage::class)->assertSuccessful();
});

it('reports volume, cache rate and spend', function (): void {
    $conversation = ChatConversation::factory()->create();

    // Two questions, one answered fresh and one from the cache.
    ChatMessage::factory()->for($conversation, 'conversation')->count(2)->create([
        'normalised_question' => 'how much feed for 500 ross 308 to week 6',
        'content' => 'How much feed for 500 Ross 308 to week 6?',
    ]);

    ChatMessage::factory()->for($conversation, 'conversation')->assistant()->create([
        'context_used' => ['facts' => [['label' => 'x', 'value' => '1,526 kg', 'source' => 'Aviagen', 'captured' => null]], 'notes' => [], 'has_figures' => true, 'numbers' => ['1526']],
    ]);

    ChatMessage::factory()->for($conversation, 'conversation')->assistant()->create([
        'from_cache' => true,
        'tokens_used' => 0,
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'context_used' => ['facts' => [], 'notes' => [], 'has_figures' => false, 'numbers' => []],
    ]);

    $data = livewire(AssistantUsage::class)->instance()->getViewData();

    expect($data['totals']['questions'])->toBe(2)
        ->and($data['totals']['answered'])->toBe(2)
        ->and($data['totals']['cached'])->toBe(1)
        ->and($data['totals']['cache_rate'])->toBe(50)
        ->and($data['totals']['tokens'])->toBe(1020)
        // Top questions are grouped on the normalised form: the raw list is the
        // same twenty questions in four hundred spellings.
        ->and($data['topQuestions'][0]['asked'])->toBe(2);
});

it('survives a period with nothing in it rather than dividing by zero', function (): void {
    $data = livewire(AssistantUsage::class)
        ->fillForm(['from' => now()->subYears(3)->toDateString(), 'until' => now()->subYears(3)->addDay()->toDateString()])
        ->instance()
        ->getViewData();

    expect($data['totals']['answered'])->toBe(0)
        ->and($data['totals']['cache_rate'])->toBe(0)
        ->and($data['totals']['figure_rate'])->toBe(0);
});

it('shows the budget as spent once it is', function (): void {
    settings()->set('ai_daily_token_budget', 1000);
    app(ChatGuard::class)->recordSpend(1500);

    $budget = livewire(AssistantUsage::class)->instance()->getViewData()['budget'];

    expect($budget['over'])->toBeTrue()
        ->and($budget['remaining'])->toBe(0)
        ->and($budget['percent'])->toBe(100);
});

it('keeps a seller out of the assistant screens', function (): void {
    $seller = User::factory()->create();
    $seller->assignRole(RoleName::Seller->value);

    $this->actingAs($seller);

    $this->get(AssistantUsage::getUrl(panel: 'admin'))->assertForbidden();
});

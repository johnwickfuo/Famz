<?php

namespace App\Filament\Admin\Pages;

use App\Models\ChatMessage;
use App\Services\Ai\Chat\ChatGuard;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * What the assistant is being asked, and what it is costing.
 *
 * Four things an administrator needs and cannot get anywhere else.
 *
 * How much it is being used, because a free feature nobody uses should be
 * reconsidered and one everybody uses should be resourced.
 *
 * What people actually ask, which is the most valuable thing on this page. The
 * top questions are a list of what the platform's customers do not know, and
 * that is a course catalogue, a consultation pitch and a set of feeding tables
 * worth loading, written by the people who need them.
 *
 * The cache hit rate, because the cache is what keeps the bill survivable and a
 * rate that collapses means something has changed — a price snapshot churning
 * every day, or somebody phrasing questions to defeat the key.
 *
 * And the spend, against today's ceiling, so the day the assistant drops into
 * cache-only mode is a thing somebody saw coming rather than a thing a farmer
 * reported.
 */
class AssistantUsage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Assistant';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.admin.pages.assistant-usage';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Assistant usage');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Assistant usage');
    }

    public function getSubheading(): ?string
    {
        return __('What people are asking the free assistant, and what it costs to answer them.');
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->subDays(30)->toDateString(),
            'until' => now()->endOfDay()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Form::make([
                    DatePicker::make('from')->label(__('From'))->live(),
                    DatePicker::make('until')->label(__('Until'))->live(),
                ])->columns(2),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $from = filled($this->data['from'] ?? null)
            ? Carbon::parse($this->data['from'])->startOfDay()
            : now()->subDays(30)->startOfDay();

        $until = filled($this->data['until'] ?? null)
            ? Carbon::parse($this->data['until'])->endOfDay()
            : now()->endOfDay();

        $guard = app(ChatGuard::class);

        $answers = fn () => ChatMessage::query()
            ->assistant()
            ->whereBetween('created_at', [$from, $until]);

        $answered = (clone $answers())->count();
        $cached = (clone $answers())->where('from_cache', true)->count();
        $tokens = (int) (clone $answers())->sum('tokens_used');

        /*
         * Answers that went out with no figures behind them. Not a fault — most
         * questions are about husbandry and have no number in the answer — but
         * a sharp rise means people are asking for figures the platform does
         * not hold, which is a list of tables worth loading.
         */
        $withoutFigures = (clone $answers())
            ->whereJsonContains('context_used->has_figures', false)
            ->count();

        return [
            'from' => $from,
            'until' => $until,
            'totals' => [
                'answered' => $answered,
                'questions' => ChatMessage::query()
                    ->where('role', ChatMessage::ROLE_USER)
                    ->whereBetween('created_at', [$from, $until])
                    ->count(),
                'people' => ChatMessage::query()
                    ->whereBetween('chat_messages.created_at', [$from, $until])
                    ->distinct()
                    ->count('chat_conversation_id'),
                'cached' => $cached,
                // Guarded: a period with no answers is a legitimate state, and
                // dividing by it should not take the page down.
                'cache_rate' => $answered > 0 ? (int) round(($cached / $answered) * 100) : 0,
                'tokens' => $tokens,
                'without_figures' => $withoutFigures,
                'figure_rate' => $answered > 0 ? (int) round((($answered - $withoutFigures) / $answered) * 100) : 0,
                'median_latency' => $this->medianLatency($from, $until),
            ],
            'budget' => [
                'limit' => $guard->dailyTokenBudget(),
                'spent' => $guard->spentToday(),
                'remaining' => $guard->budgetRemaining(),
                'over' => $guard->overBudget(),
                'percent' => $guard->dailyTokenBudget() > 0
                    ? min(100, (int) round(($guard->spentToday() / $guard->dailyTokenBudget()) * 100))
                    : 0,
            ],
            'topQuestions' => $this->topQuestions($from, $until),
            'recent' => $this->recent($from, $until),
        ];
    }

    /**
     * What people actually ask, grouped on the normalised form.
     *
     * Grouped rather than listed, because the raw list is the same twenty
     * questions in four hundred spellings and tells nobody anything.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topQuestions(Carbon $from, Carbon $until): array
    {
        return ChatMessage::query()
            ->where('role', ChatMessage::ROLE_USER)
            ->whereBetween('created_at', [$from, $until])
            ->whereNotNull('normalised_question')
            ->where('normalised_question', '!=', '')
            ->select('normalised_question')
            ->selectRaw('COUNT(*) as asked')
            ->selectRaw('MAX(content) as example')
            ->groupBy('normalised_question')
            ->orderByDesc('asked')
            ->limit(25)
            ->get()
            ->map(fn (ChatMessage $row): array => [
                // The example is shown rather than the normalised form: an
                // administrator reading "how much feed for 500 ross 308"
                // stripped of its punctuation learns less than one reading what
                // somebody typed.
                'question' => (string) $row->example,
                'asked' => (int) $row->asked,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recent(Carbon $from, Carbon $until): array
    {
        return ChatMessage::query()
            ->assistant()
            ->whereBetween('created_at', [$from, $until])
            ->with('conversation.user')
            ->latest('id')
            ->limit(40)
            ->get()
            ->map(fn (ChatMessage $message): array => [
                'when' => $message->created_at?->format('j M, H:i'),
                'who' => $message->conversation?->user?->name ?? __('Guest'),
                'answer' => \Illuminate\Support\Str::limit((string) $message->content, 140),
                'had_figures' => $message->hadFigures(),
                'from_cache' => (bool) $message->from_cache,
                'tokens' => (int) $message->tokens_used,
                'latency' => (int) $message->latency_ms,
                /*
                 * Whether a model wrote this at all. Dosage redirects, "the
                 * assistant is busy" and unreachable-provider apologies are
                 * written by the platform, and the context was still assembled
                 * and stored for them — correctly, it is the record of what was
                 * available. Counting those figures against an apology the
                 * model never saw would fill this column with noise and hide
                 * the one row that matters.
                 */
                'from_model' => filled($message->provider) || (bool) $message->from_cache,
                // How many figures the model was permitted. The audit trail in
                // one number: an answer with numbers in it and nothing here is
                // the failure worth investigating.
                'permitted' => count($message->permittedNumbers()),
            ])
            ->all();
    }

    private function medianLatency(Carbon $from, Carbon $until): int
    {
        $latencies = ChatMessage::query()
            ->assistant()
            ->whereBetween('created_at', [$from, $until])
            ->where('latency_ms', '>', 0)
            ->orderBy('latency_ms')
            ->pluck('latency_ms');

        if ($latencies->isEmpty()) {
            return 0;
        }

        // Median, not mean: one twenty-second timeout should not be allowed to
        // describe a day of eight-hundred-millisecond answers.
        $middle = intdiv($latencies->count(), 2);

        return $latencies->count() % 2 === 1
            ? (int) $latencies[$middle]
            : (int) round(((int) $latencies[$middle - 1] + (int) $latencies[$middle]) / 2);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Ai\Chat\ChatGuard;
use App\Services\Ai\Chat\ChatService;
use App\Services\Ai\Chat\ConversationService;
use App\Services\Branding\BrandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The free assistant.
 *
 * Open to anybody, signed in or not, because the people most likely to need a
 * quick answer about a sick flock at six in the morning are the least likely to
 * have an account. A registration wall here would mean the platform's most
 * useful free thing was invisible to the people it is for.
 *
 * Two rules run through everything below. The first is that a guest's thread
 * lives on a session token, minted here and never exposed in a URL — a
 * conversation somebody could link to would be a conversation somebody else
 * could read. The second is that the disclaimer travels with the page, from the
 * server, on every render: it is not a component's default prop that a later
 * refactor can quietly drop.
 */
class AssistantController extends Controller
{
    /**
     * Where a guest's thread is remembered.
     */
    public const SESSION_KEY = 'ai_chat_token';

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ChatGuard $guard,
        private readonly BrandingService $branding,
    ) {}

    public function show(Request $request): Response
    {
        $token = $this->token($request);
        $user = $request->user();

        $conversation = $this->conversations->current($user, $token);

        return Inertia::render('Assistant/Index', [
            'conversation' => ['id' => $conversation->getKey()],
            'messages' => $this->transcript($conversation),
            'disclaimer' => $this->disclaimer(),
            'suggestions' => $this->suggestions(),
            'allowance' => $this->guard->remainingFor($user, $token, $request->ip() ?? ''),
            'consultationUrl' => route('consultations.create'),
        ]);
    }

    /**
     * Ask something.
     *
     * Answers in JSON rather than by re-rendering the page. A chat that
     * reloaded its whole transcript on every turn would lose the scroll
     * position and re-download the conversation to answer one question, which
     * on a phone on a rural connection is the difference between usable and
     * not.
     */
    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:'.ChatService::MAX_MESSAGE_LENGTH],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        $token = $this->token($request);
        $user = $request->user();

        $conversation = $this->resolveConversation($request, $validated['conversation_id'] ?? null);

        $turn = $this->conversations->ask(
            message: $validated['message'],
            user: $user,
            sessionToken: $token,
            ip: $request->ip() ?? '',
            conversation: $conversation,
            options: ['state' => $this->stateFor($request)],
        );

        if (! $turn->answered) {
            /*
             * A real 429 rather than a 200 carrying an apology. The request was
             * genuinely refused, and anybody scripting against this endpoint
             * deserves to be told so in the status line rather than having to
             * parse prose to find out.
             */
            return response()->json([
                'ok' => false,
                'message' => $turn->blockedReason,
                'retry_after' => $turn->retryAfterSeconds,
            ], 429);
        }

        return response()->json([
            'ok' => true,
            'conversation_id' => $turn->conversation->getKey(),
            'question' => $this->message($turn->question),
            'answer' => $this->message($turn->answer),
            // True when the day's budget is spent. The interface says so
            // quietly rather than pretending everything is normal.
            'degraded' => $turn->degraded,
            'allowance' => $this->guard->remainingFor($user, $token, $request->ip() ?? ''),
        ]);
    }

    /**
     * Start again.
     */
    public function reset(Request $request): JsonResponse
    {
        $conversation = $this->conversations->start($request->user(), $this->token($request));

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->getKey(),
        ]);
    }

    /**
     * The conversation this turn belongs to, checked for ownership.
     *
     * A conversation id arrives from the browser, so it is treated as a claim
     * rather than a fact. An id that does not belong to whoever is asking
     * quietly starts a new thread instead of erroring — the failure mode of
     * getting this wrong is reading somebody else's questions, and there is no
     * version of that worth risking to save a person one click.
     */
    private function resolveConversation(Request $request, ?int $id): ?ChatConversation
    {
        if ($id === null) {
            return null;
        }

        return ChatConversation::query()
            ->whereKey($id)
            ->ownedBy($request->user(), $this->token($request))
            ->first();
    }

    /**
     * The guest's thread key, minted once and kept in the session.
     *
     * A signed-in user does not get one: their threads belong to the account,
     * and leaving a token in a shared browser's session would let the next
     * person there read them back as a guest.
     */
    private function token(Request $request): ?string
    {
        if ($request->user() !== null) {
            return null;
        }

        $token = $request->session()->get(self::SESSION_KEY);

        if (blank($token)) {
            $token = ChatConversation::newSessionToken();
            $request->session()->put(self::SESSION_KEY, $token);
        }

        return (string) $token;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transcript(ChatConversation $conversation): array
    {
        return $conversation->messages()
            ->latest('id')
            ->limit(50)
            ->get()
            ->reverse()
            ->map(fn (ChatMessage $message): array => $this->message($message))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function message(ChatMessage $message): array
    {
        return [
            'id' => $message->getKey(),
            'role' => $message->role,
            'content' => (string) $message->content,
            // The sources shown under an answer. Not the figures themselves —
            // those are already in the text — but where they came from, which
            // is what turns a confident sentence into a checkable one.
            'sources' => $this->sources($message),
            'at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * Where an answer's figures came from — on answers that used them.
     *
     * Gated on a model having actually written the text, and the reason is a
     * bug this caught in review: when the provider was unreachable, the
     * transcript showed the platform's own "I cannot answer right now" apology
     * with "Figures from: Aviagen Ross 308 broiler management guide" printed
     * underneath it. The context had been assembled and stored — correctly —
     * but nothing in that sentence came from Aviagen, and attributing it to
     * them is exactly the kind of false confidence this feature exists to
     * avoid.
     *
     * A cached answer carries no provider but was written by one, so it counts.
     *
     * @return array<int, string>
     */
    private function sources(ChatMessage $message): array
    {
        if ($message->isFromUser() || (blank($message->provider) && ! $message->from_cache)) {
            return [];
        }

        return collect((array) data_get($message->context_used, 'facts', []))
            ->pluck('source')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The notice above the input.
     *
     * Built here rather than written into the component so that the company
     * name comes from BrandingService, and so that a redesign of the chat
     * interface cannot lose it by accident. It is not dismissable and there is
     * no prop to turn it off: somebody who has scrolled past it fifty times
     * still needs it there the day they act on a wrong answer.
     */
    private function disclaimer(): array
    {
        return [
            'heading' => __('General guidance only'),
            'points' => [
                __('Answers are general farming guidance, not professional advice for your particular farm.'),
                __('Figures come from published feeding tables and from what sellers are actually listing on this marketplace. They are stated with their source.'),
                __('This assistant will not give drug names, dosages or treatment schedules. Anything affecting animal health needs a vet who has seen the animals.'),
                __('Before spending real money or treating sick animals, book a paid consultation with the :company team.', [
                    'company' => $this->branding->name(),
                ]),
            ],
        ];
    }

    /**
     * Openers, chosen to show what the thing is actually good at.
     *
     * Deliberately concrete. "Ask me anything about farming" produces vague
     * questions and therefore vague answers; a question with a breed, a number
     * and a week in it produces a cited figure, and somebody who sees that once
     * knows how to ask the next one.
     *
     * @return array<int, string>
     */
    private function suggestions(): array
    {
        return [
            __('How much feed do 500 Ross 308 need to week 6?'),
            __('What should I check before I put day-old chicks in the brooder?'),
            __('How much be layer mash now?'),
            __('My goat no dey chop. Wetin fit cause am?'),
        ];
    }

    /**
     * Where the person is, if the platform already knows.
     *
     * A price question is better answered locally than nationally when there is
     * a choice, and asking somebody to type their state when it is on their
     * profile is a question the platform should not need to ask.
     */
    private function stateFor(Request $request): ?string
    {
        $state = $request->user()?->profile?->state;

        return blank($state) ? null : Str::of($state)->trim()->toString();
    }
}

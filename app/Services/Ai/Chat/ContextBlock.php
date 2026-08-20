<?php

namespace App\Services\Ai\Chat;

/**
 * The figures handed to the model, and nothing else it may state as fact.
 *
 * This is the whole safety mechanism of the assistant expressed as a data
 * structure. The system prompt says every number in an answer must come from
 * this block; this class is what builds the block, records what went into it,
 * and gets stored verbatim on the message so that a later "the bot made that
 * up" has an exact answer.
 *
 * `facts` is the list the model may quote. `numbers` is the same figures
 * flattened, which is what the test suite asserts an answer against.
 */
final class ContextBlock
{
    /**
     * @param  array<int, array{label: string, value: string, source: string, captured: string|null}>  $facts
     * @param  array<int, string>  $notes
     */
    public function __construct(
        public readonly array $facts = [],
        public readonly array $notes = [],
        public readonly bool $hasFigures = false,
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @param  array<int, array{label: string, value: string, source: string, captured: string|null}>  $facts
     * @param  array<int, string>  $notes
     */
    public static function of(array $facts, array $notes = []): self
    {
        return new self($facts, $notes, $facts !== []);
    }

    /**
     * The block as the model sees it.
     *
     * Plain labelled lines rather than JSON. A model asked to read JSON and
     * then write Pidgin tends to leak the structure into the answer, and the
     * point of this block is to be quoted from, not reproduced.
     */
    public function render(): string
    {
        if ($this->facts === [] && $this->notes === []) {
            return "RETRIEVED DATA: none.\n"
                ."You have NO figures for this question. Do not state any number, "
                ."price, weight, quantity or date. Answer only with general guidance, "
                ."or say what you would need to know.";
        }

        $lines = ["RETRIEVED DATA — these are the ONLY figures you may use:"];

        foreach ($this->facts as $index => $fact) {
            $line = sprintf('%d. %s: %s', $index + 1, $fact['label'], $fact['value']);
            $line .= sprintf(' [source: %s', $fact['source']);
            $line .= $fact['captured'] !== null ? sprintf(', captured %s]', $fact['captured']) : ']';

            $lines[] = $line;
        }

        foreach ($this->notes as $note) {
            $lines[] = 'NOTE: '.$note;
        }

        return implode("\n", $lines);
    }

    /**
     * Every numeric token in the block, for auditing an answer against it.
     *
     * Notes are scanned as well as facts, and deliberately so. A note saying
     * "I have tables for Ross 308 and Cobb 500" puts those figures in front of
     * the model, so an answer repeating them is quoting the context rather than
     * inventing — and an audit that only knew about `facts` would flag it.
     *
     * Labels are scanned too, for the same reason: "Total feed, weeks 1–6" is
     * where the week numbers in the answer come from.
     *
     * @return array<int, string>
     */
    public function numbers(): array
    {
        $found = [];

        $scan = function (string $text) use (&$found): void {
            preg_match_all('/\d[\d,]*(?:\.\d+)?/', $text, $matches);

            foreach ($matches[0] ?? [] as $number) {
                $found[] = self::normaliseNumber($number);
            }
        };

        foreach ($this->facts as $fact) {
            $scan($fact['label']);
            $scan($fact['value']);
            $scan((string) $fact['source']);
            $scan((string) ($fact['captured'] ?? ''));
        }

        foreach ($this->notes as $note) {
            $scan($note);
        }

        return array_values(array_unique($found));
    }

    /**
     * One numeric token in the form the audit compares on.
     *
     * Thousands separators go, and a trailing ".0" goes with them: the model
     * writing "1526 kg" or "1,526.0 kg" for a context figure of "1,526" is the
     * same claim, and an audit that called either of those an invented number
     * would fail on correct answers and get switched off.
     */
    public static function normaliseNumber(string $number): string
    {
        $clean = str_replace(',', '', $number);

        if (str_contains($clean, '.')) {
            $clean = rtrim(rtrim($clean, '0'), '.');
        }

        return $clean === '' ? '0' : $clean;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'facts' => $this->facts,
            'notes' => $this->notes,
            'has_figures' => $this->hasFigures,
            'numbers' => $this->numbers(),
        ];
    }
}

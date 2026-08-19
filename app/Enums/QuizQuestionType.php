<?php

namespace App\Enums;

enum QuizQuestionType: string
{
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case TrueFalse = 'true_false';

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
            self::SingleChoice => __('Pick one'),
            self::MultipleChoice => __('Pick every right one'),
            self::TrueFalse => __('True or false'),
        };
    }

    /**
     * Whether more than one option may be chosen.
     */
    public function allowsMultiple(): bool
    {
        return $this === self::MultipleChoice;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }
}

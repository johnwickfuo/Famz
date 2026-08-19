<?php

namespace App\Enums;

enum CourseLevel: string
{
    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';

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
            self::Beginner => __('New to this'),
            self::Intermediate => __('Some experience'),
            self::Advanced => __('Experienced'),
        };
    }

    /**
     * The plain word, for a filter chip where the sentence would not fit.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Beginner => __('Beginner'),
            self::Intermediate => __('Intermediate'),
            self::Advanced => __('Advanced'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $level): array => [$level->value => $level->shortLabel()])
            ->all();
    }
}

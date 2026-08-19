<?php

namespace App\Enums;

enum LessonType: string
{
    case Pdf = 'pdf';
    case Video = 'video';
    case Text = 'text';
    case Quiz = 'quiz';

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
            self::Pdf => __('Handout'),
            self::Video => __('Video'),
            self::Text => __('Reading'),
            self::Quiz => __('Questions'),
        };
    }

    /**
     * Whether this kind of lesson has a file behind it.
     */
    public function hasFile(): bool
    {
        return in_array($this, [self::Pdf, self::Video], true);
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

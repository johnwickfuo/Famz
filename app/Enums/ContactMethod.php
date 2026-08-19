<?php

namespace App\Enums;

/**
 * How a mentor wants to be reached.
 *
 * The value behind it — a number, an address, a meeting link — is locked until
 * an engagement is paid for. That is the whole commercial model: the platform
 * is paid for the introduction, so the introduction cannot be free.
 */
enum ContactMethod: string
{
    case Whatsapp = 'whatsapp';
    case Phone = 'phone';
    case Email = 'email';
    case Zoom = 'zoom';
    case GoogleMeet = 'google_meet';
    case PhysicalVisit = 'physical_visit';

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
            self::Whatsapp => __('WhatsApp'),
            self::Phone => __('Phone call'),
            self::Email => __('Email'),
            self::Zoom => __('Zoom'),
            self::GoogleMeet => __('Google Meet'),
            self::PhysicalVisit => __('Visit in person'),
        };
    }

    /**
     * What to ask the mentor for, so the field is never a bare "Contact value".
     */
    public function valueLabel(): string
    {
        return match ($this) {
            self::Whatsapp => __('WhatsApp number'),
            self::Phone => __('Phone number'),
            self::Email => __('Email address'),
            self::Zoom => __('Zoom link or meeting ID'),
            self::GoogleMeet => __('Google Meet link'),
            self::PhysicalVisit => __('Where you meet people'),
        };
    }

    public function placeholder(): string
    {
        return match ($this) {
            self::Whatsapp, self::Phone => '0803 000 0000',
            self::Email => 'name@example.com',
            self::Zoom => 'https://zoom.us/j/0000000000',
            self::GoogleMeet => 'https://meet.google.com/abc-defg-hij',
            self::PhysicalVisit => __('Farm address, and the days you are there'),
        };
    }

    /**
     * Whether this method needs the two people to be in the same place.
     *
     * Used by matching: somebody who asked for an in-person visit is not
     * served by a mentor who only does Zoom, however good the tag overlap.
     */
    public function isInPerson(): bool
    {
        return $this === self::PhysicalVisit;
    }

    /**
     * A link the client can act on, where the method has one.
     */
    public function actionUrl(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return match ($this) {
            // Nigerian numbers are typed locally far more often than in E.164.
            self::Whatsapp => 'https://wa.me/'.$this->internationalise($digits),
            self::Phone => 'tel:+'.$this->internationalise($digits),
            self::Email => 'mailto:'.$value,
            self::Zoom, self::GoogleMeet => str_starts_with($value, 'http') ? $value : null,
            self::PhysicalVisit => null,
        };
    }

    /**
     * 0803… becomes 234803…, which is what a wa.me link needs.
     */
    private function internationalise(string $digits): string
    {
        if (str_starts_with($digits, '234')) {
            return $digits;
        }

        return str_starts_with($digits, '0') ? '234'.substr($digits, 1) : $digits;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $method): array => [$method->value => $method->label()])
            ->all();
    }
}

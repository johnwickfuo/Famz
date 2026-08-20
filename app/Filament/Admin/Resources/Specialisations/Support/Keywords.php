<?php

namespace App\Filament\Admin\Resources\Specialisations\Support;

/**
 * Turning the keyword textarea into the JSON column, in one place.
 *
 * A textarea because a repeater for twenty short phrases is a form nobody
 * finishes; an array in the database because the matcher iterates it.
 */
class Keywords
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fromForm(array $data, ?string $text): array
    {
        $data['keywords'] = collect(preg_split('/\r\n|\r|\n/', (string) $text) ?: [])
            ->map(fn (string $line): string => mb_strtolower(trim($line)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        unset($data['keywords_text']);

        return $data;
    }
}

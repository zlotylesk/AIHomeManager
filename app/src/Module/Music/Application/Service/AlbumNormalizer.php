<?php

declare(strict_types=1);

namespace App\Module\Music\Application\Service;

final class AlbumNormalizer
{
    public static function normalize(string $artist, string $title): string
    {
        $combined = $artist . ' ' . $title;

        // Remove parenthetical content: (Remastered 2011), [Deluxe Edition], {Special}
        $combined = preg_replace('/\s*[\(\[\{][^\)\]\}]*[\)\]\}]\s*/u', ' ', $combined) ?? $combined;

        $combined = mb_strtolower(trim($combined), 'UTF-8');

        // Transliterate diacritics to ASCII (works without intl extension)
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $combined);
        if ($transliterated !== false) {
            $combined = $transliterated;
        }

        // Keep only alphanumeric and spaces
        $combined = preg_replace('/[^a-z0-9 ]/u', '', $combined) ?? $combined;

        // Normalize whitespace
        return preg_replace('/\s+/', ' ', trim($combined)) ?? $combined;
    }
}

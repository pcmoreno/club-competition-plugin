<?php

declare(strict_types=1);

namespace SCS\Services;

/**
 * Converts a KNSB list name into the club's display convention.
 *
 * KNSB stores names surname-first with the Dutch tussenvoegsel capitalised and
 * attached to the surname: "De Roode, Peter", "Van der Aa, Quinten". The club
 * roster (and every viewer table) shows given-name-first with the tussenvoegsel
 * lowercased in the middle: "Peter de Roode", "Quinten van der Aa".
 *
 * So: split on the first comma into surname/given, lowercase any leading
 * tussenvoegsel words in the surname, and re-join as "{given} {surname}".
 * A name without a comma is returned trimmed but otherwise untouched.
 */
class KnsbNameNormalizer
{
    /**
     * Dutch tussenvoegsels (lowercased). Matched greedily from the front of the
     * surname until the first non-particle word (the main surname).
     *
     * @var list<string>
     */
    private const PARTICLES = [
        'van', 'de', 'den', 'der', 'ten', 'ter', 'te', 'het', "'t", "'s",
        'op', 'in', 'aan', 'bij', 'onder', 'over', 'voor', 'uit', 'tot',
        'toe', 'thoe', 'ver', 'vande', 'vander', 'vanden',
    ];

    public function normalize(string $knsbName): string
    {
        $name = trim($knsbName);
        if ($name === '') {
            return '';
        }

        $comma = strpos($name, ',');
        if ($comma === false) {
            return $name;
        }

        $surname = trim(substr($name, 0, $comma));
        $given   = trim(substr($name, $comma + 1));

        $surname = $this->lowercaseLeadingParticles($surname);

        // Given name missing (rare) → just the normalised surname.
        return $given === '' ? $surname : $given . ' ' . $surname;
    }

    /**
     * Lowercase the run of tussenvoegsel words at the start of the surname,
     * leaving the main surname (and its casing) intact. Bails out if the whole
     * surname is particles, so a real surname is never blanked.
     */
    private function lowercaseLeadingParticles(string $surname): string
    {
        $words = preg_split('/\s+/', $surname) ?: [];

        $particles = [];
        $i         = 0;
        for (; $i < count($words); $i++) {
            if (!in_array(mb_strtolower($words[$i]), self::PARTICLES, true)) {
                break;
            }
            $particles[] = mb_strtolower($words[$i]);
        }

        // Every word is a particle → leave the surname as KNSB gave it.
        if ($i === 0 || $i === count($words)) {
            return $surname;
        }

        $main = array_slice($words, $i);

        return implode(' ', array_merge($particles, $main));
    }
}

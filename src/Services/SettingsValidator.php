<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Engine\Settings\StandingsDisplaySettings;
use SCS\Engine\SettingsResolver;
use SCS\Entity\Enum\BuchholzMethod;
use SCS\Entity\Enum\PairingSystem;
use SCS\Entity\Enum\StandingsColumn;
use SCS\Entity\Enum\StandingsMetric;
use SCS\Entity\Enum\TprMethod;
use SCS\Exception\ValidationException;

// Strict gate on client-supplied settings JSON; returns the normalized array to store.
final class SettingsValidator
{
    private const BYE_KEY_PATTERN = '/^[a-z][a-z0-9_]{0,19}$/';

    public function __construct(private readonly SettingsResolver $settingsResolver)
    {
    }

    /**
     * Scoring settings are per-system for the same reason pairing settings are:
     * the system decides which class parses the blob. Normalising a Keizer
     * payload through the standard class would silently drop every Keizer knob.
     *
     * @param array<string,mixed> $input
     * @param list<string>        $byeKeysInUse keys the season's attendance already holds
     * @return array<string,mixed>
     */
    public function validateScoring(PairingSystem $system, array $input, array $byeKeysInUse = []): array
    {
        $settings = $this->settingsResolver->scoringFor($system, $input);
        $errors   = [];

        // Refused rather than clamped so the admin hears about it. The rule is
        // policy and applies to every scoring system: no result or bye deducts.
        foreach ($input['gameOutcomes'] ?? [] as $key => $value) {
            if (!is_numeric($value)) {
                $errors["gameOutcomes.$key"] = 'Must be a number.';
            } elseif ($value < 0) {
                $errors["gameOutcomes.$key"] = 'Cannot be negative.';
            }
        }

        // Attendance stores the key itself (VARCHAR(20)), so it must fit and be unambiguous.
        $seen = [];
        foreach ($input['byeTypes'] ?? [] as $i => $bye) {
            $key = is_array($bye) ? trim((string)($bye['key'] ?? '')) : '';
            if ($key === '') {
                $errors["byeTypes.$i.key"] = 'Key is required.';
            } elseif (preg_match(self::BYE_KEY_PATTERN, $key) !== 1) {
                $errors["byeTypes.$i.key"] = 'Use lowercase letters, digits and underscores, starting with a letter, at most 20 characters.';
            } elseif (isset($seen[$key])) {
                $errors["byeTypes.$i.key"] = sprintf('The key "%s" is used twice.', $key);
            }
            $seen[$key] = true;
            if (!is_array($bye) || trim((string)($bye['label'] ?? '')) === '') {
                $errors["byeTypes.$i.label"] = 'Label is required.';
            }
            if (isset($bye['points']) && !is_numeric($bye['points'])) {
                $errors["byeTypes.$i.points"] = 'Must be a number.';
            } elseif (isset($bye['points']) && $bye['points'] < 0) {
                $errors["byeTypes.$i.points"] = 'Cannot be negative.';
            }
        }

        // The engine assigns the reserved bye types itself, so they must survive any client payload.
        if (isset($input['byeTypes']) && is_array($input['byeTypes'])) {
            $keys    = array_column(array_filter($input['byeTypes'], 'is_array'), 'key');
            $missing = array_diff($settings->reservedByeKeys(), $keys);
            if ($missing !== []) {
                $errors['byeTypes'] = sprintf('Reserved bye type(s) cannot be removed: %s.', implode(', ', $missing));
            }

            // Removing or renaming a type a round still holds would orphan those rows.
            $orphaned = array_diff($byeKeysInUse, array_map('trim', array_map('strval', $keys)));
            if ($orphaned !== []) {
                $errors['byeTypes'] = sprintf('Bye type(s) already recorded in a round cannot be removed or renamed: %s.', implode(', ', $orphaned));
            }
        }

        if (isset($input['rankBy'])) {
            $rankBy = StandingsMetric::tryFrom((string)$input['rankBy']);
            if ($rankBy === null) {
                $errors['rankBy'] = 'Unknown ranking metric.';
            } elseif (!$rankBy->canRankBy()) {
                $errors['rankBy'] = sprintf('%s can only be used as a tiebreaker, not to rank by.', $rankBy->label());
            }
        }

        foreach ($input['tiebreakers'] ?? [] as $i => $metric) {
            if (StandingsMetric::tryFrom((string)$metric) === null) {
                $errors["tiebreakers.$i"] = 'Unknown tiebreak metric.';
            }
        }

        // The method must be implemented, not merely a valid enum case: an
        // unimplemented one makes the calculator skip itself, so the metric
        // silently reads 0 for every player while the UI shows it as configured.
        $buchholz = $input['tiebreakConfig']['buchholz']['method'] ?? null;
        if ($buchholz !== null) {
            $method = BuchholzMethod::tryFrom((string)$buchholz);
            if ($method === null) {
                $errors['tiebreakConfig.buchholz.method'] = 'Unknown Buchholz method.';
            } elseif (!$method->isImplemented()) {
                $errors['tiebreakConfig.buchholz.method'] = sprintf('The %s Buchholz method is not implemented yet.', $method->label());
            }
        }

        $tpr = $input['tiebreakConfig']['performance_rating']['method'] ?? null;
        if ($tpr !== null) {
            $method = TprMethod::tryFrom((string)$tpr);
            if ($method === null) {
                $errors['tiebreakConfig.performance_rating.method'] = 'Unknown TPR method.';
            } elseif (!$method->isImplemented()) {
                $errors['tiebreakConfig.performance_rating.method'] = sprintf('The %s TPR method is not implemented yet.', $method->label());
            }
        }

        $maxGroup = $input['tiebreakConfig']['direct_encounter']['maxGroup'] ?? null;
        if ($maxGroup !== null && (!is_numeric($maxGroup) || (int)$maxGroup < 2)) {
            $errors['tiebreakConfig.direct_encounter.maxGroup'] = 'Must be an integer of 2 or more.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $settings->getSettings();
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function validateDisplay(array $input): array
    {
        $errors = [];
        foreach ($input['columns'] ?? [] as $i => $column) {
            if (StandingsColumn::tryFrom((string)$column) === null) {
                $errors["columns.$i"] = 'Unknown column.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return StandingsDisplaySettings::fromArray($input)->getSettings();
    }

    /**
     * Pairing settings are per-system, so the system decides which class parses
     * the blob. Every knob normalises rather than rejects, so there is nothing
     * to collect errors from — the one failure is a system with no settings at
     * all, where storing the payload would leave values no engine ever reads.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function validatePairing(PairingSystem $system, array $input): array
    {
        $settings = $this->settingsResolver->pairingFor($system, $input);
        if ($settings === null) {
            throw new ValidationException(['pairing_settings' => 'Pairing settings for this system are not supported yet.']);
        }

        return $settings->getSettings();
    }
}

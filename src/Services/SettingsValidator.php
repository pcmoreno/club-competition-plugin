<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Engine\Settings\ManualPairingSettings;
use SCS\Engine\Settings\StandardScoringSettings;
use SCS\Engine\Settings\StandingsDisplaySettings;
use SCS\Entity\Enum\BuchholzMethod;
use SCS\Entity\Enum\StandingsColumn;
use SCS\Entity\Enum\StandingsMetric;
use SCS\Entity\Enum\TprMethod;
use SCS\Exception\ValidationException;

// Strict gate on client-supplied settings JSON; returns the normalized array to store.
final class SettingsValidator
{
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function validateScoring(array $input): array
    {
        $errors = [];

        foreach ($input['gameOutcomes'] ?? [] as $key => $value) {
            if (!is_numeric($value)) {
                $errors["gameOutcomes.$key"] = 'Must be a number.';
            }
        }

        foreach ($input['byeTypes'] ?? [] as $i => $bye) {
            if (!is_array($bye) || ($bye['key'] ?? '') === '') {
                $errors["byeTypes.$i.key"] = 'Key is required.';
            }
            if (isset($bye['points']) && !is_numeric($bye['points'])) {
                $errors["byeTypes.$i.points"] = 'Must be a number.';
            }
        }

        // The engine assigns the reserved bye types itself, so they must survive any client payload.
        if (isset($input['byeTypes']) && is_array($input['byeTypes'])) {
            $keys    = array_column(array_filter($input['byeTypes'], 'is_array'), 'key');
            $missing = array_diff(StandardScoringSettings::reservedByeKeys(), $keys);
            if ($missing !== []) {
                $errors['byeTypes'] = sprintf('Reserved bye type(s) cannot be removed: %s.', implode(', ', $missing));
            }
        }

        if (isset($input['rankBy']) && StandingsMetric::tryFrom((string)$input['rankBy']) === null) {
            $errors['rankBy'] = 'Unknown ranking metric.';
        }

        foreach ($input['tiebreakers'] ?? [] as $i => $metric) {
            if (StandingsMetric::tryFrom((string)$metric) === null) {
                $errors["tiebreakers.$i"] = 'Unknown tiebreak metric.';
            }
        }

        $buchholz = $input['tiebreakConfig']['buchholz']['method'] ?? null;
        if ($buchholz !== null && BuchholzMethod::tryFrom((string)$buchholz) === null) {
            $errors['tiebreakConfig.buchholz.method'] = 'Unknown Buchholz method.';
        }

        $tpr = $input['tiebreakConfig']['performance_rating']['method'] ?? null;
        if ($tpr !== null && TprMethod::tryFrom((string)$tpr) === null) {
            $errors['tiebreakConfig.performance_rating.method'] = 'Unknown TPR method.';
        }

        $maxGroup = $input['tiebreakConfig']['direct_encounter']['maxGroup'] ?? null;
        if ($maxGroup !== null && (!is_numeric($maxGroup) || (int)$maxGroup < 2)) {
            $errors['tiebreakConfig.direct_encounter.maxGroup'] = 'Must be an integer of 2 or more.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return StandardScoringSettings::fromArray($input)->getSettings();
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
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function validatePairing(array $input): array
    {
        return ManualPairingSettings::fromArray($input)->getSettings();
    }
}

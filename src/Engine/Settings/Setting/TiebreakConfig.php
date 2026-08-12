<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\BuchholzMethod;
use SCS\Entity\Enum\TprMethod;

/**
 * The parameters the parametric tiebreakers take.
 *
 * Shared with the ordered list it belongs to, and for the same reason: a tie is
 * broken the same way whatever the season ranks by.
 *
 * An unimplemented method coerces back on read rather than being honoured.
 * Settings saved before the validator started rejecting them still name one,
 * and honouring it makes the calculator skip itself — so the column reads zero
 * for every player while the form shows it configured. Repairing on read also
 * means getSettings() emits the corrected value, so the next save persists it.
 */
final class TiebreakConfig implements SettingInterface
{
    public const KEY = 'tiebreakConfig';

    public const DEFAULT = [
        'direct_encounter'   => ['maxGroup' => 2],
        'buchholz'           => ['method' => 'classic'],
        'performance_rating' => ['method' => 'fide_dp'],
    ];

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        // Rendered as part of the tiebreaker list's own schema, not on its own.
        return Tiebreakers::configSchema();
    }

    /** @return array<string,array<string,mixed>> */
    public function normalise(mixed $raw): array
    {
        $config = array_replace_recursive(self::DEFAULT, is_array($raw) ? $raw : []);

        // array_replace_recursive recurses into arrays but lets a scalar replace
        // one outright, so {"buchholz":"x"} leaves a string where the checks
        // below index an array — and this must not throw, being the validation
        // path. A parameter set that isn't a set of parameters says nothing
        // worth keeping, so it falls back whole.
        foreach (self::DEFAULT as $metric => $default) {
            if (!is_array($config[$metric])) {
                $config[$metric] = $default;
            }
        }

        $buchholz = BuchholzMethod::tryFrom((string)($config['buchholz']['method'] ?? ''));
        if ($buchholz === null || !$buchholz->isImplemented()) {
            $config['buchholz']['method'] = BuchholzMethod::Classic->value;
        }

        $tpr = TprMethod::tryFrom((string)($config['performance_rating']['method'] ?? ''));
        if ($tpr === null || !$tpr->isImplemented()) {
            $config['performance_rating']['method'] = TprMethod::FideDp->value;
        }

        return $config;
    }
}

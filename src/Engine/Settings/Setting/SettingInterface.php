<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

/**
 * One configurable knob: its key, the schema the admin form renders it from,
 * and the coercion of whatever value comes back.
 *
 * Settings classes **compose** these rather than hand-rolling each field, so a
 * knob more than one system wants is defined once. That composition is also how
 * the engine answers whether a knob applies at all: a system that derives its
 * round count (round-robin from the roster, a knockout from the field size)
 * simply doesn't compose NumberOfRounds, and the admin is never asked for one.
 */
interface SettingInterface
{
    public function key(): string;

    /**
     * The schema entry the admin form renders this field from.
     *
     * @return array<string,mixed>
     */
    public function field(): array;

    /**
     * Coerce a stored or submitted value into a usable one.
     *
     * Never throws: `fromArray()` is the validation path too — SettingsValidator
     * round-trips input through it — and every settings class in here falls back
     * to a sane value rather than rejecting one.
     */
    public function normalise(mixed $raw): mixed;
}

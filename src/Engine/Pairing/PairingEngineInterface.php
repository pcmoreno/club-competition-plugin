<?php

declare(strict_types=1);

namespace SCS\Engine\Pairing;

// Base marker. Pairing is an optional capability — a format implements one of the sub-interfaces, or none (manual would be none, but here manual is a no-op per-round engine).
interface PairingEngineInterface
{
}

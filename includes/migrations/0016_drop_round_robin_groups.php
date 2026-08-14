<?php

declare(strict_types=1);

// The grouped round-robin was only ever a sectioned individual tournament; team
// play is a separate concern, not a pairing system. Existing seasons fall back
// to the plain round-robin — their rounds and games are already generated, and
// both systems score the same way.
return function (wpdb $wpdb): void {
    $p = $wpdb->prefix . 'scs_';

    $wpdb->query("UPDATE {$p}seasons SET pairing_system = 'round-robin-full' WHERE pairing_system = 'round-robin-groups'");
};

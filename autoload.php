<?php

/**
 * systopic/relay — live updates: a page learns when the data it was rendered
 * from changes, through a hub (Centrifugo) both PHP and the browser talk to.
 * See SYS/.cursor/plans/relay.plan.md.
 *
 * Composer `autoload.files`: tells the system what the package brings. The
 * system and the ORM never name this package; without a `relay` block in the
 * instance config nothing is registered at all.
 */

use Systopic\System\Sys\Hooks\Hooks;
use Systopic\System\Sys\Packages;

// the root its script loads from (src/relay.js, public/js/centrifuge.js)
Packages::addLib('systopic/relay', __DIR__);

// after the config and the database: observer, script, client data
Hooks::on('afterConnect', static fn() => \Systopic\Relay\Relay::boot());

<?php

namespace App\Connectors;

use App\Models\Service;

/**
 * Connectors that need a scheduled look at the external service (connectors:check), e.g. to learn
 * that a Wizarr invitation was redeemed.
 */
interface ChecksExternalState
{
    public function check(Service $service): void;
}

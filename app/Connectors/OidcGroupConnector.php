<?php

namespace App\Connectors;

use App\Models\ServiceAccount;

/**
 * Default: the app logs in through the identity provider and only lets in members of the access
 * group, which SyncUserGroups sets from the account status. Nothing else to do here.
 */
class OidcGroupConnector implements ServiceConnector
{
    public function provision(ServiceAccount $account): ConnectorResult
    {
        return $this->groupSync();
    }

    public function suspend(ServiceAccount $account): ConnectorResult
    {
        return $this->groupSync();
    }

    public function resume(ServiceAccount $account): ConnectorResult
    {
        return $this->groupSync();
    }

    public function deprovision(ServiceAccount $account): ConnectorResult
    {
        return $this->groupSync();
    }

    public function status(ServiceAccount $account): ?ConnectorStatus
    {
        return null;
    }

    private function groupSync(): ConnectorResult
    {
        return ConnectorResult::skipped('Handled by the group sync.');
    }
}

<?php

namespace App\Connectors;

use App\Models\ServiceAccount;

/**
 * Creates, suspends, resumes and removes the account of a user in an external service (KONZEPT 5.5).
 * Called from queued jobs (App\Jobs\RunConnector) with retries: implementations must be idempotent
 * and throw a ConnectorException (permanent for configuration errors) or let HTTP errors bubble up.
 * The config of the service is in $account->service->connector_config; state of the account goes
 * into external_id, external_username and connector_data.
 */
interface ServiceConnector
{
    /**
     * Access was granted (approved, created active or claimed with self-registration).
     */
    public function provision(ServiceAccount $account): ConnectorResult;

    public function suspend(ServiceAccount $account): ConnectorResult;

    /**
     * The suspension was lifted.
     */
    public function resume(ServiceAccount $account): ConnectorResult;

    /**
     * The account or the user was deleted: $account is then a copy that is not saved anymore.
     */
    public function deprovision(ServiceAccount $account): ConnectorResult;

    /**
     * What the portal and the panel show about the external account (null: nothing special).
     */
    public function status(ServiceAccount $account): ?ConnectorStatus;
}

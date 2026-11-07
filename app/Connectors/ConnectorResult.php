<?php

namespace App\Connectors;

use App\Enums\ConnectorRunStatus;

/**
 * Outcome of a connector call, stored in the run.
 */
final readonly class ConnectorResult
{
    public function __construct(public ConnectorRunStatus $status, public string $message) {}

    public static function done(string $message): self
    {
        return new self(ConnectorRunStatus::Succeeded, $message);
    }

    /**
     * An admin has to do it by hand (they got a mail).
     */
    public static function manual(string $message): self
    {
        return new self(ConnectorRunStatus::Manual, $message);
    }

    public static function skipped(string $message): self
    {
        return new self(ConnectorRunStatus::Skipped, $message);
    }
}

<?php

namespace App\Connectors;

use RuntimeException;
use Throwable;

/**
 * A connector call failed. Permanent errors (configuration, rejected API key, unsupported action)
 * are not retried; the admins are notified right away.
 */
class ConnectorException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function permanent(string $message, ?Throwable $previous = null): self
    {
        return new self($message, true, $previous);
    }
}

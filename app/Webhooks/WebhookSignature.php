<?php

namespace App\Webhooks;

use SensitiveParameter;

/**
 * HMAC-SHA256 over "{timestamp}.{body}" with the secret of the service. The apps reject
 * signatures that do not match and timestamps older than the tolerance (replay protection).
 */
class WebhookSignature
{
    public const TIMESTAMP_HEADER = 'X-Account-Manager-Timestamp';

    public const SIGNATURE_HEADER = 'X-Account-Manager-Signature';

    public const EVENT_HEADER = 'X-Account-Manager-Event';

    public const EVENT_ID_HEADER = 'X-Account-Manager-Event-Id';

    /**
     * Seconds a webhook stays valid for the receiver.
     */
    public const TOLERANCE = 300;

    public static function sign(#[SensitiveParameter] string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * Reference implementation of the check in the apps (see docs/HOUSEHOLDS-API.md).
     */
    public static function verify(#[SensitiveParameter] string $secret, string $timestamp, string $body, string $signature, ?int $now = null): bool
    {
        if (! ctype_digit($timestamp) || abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }

        return hash_equals(self::sign($secret, (int) $timestamp, $body), $signature);
    }
}

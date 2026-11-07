<?php

namespace App\Identity;

/**
 * Browser URL of the identity provider's admin UI, linked in the panel; null hides the link.
 */
class AdminUrl
{
    // Users page of the Pocket ID admin UI (v2).
    public const POCKET_ID_PATH = '/settings/admin/users';

    public static function get(): ?string
    {
        if (config('identity.driver') !== 'pocket-id') {
            return null;
        }

        $url = config('identity.admin_url');
        if (is_string($url) && $url !== '') {
            return $url;
        }

        $account = config('identity.account_url');
        if (! is_string($account) || ! preg_match('#^(https?://[^/?\#]+)#i', $account, $match)) {
            return null;
        }

        return $match[1].self::POCKET_ID_PATH;
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Service;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API access of the apps: HTTP Basic with the service key as user name and the service secret
 * (the same one that signs the webhooks) as password.
 */
class AuthenticateService
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->getUser();
        $secret = (string) $request->getPassword();

        $service = $key !== '' && $secret !== '' ? Service::query()->where('key', $key)->first() : null;

        if (! $service?->secretMatches($secret)) {
            return response()->json(['message' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Basic realm="AccountManager"']);
        }

        $request->attributes->set('service', $service);

        return $next($request);
    }
}

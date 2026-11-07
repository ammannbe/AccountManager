<?php

namespace App\Connectors\Wizarr;

use App\Connectors\ConnectorException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * REST API of Wizarr (https://github.com/wizarrrr/wizarr, built for v2026.10, API since v2025.8):
 * header X-API-Key, JSON under /api. The endpoints and fields used here are listed in
 * docs/wizarr.md; verify them against the installed version (/api/docs).
 */
class WizarrClient
{
    public function __construct(
        private readonly string $baseUrl,
        #[SensitiveParameter] private readonly string $apiKey,
        private readonly int $timeout = 15,
    ) {}

    /**
     * POST /api/invitations → 201 {message, invitation: {id, code, url, expires, …}}.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function createInvitation(array $options): array
    {
        $invitation = $this->send('post', 'invitations', $options)->json('invitation');

        if (! is_array($invitation) || ! isset($invitation['id'], $invitation['code'])) {
            throw new ConnectorException('Wizarr answered without an invitation.');
        }

        return $invitation;
    }

    /**
     * GET /api/invitations → {invitations: [{id, code, url, status (pending|used|expired), used_at, used_by, …}], count}.
     *
     * @return list<array<string, mixed>>
     */
    public function invitations(): array
    {
        return $this->list($this->send('get', 'invitations'), 'invitations');
    }

    /**
     * DELETE /api/invitations/{id}; false if it does not exist (anymore).
     */
    public function deleteInvitation(int|string $id): bool
    {
        return $this->send('delete', "invitations/{$id}", allowMissing: true) !== null;
    }

    /**
     * GET /api/users (optionally ?email=, exact match) → {users: [{id, username, email, server, server_type, expires, created_at}], count}.
     *
     * @return list<array<string, mixed>>
     */
    public function users(?string $email = null): array
    {
        return $this->list($this->send('get', 'users', $email !== null ? ['email' => $email] : []), 'users');
    }

    /**
     * POST /api/users/{id}/disable → {message}; Wizarr deletes the user if the server cannot
     * disable users ("… deleted (disable not supported by server)"). Null if the user is gone.
     */
    public function disableUser(int|string $id): ?string
    {
        return $this->send('post', "users/{$id}/disable", allowMissing: true)?->json('message');
    }

    /**
     * POST /api/users/{id}/enable → {message}; also 200 with "Enable failed or not supported …".
     * Null if the user is gone.
     */
    public function enableUser(int|string $id): ?string
    {
        return $this->send('post', "users/{$id}/enable", allowMissing: true)?->json('message');
    }

    /**
     * DELETE /api/users/{id}; false if the user does not exist (anymore).
     */
    public function deleteUser(int|string $id): bool
    {
        return $this->send('delete', "users/{$id}", allowMissing: true) !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return ($allowMissing is true ? Response|null : Response)
     */
    private function send(string $method, string $path, array $data = [], bool $allowMissing = false): ?Response
    {
        try {
            $response = $this->request()->{$method}($path, $data);
        } catch (ConnectionException $exception) {
            throw new ConnectorException("Wizarr is not reachable: {$exception->getMessage()}", previous: $exception);
        }

        /** @var Response $response */
        if ($response->successful()) {
            return $response;
        }

        if ($response->status() === 404 && $allowMissing) {
            return null;
        }

        $error = trim("HTTP {$response->status()} ".(string) ($response->json('error') ?? $response->json('message') ?? mb_substr($response->body(), 0, 300)));

        // Wrong key or URL, invalid options or unknown endpoints do not get better with retries.
        if ($response->redirect() || in_array($response->status(), [400, 401, 403, 404, 405, 422], true)) {
            throw ConnectorException::permanent("Wizarr rejected {$method} /api/{$path}: {$error}");
        }

        throw new ConnectorException("Wizarr failed on {$method} /api/{$path}: {$error}");
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/api/')
            ->withHeaders(['X-API-Key' => $this->apiKey])
            ->acceptJson()
            ->asJson()
            // Never send the API key on to another host.
            ->withoutRedirecting()
            ->withUserAgent('AccountManager-Connectors')
            ->timeout($this->timeout)
            ->connectTimeout(5);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(Response $response, string $key): array
    {
        $items = $response->json($key);

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }
}

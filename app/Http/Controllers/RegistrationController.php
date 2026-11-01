<?php

namespace App\Http\Controllers;

use App\Accounts\Registrar;
use App\Http\Requests\StartRegistrationRequest;
use App\Identity\IdentityProvider;
use App\Identity\IdentityProviderException;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    /**
     * Without a service: only the account, the services follow in the portal.
     */
    public function create(?Service $service = null): View
    {
        $this->ensureOpen($service);

        return view('registrations.create', [
            'service' => $service,
            // Time trap: the encrypted render time comes back with the form.
            'renderedAt' => Crypt::encryptString((string) now()->getTimestamp()),
        ]);
    }

    public function store(StartRegistrationRequest $request, Registrar $registrar, ?Service $service = null): RedirectResponse
    {
        $this->ensureOpen($service);

        $registrar->start($service, $request->string('name')->toString(), $request->string('email')->toString(), app()->getLocale());

        return $service
            ? redirect()->route('registrations.sent', $service)
            : redirect()->route('registrations.account.sent');
    }

    public function sent(?Service $service = null): View
    {
        return view('registrations.sent', ['service' => $service]);
    }

    /**
     * The link only shows a button: mail scanners that open links must not confirm.
     */
    public function show(string $token, Registrar $registrar): View
    {
        $registration = $registrar->findPending($token);

        return view('registrations.confirm', ['registration' => $registration, 'token' => $token]);
    }

    public function confirm(string $token, Registrar $registrar): View|RedirectResponse
    {
        $registration = $registrar->findPending($token);

        if (! $registration) {
            return redirect()->route('registrations.show', ['token' => $token]);
        }

        try {
            $result = $registrar->confirm($registration);
        } catch (IdentityProviderException $exception) {
            Log::error('Registration failed at the identity provider', ['exception' => $exception->getMessage()]);

            return view('registrations.failed', ['service' => $registration->service]);
        }

        return view('registrations.done', ['result' => $result, 'service' => $registration->service]);
    }

    /**
     * "Create account" without a service: needs a provider the AccountManager manages, otherwise
     * nobody could log in afterwards.
     */
    public static function signupOpen(): bool
    {
        return config('login.signup') && app(IdentityProvider::class)->manages();
    }

    private function ensureOpen(?Service $service): void
    {
        abort_unless($service ? $service->self_signup && $service->visible : self::signupOpen(), 404);
    }
}

<?php

namespace App\Http\Controllers;

use App\Accounts\Registrar;
use App\Http\Requests\StartRegistrationRequest;
use App\Identity\IdentityProviderException;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function create(Service $service): View
    {
        abort_unless($service->self_signup && $service->visible, 404);

        return view('registrations.create', [
            'service' => $service,
            // Time trap: the encrypted render time comes back with the form.
            'renderedAt' => Crypt::encryptString((string) now()->getTimestamp()),
        ]);
    }

    public function store(StartRegistrationRequest $request, Service $service, Registrar $registrar): RedirectResponse
    {
        abort_unless($service->self_signup && $service->visible, 404);

        $registrar->start($service, $request->string('name')->toString(), $request->string('email')->toString(), app()->getLocale());

        return redirect()->route('registrations.sent', $service);
    }

    public function sent(Service $service): View
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
}

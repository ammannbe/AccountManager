<?php

namespace App\Http\Controllers\Auth;

use App\Auth\OidcLogin;
use App\Auth\OidcLoginException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\RegistrationController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;
use UnexpectedValueException;

class OidcController extends Controller
{
    public function login(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('portal');
        }

        return view('auth.login', ['signup' => RegistrationController::signupOpen()]);
    }

    public function redirect(): SymfonyRedirectResponse
    {
        abort_unless(config('login.oidc.enabled'), 404);

        return $this->provider()->redirect();
    }

    public function callback(Request $request, OidcLogin $login): RedirectResponse
    {
        abort_unless(config('login.oidc.enabled'), 404);

        try {
            $identity = $this->provider()->user();

            if (! $identity instanceof AbstractUser) {
                throw new UnexpectedValueException('Unexpected OIDC user.');
            }

            $user = $login->resolve($identity);
        } catch (OidcLoginException $exception) {
            return redirect()->route('login')->withErrors(['oidc' => __($exception->getMessage())]);
        } catch (Throwable $exception) {
            Log::warning('OIDC login failed', ['exception' => $exception->getMessage()]);

            return redirect()->route('login')->withErrors(['oidc' => __('The login failed. Please try again.')]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('portal'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function provider(): AbstractProvider
    {
        /** @var AbstractProvider $provider */
        $provider = Socialite::driver('oidc');

        $provider->redirectUrl(route('oidc.callback'));

        if (config('login.oidc.required_group')) {
            $provider->scopes(['groups']);
        }

        return $provider;
    }
}

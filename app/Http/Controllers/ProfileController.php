<?php

namespace App\Http\Controllers;

use App\Billing\BillingAddress;
use App\Billing\BillingConfig;
use App\Identity\IdentityProvider;
use App\Identity\IdentityProviderException;
use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Name and e-mail address, kept in sync with the identity provider: users manage their account
 * here, the provider is only needed for the login and the passkeys. The billing address stays here.
 */
class ProfileController extends Controller
{
    public const EMAIL_LINK_HOURS = 24;

    public function edit(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('profile', [
            'user' => $user,
            // The address only matters where invoices are sent.
            'billing' => app(BillingConfig::class)->enabled() || $user->billing_city !== null || $user->invoices()->exists(),
            'countries' => BillingAddress::countries(),
        ]);
    }

    /**
     * Billing address for the invoices (only in the AccountManager, not at the provider).
     */
    public function updateBilling(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate(BillingAddress::rules(), [], BillingAddress::attributes());
        $user->update(BillingAddress::normalize($data));

        return redirect()->route('profile')->with('status', __('Your billing address has been saved.'));
    }

    public function update(Request $request, IdentityProvider $provider): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $name = trim($data['name']);
        $email = mb_strtolower(trim($data['email']));

        if ($name !== $user->name) {
            if (! $this->updateProvider($provider, $user, $name)) {
                return back()->withInput()->withErrors(['name' => __('Your profile could not be saved right now. Please try again in a few minutes.')]);
            }

            $user->update(['name' => $name]);
        }

        if ($email === mb_strtolower($user->email)) {
            return redirect()->route('profile')->with('status', __('Your profile has been saved.'));
        }

        // The new address is used only after it is confirmed (same answer whether it is known or not).
        // "from" makes the link useless once the address changed in the meantime.
        $url = URL::temporarySignedRoute('profile.email', now()->addHours(self::EMAIL_LINK_HOURS), [
            'user' => $user->id,
            'email' => $email,
            'from' => mb_strtolower($user->email),
        ]);
        Notification::route('mail', $email)->notify(new ConfirmEmailChange($url, app()->getLocale()));

        return redirect()->route('profile')->with('status', __('We sent a link to :email. Your address changes as soon as you confirm it.', ['email' => $email]));
    }

    /**
     * The link only shows a button: mail scanners that open links must not confirm.
     */
    public function showEmail(Request $request, User $user): View
    {
        return view('profile-email', ['email' => (string) $request->query('email'), 'url' => $request->fullUrl()]);
    }

    public function confirmEmail(Request $request, User $user, IdentityProvider $provider): View|RedirectResponse
    {
        $email = mb_strtolower((string) $request->query('email'));

        $outdated = mb_strtolower($user->email) !== mb_strtolower((string) $request->query('from'));
        $taken = User::query()->whereRaw('LOWER(email) = ?', [$email])->whereKeyNot($user->id)->exists();

        if ($email === '' || $outdated || $taken || ! $this->updateProvider($provider, $user, $user->name, $email)) {
            return view('profile-email-failed');
        }

        $user->forceFill(['email' => $email, 'email_verified_at' => now()])->save();

        return redirect()->route($request->user()?->is($user) ? 'profile' : 'login')->with('status', __('Your email address has been changed to :email.', ['email' => $email]));
    }

    private function updateProvider(IdentityProvider $provider, User $user, string $name, ?string $email = null): bool
    {
        if ($user->oidc_sub === null || ! $provider->manages()) {
            return true;
        }

        try {
            $provider->updateUser($user->oidc_sub, $name, $email);
        } catch (IdentityProviderException $exception) {
            Log::error('Profile update failed at the identity provider', ['exception' => $exception->getMessage()]);

            return false;
        }

        return true;
    }
}

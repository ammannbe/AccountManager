<?php

namespace App\Http\Controllers;

use App\Accounts\Access;
use App\Connectors\Connectors;
use App\Entitlements\Entitlements;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function index(Request $request, Entitlements $entitlements, Connectors $connectors): View
    {
        /** @var User $user */
        $user = $request->user();
        $accounts = $user->serviceAccounts()->with('service')->get()->keyBy('service_id');

        return view('portal', [
            'services' => Service::query()->listed()->get(),
            'accounts' => $accounts,
            'entitlements' => $entitlements->overview($user),
            // Connector specific state, e.g. an open Wizarr invitation.
            'externals' => $accounts->map(fn ($account) => $connectors->status($account))->filter(),
        ]);
    }

    public function claim(Request $request, Service $service, Access $access): RedirectResponse
    {
        abort_unless($service->visible, 404);

        /** @var User $user */
        $user = $request->user();
        $access->claim($user, $service);

        return redirect()->route('portal');
    }
}

<?php

namespace App\Http\Controllers;

use App\Accounts\Access;
use App\Entitlements\Entitlements;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function index(Request $request, Entitlements $entitlements): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('portal', [
            'services' => Service::query()->listed()->get(),
            'accounts' => $user->serviceAccounts()->get()->keyBy('service_id'),
            'entitlements' => $entitlements->overview($user),
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

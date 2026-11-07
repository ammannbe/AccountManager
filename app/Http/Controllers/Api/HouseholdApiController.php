<?php

namespace App\Http\Controllers\Api;

use App\Enums\HouseholdImportStatus;
use App\Households\HouseholdEvents;
use App\Households\HouseholdImports;
use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\HouseholdImport;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Households for the apps (see docs/HOUSEHOLDS-API.md). A service only sees households of which
 * at least one member has an account in it.
 */
class HouseholdApiController extends Controller
{
    /**
     * Households of a user, for the first login and the periodic reconciliation.
     */
    public function index(Request $request): JsonResponse
    {
        $sub = $request->validate(['sub' => ['required', 'string', 'max:255']])['sub'];
        $user = User::query()->where('oidc_sub', $sub)->first();

        if (! $user || ! $this->service($request)->accounts()->where('user_id', $user->id)->exists()) {
            return response()->json(['data' => []]);
        }

        $households = Household::query()->withMember($user)->orderBy('id')->get();

        return response()->json(['data' => $households->map(fn (Household $household): array => $household->snapshot())->values()]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return response()->json(['data' => $this->household($request, $uuid)->snapshot()]);
    }

    /**
     * Replaces what the service shares with the household (for the overview in the portal).
     */
    public function shares(Request $request, string $uuid): JsonResponse
    {
        $household = $this->household($request, $uuid);
        $service = $this->service($request);

        /** @var array{shares: list<array{resource_type: string, resource_id: string, label: string}>} $data */
        $data = $request->validate([
            'shares' => ['present', 'array', 'max:500'],
            'shares.*.resource_type' => ['required', 'string', 'max:50'],
            'shares.*.resource_id' => ['required', 'string', 'max:100'],
            'shares.*.label' => ['required', 'string', 'max:255'],
        ]);
        $shares = collect($data['shares'])->keyBy(fn (array $share): string => $share['resource_type']."\0".$share['resource_id']);

        DB::transaction(function () use ($household, $service, $shares): void {
            $household->shares()->where('service_id', $service->id)->get()
                ->reject(fn ($share): bool => $shares->has($share->resource_type."\0".$share->resource_id))
                ->each->delete();

            foreach ($shares as $share) {
                $household->shares()->updateOrCreate(
                    ['service_id' => $service->id, 'resource_type' => $share['resource_type'], 'resource_id' => $share['resource_id']],
                    ['label' => trim($share['label'])],
                );
            }
        });

        return response()->json(['data' => [
            'shares' => $household->shares()->where('service_id', $service->id)->orderBy('id')->get(['resource_type', 'resource_id', 'label']),
        ]]);
    }

    /**
     * Proposes the local households of the app for the switch to the AccountManager.
     */
    public function import(Request $request, HouseholdImports $imports): JsonResponse
    {
        $data = $request->validate([
            'households' => ['required', 'array', 'min:1', 'max:500'],
            'households.*.local_id' => ['required', 'string', 'max:100', 'distinct'],
            'households.*.name' => ['required', 'string', 'max:100'],
            'households.*.members' => ['required', 'array', 'min:1', 'max:100'],
            'households.*.members.*.sub' => ['required', 'string', 'max:255'],
            'households.*.members.*.role' => ['nullable', 'string', 'in:admin,member'],
        ]);

        $proposals = $imports->propose($this->service($request), $data['households']);

        return response()->json(['data' => $proposals->map(fn (HouseholdImport $import): array => $this->importState($import))->values()], 202);
    }

    /**
     * State of all proposals of the app (polled until every one is resolved).
     */
    public function importStatus(Request $request): JsonResponse
    {
        $proposals = HouseholdImport::query()->where('service_id', $this->service($request)->id)->orderBy('id')->get();

        return response()->json(['data' => $proposals->map(fn (HouseholdImport $import): array => $this->importState($import))->values()]);
    }

    /**
     * @return array{local_id: string, status: string, household_uuid: string|null, household: array<string, mixed>|null}
     */
    private function importState(HouseholdImport $import): array
    {
        $household = $import->status === HouseholdImportStatus::Confirmed ? $import->household : null;

        return [
            'local_id' => $import->local_id,
            'status' => $import->status->value,
            'household_uuid' => $household?->uuid,
            'household' => $household?->snapshot(),
        ];
    }

    private function household(Request $request, string $uuid): Household
    {
        $household = Household::query()->where('uuid', $uuid)->first();

        abort_unless($household && $this->service($request)->accounts()->whereIn('user_id', HouseholdEvents::memberIds($household))->exists(), 404);

        return $household;
    }

    private function service(Request $request): Service
    {
        /** @var Service */
        return $request->attributes->get('service');
    }
}

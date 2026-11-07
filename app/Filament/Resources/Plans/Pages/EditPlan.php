<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Entitlements\Entitlements;
use App\Filament\Resources\Plans\PlanResource;
use App\Models\Plan;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Subscriptions keep their plan; end them first (or turn the plan off).
            DeleteAction::make()->visible(fn (Plan $record): bool => ! $record->subscriptions()->exists()),
        ];
    }

    /**
     * The features may have changed: users with a subscription get their new entitlements.
     */
    protected function afterSave(): void
    {
        /** @var Plan $plan */
        $plan = $this->getRecord();

        app(Entitlements::class)->refreshPlan($plan);
    }
}

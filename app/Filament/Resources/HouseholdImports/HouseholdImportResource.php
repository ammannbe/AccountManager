<?php

namespace App\Filament\Resources\HouseholdImports;

use App\Enums\HouseholdImportStatus;
use App\Filament\Resources\HouseholdImports\Pages\ListHouseholdImports;
use App\Households\HouseholdImports;
use App\Models\Household;
use App\Models\HouseholdImport;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Local households the apps propose when they switch to the AccountManager; nothing is merged
 * without an admin.
 */
class HouseholdImportResource extends Resource
{
    protected static ?string $model = HouseholdImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?int $navigationSort = 4;

    public static function getModelLabel(): string
    {
        return __('Household proposal');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Household proposals');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = HouseholdImport::query()->where('status', HouseholdImportStatus::Pending)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('service.name')->label(__('Service')),
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('known_members')
                    ->label(__('Members'))
                    ->badge()
                    ->state(fn (HouseholdImport $record): array => $record->knownMembers()->map(fn (array $member): string => "{$member['user']->name} ({$member['role']->getLabel()})")->all()),
                TextColumn::make('unknown_members')
                    ->label(__('Unknown members'))
                    ->state(fn (HouseholdImport $record): int => count($record->unknownSubs())),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('household.name')->label(__('Household'))->placeholder('–'),
                TextColumn::make('created_at')->label(__('Created'))->dateTime(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(HouseholdImportStatus::class)->default(HouseholdImportStatus::Pending->value),
            ])
            ->recordActions([
                Action::make('create')
                    ->label(__('Create household'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->requiresConfirmation()
                    ->modalDescription(__('Creates a new household with the proposed members that have an account here.'))
                    ->visible(fn (HouseholdImport $record): bool => self::resolvable($record))
                    ->action(fn (HouseholdImport $record) => app(HouseholdImports::class)->createHousehold($record, self::admin())),
                Action::make('merge')
                    ->label(__('Merge'))
                    ->icon(Heroicon::OutlinedArrowsPointingIn)
                    ->modalDescription(__('Adds the proposed members that have an account here to an existing household; members keep their role there.'))
                    ->visible(fn (HouseholdImport $record): bool => self::resolvable($record))
                    ->fillForm(fn (HouseholdImport $record): array => ['household_id' => $record->suggestedHousehold()?->getKey()])
                    ->schema([
                        Select::make('household_id')
                            ->label(__('Household'))
                            ->options(fn (): array => Household::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(fn (HouseholdImport $record, array $data) => app(HouseholdImports::class)->mergeInto($record, Household::query()->whereKey((int) $data['household_id'])->firstOrFail(), self::admin())),
                Action::make('reject')
                    ->label(__('Reject'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (HouseholdImport $record): bool => $record->status === HouseholdImportStatus::Pending)
                    ->action(fn (HouseholdImport $record) => app(HouseholdImports::class)->reject($record, self::admin())),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHouseholdImports::route('/'),
        ];
    }

    private static function resolvable(HouseholdImport $record): bool
    {
        return $record->status === HouseholdImportStatus::Pending && $record->knownMembers()->isNotEmpty();
    }

    private static function admin(): User
    {
        /** @var User */
        return Auth::user();
    }
}

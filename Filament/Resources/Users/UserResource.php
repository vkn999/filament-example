<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'Управление';

    protected static ?string $navigationLabel = 'Пользователи';

    protected static ?string $modelLabel = 'Пользователь';

    protected static ?string $pluralModelLabel = 'Пользователи';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основная информация')
                    ->schema([
                        TextInput::make('name')
                            ->label('Имя')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->label('Пароль')
                            ->password()
                            ->required(fn ($record): bool => $record === null)
                            ->minLength(8)
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->helperText('Минимум 8 символов'),
                        TextInput::make('password_confirmation')
                            ->label('Подтвердите пароль')
                            ->password()
                            ->required(fn ($record): bool => $record === null)
                            ->same('password')
                            ->dehydrated(false)
                            ->visible(fn ($record): bool => $record === null),
                    ])->columns(2),

                Section::make('Роли и разрешения')
                    ->schema([
                        Select::make('roles')
                            ->label('Роли')
                            ->multiple()
                            ->relationship('roles', 'name')
                            ->options(function () {
                                // Только Super Admin может назначать роли
                                $user = auth()->user();
                                if ($user?->hasRole('Super Admin')) {
                                    return Role::all()->pluck('name', 'id');
                                }

                                // Остальные пользователи не могут изменять роли
                                return [];
                            })
                            ->preload()
                            ->searchable()
                            ->helperText(function (): string {
                                $user = auth()->user();
                                if (! ($user?->hasRole('Super Admin') ?? false)) {
                                    return 'Только Super Admin может назначать роли пользователям.';
                                }

                                return 'Выберите одну или несколько ролей для пользователя.';
                            })
                            ->disabled(fn (): bool => ! (auth()->user()?->hasRole('Super Admin') ?? false)),

                        Placeholder::make('current_roles')
                            ->label('Текущие роли')
                            ->content(fn ($record) => $record ? $record->roles->pluck('name')->join(', ') : 'Роли не назначены')
                            ->visible(fn ($record): bool => $record && ! (auth()->user()?->hasRole('Super Admin') ?? false)),
                    ])->columns(1),

                Section::make('Дополнительная информация')
                    ->schema([
                        Placeholder::make('created_at')
                            ->label('Создан')
                            ->content(fn ($record) => $record?->created_at?->format('d.m.Y H:i')),
                        Placeholder::make('updated_at')
                            ->label('Обновлен')
                            ->content(fn ($record) => $record?->updated_at?->format('d.m.Y H:i')),
                    ])
                    ->columns(2)
                    ->visible(fn ($record): bool => $record !== null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label('Роли')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Super Admin' => 'danger',
                        'Site Admin' => 'warning',
                        'Content Manager' => 'success',
                        'Author' => 'info',
                        'Viewer' => 'gray',
                        default => 'primary',
                    })
                    ->separator(', '),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Обновлен')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->label('Роль')
                    ->relationship('roles', 'name')
                    ->multiple(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn () => auth()->user()?->can('view_users') ?? false),
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit_users') ?? false),
                DeleteAction::make()
                    ->visible(fn ($record): bool => (
                        (auth()->user()?->can('delete_users') ?? false)
                        && (auth()->id() !== $record->id)
                        && ((! $record->hasRole(' Super Admin')) || auth()->user()->hasRole(' Super Admin'))
                    ))
                    ->requiresConfirmation()
                    ->modalHeading('Удаление пользователя')
                    ->modalDescription('Вы уверены, что хотите удалить этого пользователя? Это действие нельзя отменить.')
                    ->modalSubmitActionLabel('Да, удалить'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->hasRole('Super Admin') ?? false)
                        ->requiresConfirmation()
                        ->modalHeading('Удаление пользователей')
                        ->modalDescription('Вы уверены, что хотите удалить выбранных пользователей? Это действие нельзя отменить.')
                        ->modalSubmitActionLabel('Да, удалить'),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    // Ограничения доступа
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('view_users');
    }

    public static function canCreate(): bool
    {
        // Только Super Admin может создавать пользователей
        $user = auth()->user();

        return $user instanceof User && $user->hasRole('Super Admin') && $user->can('create_users');
    }

    public static function canView($record): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('view_users');
    }

    public static function canEdit($record): bool
    {
        // Super Admin может редактировать всех, остальные - только себя
        $user = auth()->user();

        return $user instanceof User && $user->can('edit_users') &&
            ($user->hasRole('Super Admin') || (auth()->id() === $record->id));
    }

    public static function canDelete($record): bool
    {
        $user = auth()->user();

        if (! ($user instanceof User)) {
            return false;
        }

        // Нельзя удалить самого себя
        if ($user->id === $record->id) {
            return false;
        }

        // Только Super Admin может удалять пользователей
        if (! $user->hasRole('Super Admin')) {
            return false;
        }

        return $user->can('delete_users');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'primary';
    }
}

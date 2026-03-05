<?php

namespace App\Filament\Resources\MenuItems;

use App\Filament\Resources\MenuItems\Pages\CreateMenuItem;
use App\Filament\Resources\MenuItems\Pages\EditMenuItem;
use App\Filament\Resources\MenuItems\Pages\ListMenuItems;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Post;
use App\Models\Site;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class MenuItemResource extends Resource
{
    protected static ?string $model = MenuItem::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bars-3';

    protected static ?string $navigationLabel = 'Главное меню';

    protected static null|string|\UnitEnum $navigationGroup = 'Меню';

    protected static ?string $modelLabel = 'Пункт меню';

    protected static ?string $pluralModelLabel = 'Пункты меню';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('location')->default('main'),
                Select::make('site_id')
                    ->label('Сайт')
                    ->options(Site::pluck('name', 'id'))
                    ->required()
                    ->reactive(),

                Select::make('parent_id')
                    ->label('Родительский пункт')
                    ->options(function (callable $get) {
                        $siteId = $get('site_id');
                        if (! $siteId) {
                            return [];
                        }

                        return MenuItem::where('site_id', $siteId)
                            ->where('location', 'main')
                            ->whereNull('parent_id')
                            ->pluck('title', 'id');
                    })
                    ->placeholder('Корневой элемент'),

                TextInput::make('title')
                    ->label('Заголовок')
                    ->required()
                    ->maxLength(255)
                    ->rules(fn (callable $get, $record): array => [
                        'required',
                        'max:255',
                        Rule::unique('menu_items', 'title')
                            ->where('site_id', $get('site_id'))
                            ->where('location', 'main')
                            ->ignore($record?->id),
                    ])
                    ->validationMessages([
                        'unique' => 'Пункт меню с таким заголовком уже существует на этом сайте.',
                    ]),

                Select::make('type')
                    ->label('Тип')
                    ->options([
                        'page' => 'Страница',
                        'post' => 'Запись',
                        'custom' => 'Произвольная ссылка',
                        'external' => 'Внешняя ссылка',
                    ])
                    ->required()
                    ->reactive(),

                Select::make('page_id')
                    ->label('Страница')
                    ->options(function (callable $get) {
                        $siteId = $get('site_id');
                        if (! $siteId) {
                            return [];
                        }

                        return Page::where('site_id', $siteId)
                            ->where('is_publish', true)
                            ->pluck('title', 'id');
                    })
                    ->visible(fn (callable $get): bool => $get('type') === 'page')
                    ->required(fn (callable $get): bool => $get('type') === 'page'),

                Select::make('post_id')
                    ->label('Запись')
                    ->options(function (callable $get) {
                        $siteId = $get('site_id');
                        if (! $siteId) {
                            return [];
                        }

                        return Post::where('site_id', $siteId)
                            ->where('is_publish', true)
                            ->pluck('title', 'id');
                    })
                    ->visible(fn (callable $get): bool => $get('type') === 'post')
                    ->required(fn (callable $get): bool => $get('type') === 'post'),

                TextInput::make('url')
                    ->label('URL')
                    ->url()
                    ->visible(fn (callable $get): bool => in_array($get('type'), ['custom', 'external']))
                    ->required(fn (callable $get): bool => in_array($get('type'), ['custom', 'external']))
                    ->rules(function (callable $get, $record): array {
                        $rules = [];
                        if (in_array($get('type'), ['custom', 'external'])) {
                            $rules[] = 'required';
                            $rules[] = Rule::unique('menu_items', 'url')
                                ->where('site_id', $get('site_id'))
                                ->where('location', 'main')
                                ->whereNotNull('url')
                                ->ignore($record?->id);
                        }

                        return $rules;
                    })
                    ->validationMessages([
                        'unique' => 'Пункт меню с таким URL уже существует на этом сайте.',
                    ]),

                TextInput::make('sort_order')
                    ->label('Порядок сортировки')
                    ->numeric()
                    ->default(0)
                    ->validationMessages([
                        'numeric' => 'Порядок сортировки должен быть числом.',
                    ]),

                Toggle::make('is_active')
                    ->label('Активен')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('site.name')
                    ->label('Сайт')
                    ->sortable(),

                TextColumn::make('title')
                    ->label('Заголовок')
                    ->searchable(),

                BadgeColumn::make('type')
                    ->label('Тип')
                    ->colors([
                        'primary' => 'page',
                        'secondary' => 'post',
                        'success' => 'custom',
                        'warning' => 'external',
                    ]),

                /*Tables\Columns\TextColumn::make('page.title')
                    ->label('Статья')
                    ->limit(50),

                Tables\Columns\TextColumn::make('post.title')
                    ->label('Запись')
                    ->limit(50),*/

                TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('site_id')
                    ->label('Сайт')
                    ->options(Site::pluck('name', 'id')),

                SelectFilter::make('type')
                    ->label('Тип')
                    ->options([
                        'page' => 'Страница',
                        'post' => 'Запись',
                        'custom' => 'Произвольная ссылка',
                        'external' => 'Внешняя ссылка',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->label('Удалить')
                    ->requiresConfirmation()
                    ->modalHeading('Подтверждение удаления')
                    ->modalDescription('Вы уверены, что хотите удалить этот пункт меню?')
                    ->modalSubmitActionLabel('Удалить')
                    ->modalCancelActionLabel('Отмена'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Удалить выбранные')
                        ->requiresConfirmation()
                        ->modalHeading('Подтверждение удаления')
                        ->modalDescription('Вы уверены, что хотите удалить выбранные пункты меню?')
                        ->modalSubmitActionLabel('Удалить')
                        ->modalCancelActionLabel('Отмена'),
                ]),
            ])
            ->defaultSort('site_id')
            ->defaultSort('sort_order');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('location', 'main');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMenuItems::route('/'),
            'create' => CreateMenuItem::route('/create'),
            'edit' => EditMenuItem::route('/{record}/edit'),
        ];
    }
}

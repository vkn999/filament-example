<?php

namespace App\Filament\Resources\Widgets;

use App\Filament\Resources\Widgets\Pages\CreateWidget;
use App\Filament\Resources\Widgets\Pages\EditWidget;
use App\Filament\Resources\Widgets\Pages\ListWidgets;
use App\Models\Site;
use App\Models\Widget;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language as CodeLanguage;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class WidgetResource extends Resource
{
    protected static ?string $model = Widget::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static null|string|\UnitEnum $navigationGroup = 'Контент';

    protected static ?string $navigationLabel = 'Виджеты';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make()->columns(3)->schema([
                Select::make('site_id')->label('Сайт')->options(Site::pluck('name', 'id'))->required()->reactive(),
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255)
                    ->reactive()
                    ->live(debounce: 750)
                    ->afterStateUpdated(function (callable $set, callable $get, $state): void {
                        // Авто-генерация слага из названия, пока слаг не отредактирован вручную
                        if (! $get('slug_locked')) {
                            $set('slug', Str::slug((string) $state, '-', 'ru'));
                        }
                    }),
                TextInput::make('slug')
                    ->label('Слаг')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->minLength(2)
                    ->maxLength(255)
                    ->helperText('Автозаполняется из названия, можно отредактировать вручную.')
                    ->reactive()
                    ->afterStateUpdated(function (callable $set, callable $get, $state): void {
                        // Если слаг отличается от авто-сгенерированного из названия — считаем, что пользователь его зафиксировал
                        $generated = Str::slug((string) ($get('name') ?? ''), '-', 'ru');
                        $set('slug_locked', $state !== $generated);
                    }),
                // Техническое поле для слежения, редактировался ли слаг вручную
                Hidden::make('slug_locked')->default(false)->dehydrated(false),
            ]),

            Grid::make()->columns(4)->schema([
                Select::make('placement')->label('Расположение')->options([
                    'right_sidebar' => 'Правый сайдбар',
                    'left_sidebar' => 'Левый сайдбар',
                    'before_content' => 'Перед контентом',
                    'home_between_blocks' => 'На главной между блоками',
                    'after_content' => 'После контента',
                ])->required()->reactive()
                    ->afterStateUpdated(function (callable $set, callable $get, $state): void {
                        // При выборе "на главной между блоками" скрываем страничные настройки и принудительно включаем показ на главной
                        if ($state === 'home_between_blocks') {
                            // Очистим выбранные страницы/посты, чтобы не сохранялись лишние связи
                            $set('pages', []);
                            $set('posts', []);
                            // Принудительно включим показ на главной
                            $set('show_on_home', true);
                        }
                    }),
                Select::make('content_type')->label('Тип содержимого')->options([
                    'iframe' => 'Iframe',
                    'html' => 'HTML',
                    'javascript' => 'JavaScript',
                    'url' => 'URL',
                ])->required()->reactive(),
                TextInput::make('sort_order')->numeric()->default(0)->label('Порядок'),
                Toggle::make('is_active')->label('Активен')->default(true),
            ]),

            CodeEditor::make('code')->label('Код (HTML/JS/Iframe)')
                ->language(fn (callable $get): \Filament\Forms\Components\CodeEditor\Enums\Language => ($get('content_type') === 'javascript')
                    ? CodeLanguage::JavaScript
                    : CodeLanguage::Html)
                ->visible(fn (callable $get): bool => in_array($get('content_type'), ['iframe', 'html', 'javascript']))
                ->helperText('Вставьте код виджета: iframe, HTML или JS.'),

            TextInput::make('url')->label('URL виджета')
                ->visible(fn (callable $get): bool => $get('content_type') === 'url')
                ->maxLength(255),

            Toggle::make('show_on_home')
                ->label('Показывать на главной')
                ->visible(fn (callable $get): bool => $get('placement') !== 'home_between_blocks'),

            Section::make('Настройки размещения на главной')
                ->collapsible()
                ->visible(fn (callable $get): bool => $get('placement') === 'home_between_blocks')
                ->schema([
                    Group::make()->statePath('settings')->schema([
                        TextInput::make('home_between.after_block')
                            ->label('После какого по счёту блока на главной вывести виджет')
                            ->numeric()
                            ->minValue(0)
                            ->default(1)
                            ->helperText('0 — перед первым блоком; 1 — после первого; 2 — после второго и т.д.'),
                    ]),
                ]),

            Select::make('pages')->label('Показывать на страницах')
                ->relationship(
                    name: 'pages',
                    titleAttribute: 'title',
                    modifyQueryUsing: fn ($query) => $query->select('pages.id', 'pages.title')
                        ->distinct()
                        ->orderBy('pages.title')
                )
                ->multiple()->preload()->searchable()
                ->visible(fn (callable $get): bool => $get('placement') !== 'home_between_blocks')
                ->dehydrated(fn (callable $get): bool => $get('placement') !== 'home_between_blocks'),

            Select::make('posts')->label('Показывать на записях')
                ->relationship(
                    name: 'posts',
                    titleAttribute: 'title',
                    modifyQueryUsing: fn ($query) => $query->select('posts.id', 'posts.title')
                        ->distinct()
                        ->orderBy('posts.title')
                )
                ->multiple()->preload()->searchable()
                ->visible(fn (callable $get): bool => $get('placement') !== 'home_between_blocks')
                ->dehydrated(fn (callable $get): bool => $get('placement') !== 'home_between_blocks'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->sortable(),
            TextColumn::make('name')->label('Название')->searchable(),
            TextColumn::make('site.name')->label('Сайт')->sortable(),
            TextColumn::make('placement')->label('Место')->badge(),
            IconColumn::make('show_on_home')->label('На главной')->boolean(),
            IconColumn::make('is_active')->label('Активен')->boolean(),
            TextColumn::make('sort_order')->label('Порядок')->sortable(),
        ])->filters([
            SelectFilter::make('site_id')->label('Сайт')->options(Site::pluck('name', 'id')),
            SelectFilter::make('placement')->label('Место')->options([
                'right_sidebar' => 'Правый сайдбар',
                'left_sidebar' => 'Левый сайдбар',
                'before_content' => 'Перед контентом',
                'home_between_blocks' => 'На главной между блоками',
                'after_content' => 'После контента',
            ]),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make(),
        ])->toolbarActions([
            BulkActionGroup::make([
                DeleteBulkAction::make(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWidgets::route('/'),
            'create' => CreateWidget::route('/create'),
            'edit' => EditWidget::route('/{record}/edit'),
        ];
    }
}

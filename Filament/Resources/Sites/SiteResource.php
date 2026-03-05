<?php

namespace App\Filament\Resources\Sites;

use App\Filament\Resources\Sites\Pages\CreateSite;
use App\Filament\Resources\Sites\Pages\EditSite;
use App\Filament\Resources\Sites\Pages\ListSites;
use App\Models\Site;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Сайты';

    protected static ?string $modelLabel = 'сайт';

    protected static ?string $pluralModelLabel = 'сайты';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основная информация')
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('name_ru')
                            ->label('Название на русском')
                            ->maxLength(255)
                            ->helperText('Отображается на сайте вместо технического названия'),

                        TextInput::make('domain')
                            ->label('Домен')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->rules([
                                'regex:/^([a-zA-Z0-9][a-zA-Z0-9-_]*\.)*[a-zA-Z0-9]*[a-zA-Z0-9-_]*[[a-zA-Z0-9]+$/',
                            ])
                            ->helperText('Введите домен в формате: example.com'),

                        /*Textarea::make('description')
                            ->label('Описание')
                            ->maxLength(400)
                            ->rows(3),*/

                        Toggle::make('is_active')
                            ->label('Активен')
                            ->default(true),
                    ])->columns(2),

                Section::make('Hero-секция')
                    ->description('Текст, отображаемый в главном баннере на главной странице')
                    ->schema([
                        TextInput::make('hero_title')
                            ->label('Заголовок')
                            ->maxLength(255)
                            ->helperText('Крупный заголовок в центре баннера'),

                        TextInput::make('hero_subtitle')
                            ->label('Подзаголовок')
                            ->maxLength(255)
                            ->helperText('Подзаголовок под основным заголовком'),

                        Textarea::make('hero_description')
                            ->label('Описание')
                            ->rows(3)
                            ->helperText('Текст описания под заголовком'),
                    ])->columns(1)->collapsible(),

                Section::make('API настройки')
                    ->schema([
                        TextInput::make('api_key')
                            ->label('API ключ')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('API ключ генерируется автоматически при создании сайта')
                            ->visible(fn ($record): bool => $record !== null),

                        TextInput::make('rate_limit')
                            ->label('Лимит запросов в минуту')
                            ->numeric()
                            ->default(60)
                            ->minValue(1)
                            ->maxValue(100000)
                            ->helperText('Максимальное количество API запросов в минуту'),
                    ])->columns(2)->visible(fn ($record): bool => $record !== null),

                Section::make('SEO настройки')
                    ->schema([
                        Forms\Components\TextInput::make('meta_title')
                            ->label('Meta Title')
                            ->maxLength(255)
                            ->helperText('Рекомендуется до 60 символов'),

                        Forms\Components\Textarea::make('meta_description')
                            ->label('Meta Description')
                            ->maxLength(400)
                            ->rows(3)
                            ->helperText('Рекомендуется до 160 символов'),

                        Forms\Components\TextInput::make('meta_keywords')
                            ->label('Meta Keywords')
                            ->helperText('Ключевые слова через запятую'),
                    ])->columns(1)->collapsible(),

                Section::make('Favicon и иконки')
                    ->description('Загрузите favicon в различных форматах для лучшей совместимости')
                    ->schema([
                        Forms\Components\FileUpload::make('favicon_ico')
                            ->label('Favicon ICO')
                            ->image()
                            ->directory('sites/favicons')
                            ->visibility('public')
                            ->acceptedFileTypes(['image/x-icon', 'image/vnd.microsoft.icon'])
                            ->maxSize(512)
                            ->helperText('favicon.ico (до 512 КБ)'),

                        Forms\Components\FileUpload::make('favicon_png')
                            ->label('Favicon PNG')
                            ->image()
                            ->directory('sites/favicons')
                            ->visibility('public')
                            ->acceptedFileTypes(['image/png'])
                            ->maxSize(512)
                            ->helperText('favicon.png (до 512 КБ)'),

                        Forms\Components\FileUpload::make('favicon_svg')
                            ->label('Favicon SVG')
                            ->image()
                            ->directory('sites/favicons')
                            ->visibility('public')
                            ->acceptedFileTypes(['image/svg+xml'])
                            ->maxSize(256)
                            ->helperText('favicon.svg (до 256 КБ)'),

                        Forms\Components\FileUpload::make('apple_touch_icon')
                            ->label('Apple Touch Icon')
                            ->image()
                            ->directory('sites/favicons')
                            ->visibility('public')
                            ->acceptedFileTypes(['image/png'])
                            ->maxSize(512)
                            ->helperText('apple-touch-icon.png (180x180, до 512 КБ)'),

                        Forms\Components\FileUpload::make('android_chrome_192')
                            ->label('Android Chrome 192x192')
                            ->image()
                            ->directory('sites/favicons')
                            ->visibility('public')
                            ->acceptedFileTypes(['image/png'])
                            ->maxSize(512)
                            ->helperText('android-chrome-192x192.png'),

                        Forms\Components\FileUpload::make('android_chrome_512')
                            ->label('Android Chrome 512x512')
                            ->image()
                            ->directory('sites/favicons')
                            ->visibility('public')
                            ->acceptedFileTypes(['image/png'])
                            ->maxSize(1024)
                            ->helperText('android-chrome-512x512.png'),

                        Forms\Components\FileUpload::make('safari_pinned_tab')
                            ->label('Safari Pinned Tab')
                            ->image()
                            ->directory('sites/favicons')
                            ->visibility('public')
                            ->acceptedFileTypes(['image/svg+xml'])
                            ->maxSize(256)
                            ->helperText('safari-pinned-tab.svg (монохромный)'),
                    ])->columns(3)->collapsible(),

                Section::make('Фоновые изображения')
                    ->description('Изображения для главного экрана')
                    ->schema([
                        Forms\Components\FileUpload::make('background_image')
                            ->label('Основной фон')
                            ->image()
                            ->directory('sites/backgrounds')
                            ->visibility('public')
                            ->maxSize(2048)
                            ->helperText('Основное фоновое изображение (до 2 МБ)'),

                        Forms\Components\FileUpload::make('background_image_sizes')
                            ->label('Варианты фона')
                            ->multiple()
                            ->reorderable()
                            ->schema([
                                Forms\Components\TextInput::make('mobile')
                                    ->label('Mobile')
                                    ->disabled()
                                    ->default('background-mobile.webp'),
                                Forms\Components\TextInput::make('tablet')
                                    ->label('Tablet')
                                    ->disabled()
                                    ->default('background-tablet.webp'),
                                Forms\Components\TextInput::make('desktop')
                                    ->label('Desktop')
                                    ->disabled()
                                    ->default('background.webp'),
                            ])
                            ->hidden()
                            ->helperText('Автоматически генерируется из основного фона'),
                    ])->columns(1)->collapsible(),

                Section::make('Яндекс.Метрика')
                    ->schema([
                        Forms\Components\TextInput::make('yandex_metrica_id')
                            ->label('Счётчик Яндекс.Метрики')
                            ->numeric()
                            ->helperText('Номер счётчика из Яндекс.Метрики'),
                    ])->columns(1)->collapsible(),

                Section::make('Настройки оформления')
                    ->schema([
                        Forms\Components\ColorPicker::make('theme_color')
                            ->label('Цвет темы')
                            ->default('#ffffff')
                            ->helperText('Для meta tag theme-color'),

                        Forms\Components\FileUpload::make('logo')
                            ->label('Логотип')
                            ->image()
                            ->directory('sites/logos')
                            ->visibility('public')
                            ->maxSize(1024)
                            ->helperText('Логотип для шапки сайта'),

                        Forms\Components\Textarea::make('footer_text')
                            ->label('Текст в футере')
                            ->rows(2)
                            ->maxLength(500)
                            ->helperText('Текст копирайта или дополнительная информация'),
                    ])->columns(3)->collapsible(),

                Section::make('Страницы')
                    ->description('Ссылки на юридические страницы')
                    ->schema([
                        Forms\Components\Select::make('privacy_page_id')
                            ->label('Страница политики конфиденциальности')
                            ->relationship('privacyPage', 'title', fn ($query) => $query->where('site_id', $record?->id ?? null))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->nullable(),

                        Forms\Components\Select::make('terms_page_id')
                            ->label('Страница условий использования')
                            ->relationship('termsPage', 'title', fn ($query) => $query->where('site_id', $record?->id ?? null))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->nullable(),
                    ])->columns(2)->collapsible(),

                Section::make('Дополнительные настройки')
                    ->schema([
                        Repeater::make('custom_settings')
                            ->label('Пользовательские настройки')
                            ->schema([
                                TextInput::make('key')
                                    ->label('Ключ')
                                    ->required()
                                    ->maxLength(255),

                                Textarea::make('value')
                                    ->label('Значение')
                                    ->rows(2),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->collapsible()
                            ->afterStateUpdated(function ($state, $record): void {
                                if ($record && is_array($state)) {
                                    foreach ($state as $setting) {
                                        if (is_array($setting) && isset($setting['key']) && is_string($setting['key'])) {
                                            $record->setSetting($setting['key'], (string) ($setting['value'] ?? ''));
                                        }
                                    }
                                }
                            })
                            ->dehydrated(false)
                            ->default(function ($record) {
                                if (! $record) {
                                    return [];
                                }

                                return $record->settings()
                                    ->whereNotIn('key', ['logo', 'favicon', 'theme_color', 'contact_email'])
                                    ->get()
                                    ->map(fn ($setting): array => [
                                        'key' => $setting->key,
                                        'value' => $setting->value,
                                    ])
                                    ->toArray();
                            }),
                    ])->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('domain')
                    ->label('Домен')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Домен скопирован!')
                    ->copyMessageDuration(1500),

                ImageColumn::make('logo_display')
                    ->label('Логотип')
                    ->getStateUsing(fn ($record) => $record->logo ?: $record->getSetting('logo'))
                    ->size(40)
                    ->toggleable(),

                ImageColumn::make('favicon_display')
                    ->label('Favicon')
                    ->getStateUsing(fn ($record) => $record->favicon_png ?? $record->favicon_svg ?? $record->favicon_ico)
                    ->size(40)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('yandex_metrica_id')
                    ->label('Я.Метрика')
                    ->toggleable(isToggledHiddenByDefault: true),

                ColorColumn::make('theme_color_display')
                    ->label('Цвет темы')
                    ->getStateUsing(fn ($record) => $record->theme_color ?: $record->getSetting('theme_color', '#000000'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('api_key')
                    ->label('API ключ')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('API ключ скопирован!')
                    ->copyMessageDuration(1500)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('rate_limit')
                    ->label('Лимит/мин')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),

                TextColumn::make('pages_count')
                    ->label('Страниц')
                    ->counts('pages')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Статус активности'),
            ])
            ->recordActions([
                EditAction::make(),

                DeleteAction::make()
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSites::route('/'),
            'create' => CreateSite::route('/create'),
            'edit' => EditSite::route('/{record}/edit'),
        ];
    }
}

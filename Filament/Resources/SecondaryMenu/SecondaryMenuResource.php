<?php

namespace App\Filament\Resources\SecondaryMenu;

use App\Filament\Resources\SecondaryMenu\Pages\CreateSecondaryMenu;
use App\Filament\Resources\SecondaryMenu\Pages\EditSecondaryMenu;
use App\Filament\Resources\SecondaryMenu\Pages\ListSecondaryMenu;
use App\Models\Page;
use App\Models\Post;
use App\Models\SecondaryMenu;
use App\Models\Site;
use App\Services\PreviewImageService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class SecondaryMenuResource extends Resource
{
    protected static ?string $model = SecondaryMenu::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static null|string|\UnitEnum $navigationGroup = 'Меню';

    protected static ?string $navigationLabel = 'Второе меню';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make()->columns(2)->schema([
                Select::make('site_id')
                    ->label('Сайт')
                    ->options(Site::pluck('name', 'id'))
                    ->required()
                    // Исключаем мгновенную валидацию при каждом изменении состояния
                    // и обновляем зависимые поля только после выхода из поля
                    ->live(onBlur: true),

                TextInput::make('title')
                    ->label('Заголовок')
                    ->required()
                    ->maxLength(255)
                    // Валидация только по blur/submit, чтобы выбор изображения не подсвечивал ошибки
                    ->live(onBlur: true),
            ]),

            Grid::make()->columns(3)->schema([
                FileUpload::make('image_path')
                    ->label('Изображение')
                    ->image()
                    ->disk('public')
                    ->directory('secondary-menus/images/'.now()->format('Y/m'))
                    ->helperText('Загрузите изображение через медиагалерею или напрямую. При загрузке выполняется конвертация и транслитерация имени файла.')
                    ->saveUploadedFileUsing(function (TemporaryUploadedFile $temporaryUploadedFile, callable $set) {
                        $previewImageService = app(PreviewImageService::class);
                        $paths = $previewImageService->processPreviewImage(
                            $temporaryUploadedFile,
                            'secondary-menus/images'
                        );

                        // сохраняем набор размеров и сбрасываем связь с медиагалереей
                        $set('image_path_sizes', $paths);
                        $set('image_media_id', null);

                        return $paths['preview'] ?? null;
                    }),

                TextInput::make('image_alt')
                    ->label('Alt для изображения')
                    ->maxLength(255),

                Hidden::make('image_path_sizes'),
                Hidden::make('image_media_id'),

                ViewField::make('secondary_menu_media_picker')
                    ->view('filament.forms.components.secondary-menu-media-button')
                    // Не позволяем Livewire отслеживать внутренние изменения этого view,
                    // чтобы не триггерить пересборку формы и «красные» ошибки до сохранения
                    ->extraAttributes(['wire:ignore'])
                    ->columnSpanFull(),

                TextInput::make('sort_order')->numeric()->default(0)->label('Порядок'),

                Toggle::make('is_active')->label('Активно')->default(true),
            ]),

            Grid::make()->columns(3)->schema([
                Select::make('type')
                    ->label('Тип ссылки')
                    ->options([
                        'page' => 'Страница',
                        'post' => 'Запись',
                        'custom' => 'Произвольная',
                        'external' => 'Внешняя',
                    ])
                    // Безопасное значение по умолчанию, чтобы при выборе изображения
                    // не срабатывала обязательность поля до явного выбора пользователем
                    ->default('custom')
                    ->required()
                    // Меняем тип только по blur, чтобы не запускать валидацию моментально
                    ->live(onBlur: true),

                Select::make('page_id')
                    ->label('Страница')
                    ->options(fn (callable $get) => $get('site_id') ? Page::where('site_id', $get('site_id'))->pluck('title', 'id') : [])
                    ->visible(fn (callable $get): bool => $get('type') === 'page')
                    ->required(fn (callable $get): bool => $get('type') === 'page')
                    ->live(onBlur: true),

                Select::make('post_id')
                    ->label('Запись')
                    ->options(fn (callable $get) => $get('site_id') ? Post::where('site_id', $get('site_id'))->pluck('title', 'id') : [])
                    ->visible(fn (callable $get): bool => $get('type') === 'post')
                    ->required(fn (callable $get): bool => $get('type') === 'post')
                    ->live(onBlur: true),
            ]),

            TextInput::make('url')
                ->label('URL')
                ->visible(fn (callable $get): bool => in_array($get('type'), ['custom', 'external']))
                ->maxLength(255)
                ->live(onBlur: true),

            Toggle::make('show_on_home')->label('Показывать на главной')->default(true),

            Select::make('pages')
                ->label('Показывать на страницах')
                ->relationship(
                    name: 'pages',
                    titleAttribute: 'title',
                    modifyQueryUsing: // В PostgreSQL DISTINCT по pages.* ломается, если есть JSON поля.
                        // Ограничиваем выборку безопасными колонками, добавляем distinct по id.

                        fn ($query) => $query->select('pages.id', 'pages.title')
                            ->distinct()
                            ->orderBy('pages.title')
                )
                ->multiple()
                ->preload()
                ->searchable(),

            Select::make('posts')
                ->label('Показывать на записях')
                ->relationship(
                    name: 'posts',
                    titleAttribute: 'title',
                    modifyQueryUsing: fn ($query) => $query->select('posts.id', 'posts.title')
                        ->distinct()
                        ->orderBy('posts.title')
                )
                ->multiple()
                ->preload()
                ->searchable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('title')->label('Название')->searchable(),
                TextColumn::make('site.name')->label('Сайт')->sortable(),
                TextColumn::make('type')->label('Тип')->badge(),
                IconColumn::make('show_on_home')->label('На главной')->boolean(),
                IconColumn::make('is_active')->label('Активно')->boolean(),
                TextColumn::make('sort_order')->label('Порядок')->sortable(),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('Сайт')->options(Site::pluck('name', 'id')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSecondaryMenu::route('/'),
            'create' => CreateSecondaryMenu::route('/create'),
            'edit' => EditSecondaryMenu::route('/{record}/edit'),
        ];
    }
}

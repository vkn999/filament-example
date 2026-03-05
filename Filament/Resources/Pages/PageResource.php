<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Forms\RichContent\Plugins\LinkButtonRichContentPlugin;
use App\Filament\Forms\RichContent\Plugins\MediaGalleryRichContentPlugin;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\Pages\Pages\ViewRevisions;
use App\Models\Page;
use App\Models\Site;
use App\Models\User;
use App\Services\ContentImageService;
use App\Services\PreviewImageService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Страницы';

    protected static ?string $modelLabel = 'Страница';

    protected static ?string $pluralModelLabel = 'Страницы';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Group::make()
                    ->schema([
                        Section::make('Основная информация')
                            ->schema([
                                Select::make('site_id')
                                    ->label('Сайт')
                                    ->options(Site::all()->pluck('name', 'id'))
                                    ->required()
                                    ->searchable(),

                                TextInput::make('title')
                                    ->label('Заголовок')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (string $state, $set, $get, ?Page $record): void {
                                        // Получаем текущее значение slug в форме
                                        $currentSlug = $get('slug');

                                        // Пропускаем автогенерацию если slug уже установлен и не пустой
                                        // Это означает что пользователь либо вручную отредактировал slug,
                                        // либо это существующая запись с сохраненным slug
                                        if (! empty($currentSlug) && $record && ! empty($record->slug)) {
                                            // Для существующих записей не трогаем slug
                                            return;
                                        }

                                        // Создаем временный объект Page для генерации slug
                                        $page = new Page;
                                        $page->site_id = ! $record || empty($record->slug) ? 1 : $record->site_id;

                                        // Генерируем slug из нового заголовка
                                        $generatedSlug = $page->generateSlug($state);

                                        // Автоматически устанавливаем slug только если он пустой
                                        // или если текущий slug точно совпадает с тем, что был бы сгенерирован
                                        // (это означает что пользователь не редактировал slug вручную)
                                        if (empty($currentSlug) || $currentSlug === $generatedSlug) {
                                            $set('slug', $generatedSlug);
                                        }
                                    }),

                                TextInput::make('slug')
                                    ->label('URL (slug)')
                                    ->required()
                                    ->maxLength(255)
                                    ->rules(['regex:/^(\/|[A-Za-z0-9_-]+)$/'])
                                    ->unique(
                                        table: Page::class,
                                        column: 'slug',
                                        ignoreRecord: true,
                                        modifyRuleUsing: fn ($get, $rule) => $rule->where('site_id', $get('site_id'))
                                    )
                                    ->helperText('URL адрес. Разрешены латинские буквы, цифры, дефисы и подчеркивания, либо одиночный слэш "/" для главной страницы.')
                                    ->suffixIcon('heroicon-m-link'),

                                Select::make('author_id')
                                    ->label('Автор')
                                    ->options(User::all()->pluck('name', 'id'))
                                    ->default(fn () => Auth::id())
                                    ->required()
                                    ->searchable()
                                    ->helperText('Выберите автора'),

                                RichEditor::make('content')
                                    ->floatingToolbars([
                                        'paragraph' => [
                                            'bold', 'italic', 'underline', 'strike', 'subscript', 'superscript', 'link', 'mediaGalleryButton', 'linkButton',
                                        ],
                                    ])
                                    ->label('Содержание')
                                    ->columnSpanFull()
                                    ->fileAttachments(true)
                                    ->fileAttachmentsDisk('public')
                                    ->fileAttachmentsDirectory('pages/content/'.now()->format('Y/m'))
                                    ->fileAttachmentsVisibility('public')
                                    ->fileAttachmentsAcceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/gif'])
                                    ->saveUploadedFileAttachmentUsing(
                                        // Возвращаем относительный путь (ключ файла) для Filament v4

                                        fn (TemporaryUploadedFile $temporaryUploadedFile) => app(ContentImageService::class)->processContentImage($temporaryUploadedFile, 'pages/content'))
                                    ->getFileAttachmentUrlUsing(function ($file) {
                                        // Преобразуем ключ файла в публичный URL для предпросмотра в редакторе
                                        if ($file instanceof TemporaryUploadedFile) {
                                            return $file->temporaryUrl();
                                        }
                                        $file = (string) $file;

                                        return str_starts_with($file, 'http')
                                            ? $file
                                            : Storage::disk('public')->url($file);
                                    })
                                    ->getUploadedAttachmentUrlUsing(function ($file) {
                                        // Совместимость с устаревшим алиасом метода
                                        if ($file instanceof TemporaryUploadedFile) {
                                            return $file->temporaryUrl();
                                        }
                                        $file = (string) $file;

                                        return str_starts_with($file, 'http')
                                            ? $file
                                            : Storage::disk('public')->url($file);
                                    })
                                    ->extraInputAttributes([
                                        'style' => 'min-height: 12rem;',
                                    ])
                                    ->toolbarButtons([
                                        ['bold', 'italic', 'underline', 'strike', 'subscript', 'superscript', 'link'],
                                        ['h2', 'h3', 'alignStart', 'alignCenter', 'alignEnd'],
                                        ['blockquote', 'codeBlock', 'code', 'bulletList', 'orderedList'],
                                        ['table'],
                                        ['attachFiles'],
                                        ['undo', 'redo'],
                                        ['mediaGalleryButton'],
                                        ['linkButton'],
                                        ['source-ai'],
                                    ])
                                    ->plugins([
                                        MediaGalleryRichContentPlugin::make(),
                                        LinkButtonRichContentPlugin::make(),
                                    ]),

                                RichEditor::make('excerpt')
                                    ->label('Краткое описание')
                                    ->helperText('Краткое описание. Если не заполнено, будет сгенерировано автоматически из содержания.')
                                    ->columnSpanFull(),

                                Hidden::make('content_with_webp'),

                                FileUpload::make('image_preview')
                                    ->label('Превью')
                                    ->image()
                                    ->disk('public')
                                    ->directory('pages/previews/'.now()->format('Y/m'))
                                    ->saveUploadedFileUsing(function (TemporaryUploadedFile $temporaryUploadedFile, callable $set) {
                                        $previewImageService = app(PreviewImageService::class);
                                        $paths = $previewImageService->processPreviewImage(
                                            $temporaryUploadedFile,
                                            'pages/previews'
                                        );

                                        $set('image_preview_sizes', $paths);
                                        // При прямой загрузке файла считаем, что превью больше не связано с медиагалереей
                                        $set('preview_media_id', null);

                                        return $paths['preview'] ?? null;
                                    }),

                                TextInput::make('image_preview_alt')
                                    ->label('Alt для превью')
                                    ->maxLength(255)
                                    ->helperText('Альтернативный текст для превью изображения (используется в тегах img).'),

                                ViewField::make('preview_media_picker')
                                    ->view('filament.forms.components.page-preview-media-button')
                                    ->columnSpanFull(),

                                Hidden::make('image_preview_sizes'),
                                Hidden::make('preview_media_id'),
                            ])->columns(2),

                        Section::make('SEO метаданные')
                            ->schema([
                                TextInput::make('meta_title')
                                    ->label('Meta Title')
                                    ->maxLength(255)
                                    ->helperText('Рекомендуется до 60 символов'),

                                Textarea::make('meta_description')
                                    ->label('Meta Description')
                                    ->maxLength(255)
                                    ->rows(3)
                                    ->helperText('Рекомендуется до 160 символов'),

                                TextInput::make('meta_keywords')
                                    ->label('Meta Keywords')
                                    ->helperText('Ключевые слова через запятую'),
                            ])->columns(2),
                    ])
                    ->columnSpan(['lg' => 2]),

                Group::make()
                    ->schema([
                        Section::make('Публикация')
                            ->schema([
                                Toggle::make('is_publish')
                                    ->label('Опубликовано')
                                    ->default(false)
                                    ->live()
                                    ->helperText('Включите для немедленной публикации'),

                                DateTimePicker::make('published_at')
                                    ->label('Дата публикации')
                                    ->default(now())
                                    ->displayFormat('d.m.Y H:i')
                                    ->helperText(function ($get): string {
                                        if (! $get('is_publish') && $get('published_at')) {
                                            $publishedAt = Carbon::parse($get('published_at'));
                                            if ($publishedAt->isFuture()) {
                                                return 'Страница будет автоматически опубликована '.$publishedAt->format('d.m.Y в H:i');
                                            }
                                        }

                                        return 'Укажите дату и время публикации статьи';
                                    }),
                            ]),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_preview')
                    ->label('Превью')
                    ->getStateUsing(function ($record) {
                        // Проверяем наличие размеров превью
                        $sizes = $record->image_preview_sizes;

                        if (is_array($sizes)) {
                            $path = $sizes['preview'] ?? $sizes['medium'] ?? $sizes['thumbnail'] ?? null;
                            if (is_string($path) && $path !== '') {
                                return Storage::disk('public')->url($path);
                            }
                        }

                        if (is_string($sizes) && $sizes !== '') {
                            $decoded = json_decode($sizes, true);
                            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                $path = $decoded['preview'] ?? $decoded['medium'] ?? $decoded['thumbnail'] ?? null;
                                if (is_string($path) && $path !== '') {
                                    return Storage::disk('public')->url($path);
                                }
                            }
                        }

                        // Возвращаем оригинальный путь как fallback
                        return is_string($record->image_preview) && $record->image_preview !== ''
                            ? Storage::disk('public')->url($record->image_preview)
                            : null;
                    })
                    ->size(50),

                TextColumn::make('title')
                    ->label('Заголовок')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('excerpt')
                    ->label('Описание')
                    ->limit(255)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('slug')
                    ->label('URL')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('URL скопирован')
                    ->toggleable(),

                TextColumn::make('site.name')
                    ->label('Сайт')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->getStateUsing(fn ($record) => $record->status)
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Опубликовано' => 'success',
                        'Запланировано' => 'warning',
                        'Готово к публикации' => 'info',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'Опубликовано' => 'heroicon-o-check-circle',
                        'Запланировано' => 'heroicon-o-clock',
                        'Готово к публикации' => 'heroicon-o-exclamation-circle',
                        default => 'heroicon-o-document-text',
                    }),

                TextColumn::make('revisions_count')
                    ->label('Ревизии')
                    ->getStateUsing(fn ($record) => $record->revisions()->count()) // Используем прямой запрос
                    ->badge()
                    ->color('info'),

                TextColumn::make('published_at')
                    ->label('Дата публикации')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->color(fn ($record): ?string => $record->isScheduled() ? 'warning' : null),

                TextColumn::make('author.name')
                    ->label('Автор'),

                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('site_id')
                    ->label('Сайт')
                    ->options(Site::all()->pluck('name', 'id')),

                SelectFilter::make('author_id')
                    ->label('Автор')
                    ->options(User::all()->pluck('name', 'id')),

                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'published' => 'Опубликовано',
                        'scheduled' => 'Запланировано',
                        'ready' => 'Готово к публикации',
                        'draft' => 'Черновик',
                    ])
                    ->query(function (Builder $builder, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (blank($value)) {
                            return $builder;
                        }

                        return match ($value) {
                            'published' => $builder->where('is_publish', true)
                                ->where('published_at', '<=', now()),
                            'scheduled' => $builder->where('is_publish', false)
                                ->where('published_at', '>', now()),
                            'ready' => $builder->where('is_publish', false)
                                ->where('published_at', '<=', now()),
                            'draft' => $builder->where('is_publish', false)
                                ->whereNull('published_at'),
                            default => $builder,
                        };
                    }),
            ])
            ->recordActions([
                Action::make('view_page')
                    ->label('Предпросмотр')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Page $page): string => route('pages.show', $page))
                    ->openUrlInNewTab(),

                Action::make('view_revisions')
                    ->label('Ревизии')
                    ->icon('heroicon-o-clock')
                    ->color('info')
                    ->url(fn (Page $page): string => static::getUrl('revisions', ['record' => $page]))
                    ->visible(fn ($record): bool => $record->revisions()->count() > 0),

                Action::make('publish_now')
                    ->label('Опубликовать сейчас')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->visible(fn ($record): bool => ! $record->is_publish)
                    ->action(function ($record): void {
                        $record->update([
                            'is_publish' => true,
                            'published_at' => now(),
                        ]);
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Опубликовать страницу?')
                    ->modalDescription('Страница будет немедленно опубликована.'),

                EditAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),

                    BulkAction::make('publish_selected')
                        ->label('Опубликовать выбранные')
                        ->icon('heroicon-o-rocket-launch')
                        ->color('success')
                        ->action(function ($records): void {
                            foreach ($records as $record) {
                                if (! $record->is_publish) {
                                    $record->update([
                                        'is_publish' => true,
                                        'published_at' => now(),
                                    ]);
                                }
                            }
                        })
                        ->requiresConfirmation()
                        ->modalHeading('Опубликовать выбранные страницы?')
                        ->modalDescription('Все выбранные неопубликованные страницы будут немедленно опубликованы.'),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'create' => CreatePage::route('/create'),
            'edit' => EditPage::route('/{record}/edit'),
            'revisions' => ViewRevisions::route('/{record}/revisions'),
        ];
    }
}

<?php

namespace App\Filament\Resources\Media;

use App\Filament\Resources\Media\Pages\CreateMedia;
use App\Filament\Resources\Media\Pages\EditMedia;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Models\Media;
use App\Models\Site;
use App\Services\MediaService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MediaResource extends Resource
{
    protected static ?string $model = Media::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Медиагалерея';

    protected static ?string $modelLabel = 'Медиафайл';

    protected static ?string $pluralModelLabel = 'Медиагалерея';

    protected static ?int $navigationSort = 5;

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function getNavigationUrl(): string
    {
        // Ensure the sidebar menu opens the gallery (list) page, not a single record
        return static::getUrl('index');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Загрузка файла')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Файл')
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios([
                                null,
                                '16:9',
                                '4:3',
                                '1:1',
                            ])
                            ->disk('public')
                            ->directory('temp/media-uploads')
                            ->maxSize(10240)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'])
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->hiddenOn('edit')
                            ->columnSpanFull(),

                        Select::make('site_id')
                            ->label('Сайт')
                            ->options(Site::all()->pluck('name', 'id'))
                            ->nullable()
                            ->searchable()
                            ->helperText('Привязать к конкретному сайту (опционально)'),
                    ])->columns(1)
                    ->visibleOn('create'),

                Section::make('Метаданные')
                    ->schema([
                        TextInput::make('title')
                            ->label('Заголовок')
                            ->maxLength(255)
                            ->helperText('Используется для SEO и доступности'),

                        TextInput::make('alt_text')
                            ->label('Alt текст')
                            ->maxLength(255)
                            ->helperText('Альтернативный текст для изображения (важно для SEO)'),

                    ])->columns(1),

                Section::make('Информация о файле')
                    ->schema([
                        ViewField::make('preview')
                            ->label('Превью')
                            ->view('filament.forms.components.media-preview')
                            ->hiddenOn('create'),

                        TextInput::make('original_name')
                            ->label('Оригинальное имя')
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('filename')
                            ->label('Имя файла')
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('mime_type')
                            ->label('Тип файла')
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('formatted_size')
                            ->label('Размер')
                            ->disabled()
                            ->dehydrated(false),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('width')
                                    ->label('Ширина (px)')
                                    ->disabled()
                                    ->dehydrated(false),

                                TextInput::make('height')
                                    ->label('Высота (px)')
                                    ->disabled()
                                    ->dehydrated(false),
                            ]),

                        Hidden::make('path'),
                        Hidden::make('disk'),
                        Hidden::make('size'),
                    ])->columns(1)
                    ->hiddenOn('create'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('path')
                    ->label('Превью')
                    ->disk('public')
                    ->size(200)
                    ->square()
                    ->extraImgAttributes(['class' => 'object-cover']),

                TextColumn::make('title')
                    ->label('Заголовок')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('original_name')
                    ->label('Имя файла')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->limit(30),

                TextColumn::make('site.name')
                    ->label('Сайт')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->badge(),

                TextColumn::make('width')
                    ->label('Размеры')
                    ->formatStateUsing(fn (Media $media): string => $media->width && $media->height
                            ? "{$media->width}×{$media->height}"
                            : 'N/A'
                    )
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('formatted_size')
                    ->label('Размер')
                    ->sortable(query: fn (Builder $builder, string $direction): Builder => $builder->orderBy('size', $direction))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('uploadedBy.name')
                    ->label('Загрузил')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Дата загрузки')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->contentGrid([
                'md' => 3,
                'xl' => 6,
            ])
            ->filters([
                SelectFilter::make('site_id')
                    ->label('Сайт')
                    ->options(Site::all()->pluck('name', 'id'))
                    ->placeholder('Все сайты'),

                Filter::make('images_only')
                    ->label('Только изображения')
                    ->query(fn (Builder $builder): Builder => $builder->images())
                    ->default(),

                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('created_from')
                            ->label('Загружено с'),
                        DatePicker::make('created_until')
                            ->label('Загружено до'),
                    ])
                    ->query(fn (Builder $builder, array $data): Builder => $builder
                        ->when(
                            $data['created_from'],
                            fn (Builder $builder, $date): Builder => $builder->whereDate('created_at', '>=', $date),
                        )
                        ->when(
                            $data['created_until'],
                            fn (Builder $builder, $date): Builder => $builder->whereDate('created_at', '<=', $date),
                        )),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Просмотр')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Media $media): string => $media->url)
                    ->openUrlInNewTab(),

                Action::make('copy_url')
                    ->label('Копировать URL')
                    ->icon('heroicon-o-clipboard')
                    ->action(function (Media $media): void {
                        // Handled client-side via Alpine
                    })
                    ->requiresConfirmation(false)
                    ->extraAttributes(fn (Media $media): array => [
                        'data-url' => $media->url,
                        // Однострочный безопасный обработчик без сложных объектных литералов
                        'x-on:click' => "try{navigator.clipboard.writeText(\$el.getAttribute('data-url'));}catch(e){}; try{window.dispatchEvent(new CustomEvent('filament-notify',{detail:{status:'success',message:'URL скопирован'}}));}catch(e){}",
                    ]),

                EditAction::make()
                    ->label('Редактировать'),

                DeleteAction::make()
                    ->label('Удалить')
                    ->before(function (Media $media): void {
                        $mediaService = app(MediaService::class);
                        $mediaService->deleteMedia($media);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function ($records): void {
                            $mediaService = app(MediaService::class);
                            foreach ($records as $record) {
                                $mediaService->deleteMedia($record);
                            }
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s');
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
            'index' => \App\Filament\Resources\Media\Pages\Gallery::route('/'),
            'upload' => \App\Filament\Resources\Media\Pages\BulkUpload::route('/upload'),
            'list' => ListMedia::route('/list'),
            'create' => CreateMedia::route('/create'),
            'edit' => EditMedia::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationLabel = 'Рубрики';

    protected static ?string $modelLabel = 'Рубрика';

    protected static ?string $pluralModelLabel = 'Рубрики';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (string $state, $set, ?Category $record): void {
                        $category = new Category;
                        if (! $record || empty($record->slug)) {
                            $category->site_id = 1;
                        } else {
                            // Если запись уже существует и имеет slug, перегенерируем его при изменении имени
                            $category->site_id = $record->site_id;
                        }
                        $set('slug', $category->generateSlug($state));
                    }),
                TextInput::make('slug')
                    ->label('URL (slug)')
                    ->required()
                    ->maxLength(255)
                    ->rules(['alpha_dash'])
                    ->unique(
                        table: Category::class,
                        column: 'slug',
                        ignoreRecord: true,
                        modifyRuleUsing: fn ($get, $rule) => $rule->where('site_id', $get('site_id'))
                    )
                    ->helperText('URL адрес. Должен содержать только латинские буквы, цифры, дефисы и подчеркивания.')
                    ->suffixIcon('heroicon-m-link'),
                Textarea::make('description')
                    ->label('Описание')
                    ->maxLength(65535),
                TextInput::make('meta_title')
                    ->label('Мета заголовок')
                    ->maxLength(255),
                Textarea::make('meta_description')
                    ->label('Мета описание')
                    ->maxLength(65535),
                TextInput::make('meta_keywords')
                    ->label('Ключевые слова')
                    ->maxLength(255),
                Select::make('site_id')
                    ->label('Сайт')
                    ->relationship('site', 'name')
                    ->required(),
                Select::make('parent_id')
                    ->label('Родительская рубрика')
                    ->relationship('parent', 'name')
                    ->placeholder('Нет родителя'),
                Toggle::make('is_active')
                    ->label('Активна')
                    ->default(true),
                TextInput::make('sort_order')
                    ->label('Порядок сортировки')
                    ->numeric()
                    ->default(0),
                FileUpload::make('image')
                    ->label('Изображение')
                    ->image()
                    ->directory('categories'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable(),
                TextColumn::make('site.name')
                    ->label('Сайт')
                    ->sortable(),
                TextColumn::make('parent.name')
                    ->label('Родительская рубрика')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('posts_count')
                    ->label('Количество постов')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Активна')
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->label('Сортировка')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('site_id')
                    ->label('Сайт')
                    ->relationship('site', 'name'),
                SelectFilter::make('parent_id')
                    ->label('Родительская рубрика')
                    ->relationship('parent', 'name'),
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

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\Tags;

use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Filament\Resources\Tags\Pages\EditTag;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Models\Tag;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Метки';

    protected static ?string $modelLabel = 'Метка';

    protected static ?string $pluralModelLabel = 'Метки';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (string $state, $set, ?Tag $record): void {
                        $tag = new Tag;
                        $tag->site_id = ! $record || empty($record->slug) ? 1 : $record->site_id;
                        $set('slug', $tag->generateSlug($state));
                    }),
                TextInput::make('slug')
                    ->label('URL (slug)')
                    ->required()
                    ->maxLength(255)
                    ->rules(['alpha_dash'])
                    ->unique(
                        table: Tag::class,
                        column: 'slug',
                        ignoreRecord: true,
                        modifyRuleUsing: fn ($get, $rule) => $rule->where('site_id', $get('site_id'))
                    )
                    ->helperText('URL адрес. Должен содержать только латинские буквы, цифры, дефисы и подчеркивания.')
                    ->suffixIcon('heroicon-m-link'),
                Textarea::make('description')
                    ->label('Описание')
                    ->maxLength(65535),
                TextInput::make('color')
                    ->label('Цвет')
                    ->maxLength(255)
                    ->helperText('Цвет метки (например, #ff0000)'),
                Select::make('site_id')
                    ->label('Сайт')
                    ->relationship('site', 'name')
                    ->required(),
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
                TextColumn::make('posts_count')
                    ->label('Количество постов')
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
            'index' => ListTags::route('/'),
            'create' => CreateTag::route('/create'),
            'edit' => EditTag::route('/{record}/edit'),
        ];
    }
}

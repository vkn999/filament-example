<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\PostResource;
use App\Models\ContentRevision;
use App\Models\Post;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class ViewRevisions extends ResourcePage implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = PostResource::class;

    protected string $view = 'filament.resources.post-resource.posts.view-revisions';

    public Post $record;

    public function mount(int|string $record): void
    {
        if (is_string($record) && str_starts_with($record, '{')) {
            $data = json_decode($record, true);
            $record = $data['id'] ?? $record;
        }

        $record = Post::findOrFail($record);
        if ($record instanceof Post) {
            $this->record = $record;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Назад к записи')
                ->icon('heroicon-o-arrow-left')
                ->url(fn (): string => PostResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ContentRevision::query()->where('revisable_id', $this->record->id)->where('revisable_type', Post::class))
            ->columns([
                TextColumn::make('revision_number')
                    ->label('Ревизия #')
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                TextColumn::make('title')
                    ->label('Заголовок')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->title),

                /*Tables\Columns\TextColumn::make('content')
                    ->label('Контент (превью)')
                    ->limit(100)
                    ->formatStateUsing(fn (string $state): string => strip_tags($state))
                    ->tooltip(function ($record) {
                        return 'Длина: '.strlen($record->content).' символов';
                    }),*/

                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('changes_count')
                    ->label('Изменений')
                    ->getStateUsing(function ($record): int {
                        $diff = $record->getDifferences();
                        $changesCount = 0;
                        foreach ($diff as $change) {
                            if ($change['old'] !== $change['new']) {
                                $changesCount++;
                            }
                        }

                        return $changesCount;
                    })
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 3 => 'danger',
                        $state >= 2 => 'warning',
                        default => 'success',
                    }),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label('Просмотр')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->modalHeading(fn (ContentRevision $contentRevision): string => "Ревизия #{$contentRevision->revision_number}")
                    ->modalContent(fn (ContentRevision $contentRevision): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View => view('filament.components.revision-preview', [
                        'revision' => $contentRevision,
                    ]))
                    ->modalWidth('7xl'),

                Action::make('restore')
                    ->label('Восстановить')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Восстановить ревизию?')
                    ->modalDescription(fn (ContentRevision $contentRevision): string => "Вы уверены, что хотите восстановить запись до состояния ревизии #{$contentRevision->revision_number}? Текущие изменения будут перезаписаны."
                    )
                    ->action(function (ContentRevision $contentRevision) {
                        if ($contentRevision->restoreToModel()) {
                            Notification::make()
                                ->title('Ревизия восстановлена')
                                ->body("Запись восстановлена до состояния ревизии #{$contentRevision->revision_number}")
                                ->success()
                                ->send();

                            return redirect(PostResource::getUrl('edit', ['record' => $this->record]));
                        }
                        Notification::make()
                            ->title('Ошибка')
                            ->body('Не удалось восстановить ревизию')
                            ->danger()
                            ->send();
                    }),

                Action::make('compare')
                    ->label('Сравнить')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('warning')
                    ->modalHeading(fn (ContentRevision $contentRevision): string => "Сравнение ревизии #{$contentRevision->revision_number}")
                    ->modalContent(fn (ContentRevision $contentRevision): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View => view('filament.components.revision-compare', [
                        'revision' => $contentRevision,
                        'differences' => $contentRevision->getDifferences(),
                    ]))
                    ->modalWidth('7xl'),

                DeleteAction::make()
                    ->label('Удалить')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (ContentRevision $contentRevision): string => "Удалить ревизию #{$contentRevision->revision_number}?")
                    ->modalDescription('Вы уверены, что хотите удалить эту ревизию? Это действие нельзя отменить.')
                    ->action(function (ContentRevision $contentRevision): void {
                        try {
                            $revisionNumber = $contentRevision->revision_number;
                            $contentRevision->delete();

                            Notification::make()
                                ->title('Ревизия удалена')
                                ->body("Ревизия #{$revisionNumber} была успешно удалена")
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Ошибка')
                                ->body('Не удалось удалить ревизию: '.$e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('revision_number', 'desc')
            ->emptyStateHeading('Ревизии отсутствуют')
            ->emptyStateDescription('Ревизии будут создаваться автоматически при изменении содержимого записи.')
            ->emptyStateIcon('heroicon-o-document-text');
    }

    public function getTitle(): string
    {
        return "Ревизии записи: {$this->record->title}";
    }
}

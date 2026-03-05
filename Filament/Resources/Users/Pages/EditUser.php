<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->visible(function (): bool {
                    $user = auth()->user();
                    if (! $user) {
                        return false;
                    }

                    $recordId = $this->record instanceof Model ? $this->record->getKey() : null;
                    $userKey = $user->getKey();

                    $notSelf = false;
                    if ($recordId !== null && is_scalar($recordId) && is_scalar($userKey)) {
                        $notSelf = (string) $userKey !== (string) $recordId;
                    }

                    return $user->can('delete_users')
                        && $user->hasRole('Super Admin')
                        && $notSelf;
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Пользователь успешно обновлен';
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Удаляем подтверждение пароля перед сохранением
        unset($data['password_confirmation']);

        // Если пароль пустой, не обновляем его
        if (empty($data['password'])) {
            unset($data['password']);
        }

        return $data;
    }
}

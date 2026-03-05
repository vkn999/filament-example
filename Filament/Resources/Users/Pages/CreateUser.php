<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Пользователь успешно создан';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Удаляем подтверждение пароля перед сохранением
        unset($data['password_confirmation']);

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        // Создаем пользователя
        $user = static::getModel()::create($data);

        // Назначаем роли, если они выбраны
        $currentUser = auth()->user();
        if (isset($data['roles']) && ($currentUser instanceof User) && $currentUser->hasRole('Super Admin')) {
            $user->syncRoles($data['roles']);
        }

        return $user;
    }
}

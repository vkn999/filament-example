<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(function (): bool {
                    $user = auth()->user();
                    if (! $user) {
                        return false;
                    }

                    $recordId = $this->record instanceof Model ? $this->record->getKey() : null;
                    $userKey = $user->getKey();

                    $isSelf = false;
                    if ($recordId !== null && is_scalar($recordId) && is_scalar($userKey)) {
                        $isSelf = (string) $userKey === (string) $recordId;
                    }

                    return $user->can('edit_users')
                        && (
                            $user->hasRole('Super Admin')
                            || $isSelf
                        );
                }),
        ];
    }
}

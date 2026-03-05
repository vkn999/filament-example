<?php

namespace App\Filament\Resources\SecondaryMenu\Pages;

use App\Filament\Resources\SecondaryMenu\SecondaryMenuResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSecondaryMenu extends ListRecords
{
    protected static string $resource = SecondaryMenuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

<?php

namespace App\Forms\Components;

use Filament\Forms\Components\RichEditor;

class MediaRichEditor extends RichEditor
{
    protected function setUp(): void
    {
        parent::setUp();

        // Сохраняем поддержку события вставки медиа, не переопределяя Blade-шаблон
        $this->extraAlpineAttributes([
            'x-on:media-selected.window' => 'handleMediaSelected($event.detail)',
        ]);
    }
}

<?php

namespace App\Filament\Forms\RichContent\Plugins;

use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Support\Icons\Heroicon;
use Tiptap\Core\Extension;

class WidgetRichContentPlugin implements RichContentPlugin
{
    public function __construct(
        protected string $name = 'widgetButton',
        protected string $label = 'Виджет',
        protected Heroicon $icon = Heroicon::PuzzlePiece,
    ) {}

    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * @return array<Extension>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    public function getTipTapJsExtensions(): array
    {
        return [];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make($this->name)
                ->label($this->label)
                ->icon('heroicon-o-puzzle-piece')
                ->jsHandler('window.travelCmsOpenWidgetModal && window.travelCmsOpenWidgetModal($getEditor())'),
        ];
    }

    public function getEditorActions(): array
    {
        return []; // Больше не нужны встроенные Actions
    }
}

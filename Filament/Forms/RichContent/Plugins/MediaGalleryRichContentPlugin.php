<?php

namespace App\Filament\Forms\RichContent\Plugins;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Support\Icons\Heroicon;
use Tiptap\Core\Extension;

class MediaGalleryRichContentPlugin implements RichContentPlugin
{
    public function __construct(
        protected string $name = 'mediaGalleryButton',
        protected string $label = 'Медиа',
        protected Heroicon $icon = Heroicon::Photo,
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
        // No custom TipTap PHP extensions are required for this simple modal button.
        return [];
    }

    /**
     * @return array<string>
     */
    public function getTipTapJsExtensions(): array
    {
        // No JavaScript extensions are needed for this plugin.
        return [];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        // Register a single toolbar button that will open a modal on click.
        return [
            RichEditorTool::make($this->name)
                ->label($this->label)
                ->icon($this->icon)
                ->extraAttributes(['data-custom-gallery-button' => 'true'])
                ->jsHandler('window.travelCmsOpenMediaGallery && window.travelCmsOpenMediaGallery($getEditor())'),
        ];
    }

    /**
     * @return array<Action>
     */
    public function getEditorActions(): array
    {
        // No actions needed. The toolbar button uses jsHandler to directly call
        // the global JavaScript function window.travelCmsOpenMediaGallery().
        return [];
    }
}

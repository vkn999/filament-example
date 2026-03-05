<?php

namespace App\Filament\Forms\RichContent\Plugins;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Support\Icons\Heroicon;
use Tiptap\Core\Extension;

class LinkButtonRichContentPlugin implements RichContentPlugin
{
    public function __construct(
        protected string $name = 'linkButton',
        protected string $label = 'Кнопка',
        protected Heroicon $icon = Heroicon::Link,
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
                // Иконка в виде «кнопки» (прямоугольник) вместо стандартной ссылки
                // Используем строковый алиас heroicon, чтобы избежать несовместимости enum в разных версиях
                ->icon('heroicon-o-rectangle-stack')
                ->extraAttributes(['data-custom-link-button' => 'true'])
                // Надёжный jsHandler: если глобальная функция не найдена, напрямую открываем модалку
                ->jsHandler("(()=>{try{console.info('[LinkButton] toolbar handler fired')}catch(e){};try{window.currentTipTapEditor=\$getEditor()}catch(e){};if(window.travelCmsOpenLinkButton){try{console.info('[LinkButton] calling global opener');}catch(e){};window.travelCmsOpenLinkButton(\$getEditor());return}try{console.warn('[LinkButton] global opener not found, dispatching events');}catch(e){};try{window.dispatchEvent(new CustomEvent('open-link-button-modal'))}catch(e){try{console.warn('[LinkButton] failed to dispatch open-link-button-modal',e)}catch(ee){}};try{window.dispatchEvent(new CustomEvent('open-modal',{detail:{id:'link-button-modal'}}))}catch(e){try{console.warn('[LinkButton] failed to dispatch open-modal',e)}catch(ee){}}})()"),
        ];
    }

    /**
     * @return array<Action>
     */
    public function getEditorActions(): array
    {
        return [];
    }
}

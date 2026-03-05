<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\PostResource;
use App\Models\Media;
use App\Models\Post;
use App\Services\ContentImageService;
use DOMDocument;
use DOMNode;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['content'] = $this->sanitizeContent($data['content'] ?? '');

        return $data;
    }

    protected function afterCreate(): void
    {
        $contentImageService = app(ContentImageService::class);
        $result = $contentImageService->processContent($this->record->content ?? '');

        if (! $this->record instanceof \Illuminate\Database\Eloquent\Model) {
            return;
        }

        $hasChanges = $result['hasChanges'] ?? false;

        if ($hasChanges) {
            $content = $result['content'] ?? ($this->record->content ?? '');
            $this->record->updateQuietly([
                'content' => $content,
                'content_with_webp' => $content,
            ]);
        }
    }

    /**
     * Устанавливает превью поста из элемента медиагалереи.
     *
     * Метод вызывается из JS через Livewire, когда пользователь
     * выбирает изображение в медиагалерее для превью поста.
     */
    public function setPreviewFromMedia(int $mediaId, ?string $altOverride = null): void
    {
        $media = Media::find($mediaId);

        if (! $media) {
            return;
        }

        // Используем модель Post, чтобы повторно использовать доменную логику
        $post = new Post;
        $post->setPreviewFromMedia($media, $altOverride);

        // Аккуратно обновляем только поля превью, не сбрасывая остальное состояние формы
        $state = $this->form->getState();

        $state['image_preview'] = $post->image_preview;
        $state['image_preview_sizes'] = $post->image_preview_sizes;
        $state['image_preview_alt'] = $post->image_preview_alt;
        $state['preview_media_id'] = $post->preview_media_id;

        $this->form->fill($state);
    }

    private function sanitizeContent(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        $internalErrors = libxml_use_internal_errors(true);
        $domDocument = new DOMDocument('1.0', 'UTF-8');
        $wrapper = '<div>'.$html.'</div>';
        $domDocument->loadHTML('<?xml encoding="utf-8" ?>'.$wrapper, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        // Remove any leaked script text fragments accidentally inserted into content
        $divWrapper = $domDocument->getElementsByTagName('div')->item(0);
        if ($divWrapper) {
            $nodes = [$divWrapper];
            while ($nodes) {
                /** @var DOMNode $node */
                $node = array_pop($nodes);
                for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
                    $child = $node->childNodes->item($i);
                    if ($child->nodeType === XML_TEXT_NODE) {
                        $parentName = strtoupper($node->nodeName ?? '');
                        // Do not strip from code/pre/script/style blocks
                        if (in_array($parentName, ['CODE', 'PRE', 'SCRIPT', 'STYLE'], true)) {
                            continue;
                        }
                        $text = (string) $child->textContent;
                        $hasLeak = $text !== '' && (
                            str_contains($text, 'MutationObserver') ||
                            str_contains($text, 'computeUrl(') ||
                            str_contains($text, 'isPreview(') ||
                            str_contains($text, 'livewire/preview-file/')
                        );
                        if ($hasLeak) {
                            $node->removeChild($child);

                            continue;
                        }
                    } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                        $nodes[] = $child;
                    }
                }
            }
        }

        // Collect img nodes to remove (livewire previews)
        $images = [];
        foreach ($domDocument->getElementsByTagName('img') as $domNodeList) {
            $images[] = $domNodeList;
        }

        $toRemove = [];
        foreach ($images as $image) {
            $src = $image->getAttribute('src');
            $dataId = $image->getAttribute('data-id');

            // Remove livewire preview images
            if ($src !== '' && str_contains($src, '/livewire/preview-file/')) {
                $toRemove[] = $image;

                continue;
            }

            $needsFix = ($src === '') || str_contains($src, '/livewire/preview-file/');

            if ($needsFix && $dataId !== '') {
                $url = $dataId;
                if (! str_starts_with($url, 'http')) {
                    // If it points to storage or a key, normalize to public URL
                    if (str_starts_with($url, '/storage/') || str_starts_with($url, 'storage/')) {
                        $relative = ltrim((string) preg_replace('#^/?storage/#', '', $url), '/');
                        $url = Storage::disk('public')->url($relative);
                    } else {
                        $url = Storage::disk('public')->url(ltrim($url, '/'));
                    }
                }

                $image->setAttribute('src', $url);
            }
        }

        foreach ($toRemove as $node) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }

        // Enhance links: convert our special link class into inline-styled button
        try {
            $links = [];
            foreach ($domDocument->getElementsByTagName('a') as $a) {
                $links[] = $a;
            }
            foreach ($links as $link) {
                $class = $link->getAttribute('class');
                if ($class === '') {
                    continue;
                }
                if (! str_contains($class, 'tcms-btn-link')) {
                    continue;
                }

                $bg = null;
                $br = null;
                $tc = null;
                // Try combined token first: tcms-btn-link--bg-HEX--br-HEX--tc-HEX
                if (preg_match('/tcms-btn-link--bg-([0-9a-fA-F]{3,6})--br-([0-9a-fA-F]{3,6})--tc-([0-9a-fA-F]{3,6})/i', $class, $m)) {
                    $bg = $m[1];
                    $br = $m[2];
                    $tc = $m[3];
                } else {
                    if (preg_match('/tcms-btn-bg-([0-9a-fA-F]{3,6})/i', $class, $m)) {
                        $bg = $m[1];
                    }
                    if (preg_match('/tcms-btn-br-([0-9a-fA-F]{3,6})/i', $class, $m)) {
                        $br = $m[1];
                    }
                    if (preg_match('/tcms-btn-tc-([0-9a-fA-F]{3,6})/i', $class, $m)) {
                        $tc = $m[1];
                    }
                }

                $normalize = function (?string $hex): string {
                    $hex = (string) $hex;
                    $hex = ltrim($hex, '#');
                    if ($hex === '') {
                        return '';
                    }
                    if (strlen($hex) === 3) {
                        return strtolower($hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]);
                    }

                    return strtolower(substr($hex, 0, 6));
                };

                $bg = $normalize($bg) ?: '2563eb';
                $br = $normalize($br) ?: '1d4ed8';
                $tc = $normalize($tc) ?: 'ffffff';

                $style = sprintf(
                    'display:inline-block;padding:8px 16px;border-radius:6px;background-color:#%s;border:1px solid #%s;color:#%s;text-decoration:none;',
                    $bg, $br, $tc
                );
                $link->setAttribute('style', $style);
                if (! $link->hasAttribute('role')) {
                    $link->setAttribute('role', 'button');
                }
            }
        } catch (\Throwable) {
            // No-op: never break saving because of styling issues
        }

        // Extract inner HTML of wrapper div
        $div = $domDocument->getElementsByTagName('div')->item(0);
        $result = '';
        if ($div) {
            foreach ($div->childNodes as $child) {
                $result .= $domDocument->saveHTML($child);
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        return $result !== '' ? $result : $html;
    }
}

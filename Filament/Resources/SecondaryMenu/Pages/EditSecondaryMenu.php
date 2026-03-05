<?php

namespace App\Filament\Resources\SecondaryMenu\Pages;

use App\Events\MenuChanged;
use App\Filament\Resources\SecondaryMenu\SecondaryMenuResource;
use App\Models\Media;
use App\Services\PreviewImageService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EditSecondaryMenu extends EditRecord
{
    protected static string $resource = SecondaryMenuResource::class;

    protected function afterSave(): void
    {
        // Инвалидируем кэш меню для сайта после сохранения изменений и синхронизации связей
        $model = $this->getRecord();
        if ($model && $model->site_id) {
            event(new MenuChanged($model->site_id, true));
        }
    }

    /**
     * Устанавливает изображение элемента второго меню из медиагалереи.
     * Вызывается из JS через Livewire после выбора изображения.
     */
    public function setImageFromMedia(int $mediaId, ?string $altOverride = null): void
    {
        Log::debug('[SecondaryMenu][Edit] setImageFromMedia called', ['media_id' => $mediaId, 'altOverride' => $altOverride]);
        $media = Media::find($mediaId);
        if (! $media || ! $media->isImage()) {
            Log::warning('[SecondaryMenu][Edit] media not found or not image', ['exists' => (bool) $media, 'is_image' => $media?->isImage()]);
            try {
                $this->dispatch('secondary-menu-set-image-done', ['error' => 'media_not_image_or_missing', 'where' => 'edit']);
            } catch (\Throwable) {
            }

            return;
        }

        $srcDisk = $media->disk ?: 'public';
        $srcPath = $media->path;
        if (! Storage::disk($srcDisk)->exists($srcPath)) {
            Log::warning('[SecondaryMenu][Edit] media file not exists on disk', ['disk' => $srcDisk, 'path' => $srcPath]);
            try {
                $this->dispatch('secondary-menu-set-image-done', ['error' => 'source_not_exists', 'disk' => $srcDisk, 'path' => $srcPath, 'where' => 'edit']);
            } catch (\Throwable) {
            }

            return;
        }

        $fullPath = Storage::disk($srcDisk)->path($srcPath);
        Log::debug('[SecondaryMenu][Edit] source resolved', ['fullPath' => $fullPath]);

        $previewImageService = app(PreviewImageService::class);
        try {
            // ВНИМАНИЕ: createPreviewFromPath ожидает ОТНОСИТЕЛЬНЫЙ путь на диске 'public',
            // а не абсолютный full path. Поэтому передаём $srcPath.
            $paths = $previewImageService->createPreviewFromPath(
                $srcPath,
                'secondary-menus/images'
            );
        } catch (\Throwable $e) {
            Log::error('[SecondaryMenu][Edit] createPreviewFromPath exception', ['message' => $e->getMessage()]);
            try {
                $this->dispatch('secondary-menu-set-image-done', ['error' => 'exception', 'message' => $e->getMessage(), 'where' => 'edit']);
            } catch (\Throwable) {
            }

            return;
        }

        if (! $paths) {
            Log::warning('[SecondaryMenu][Edit] preview service returned empty paths');
            try {
                $this->dispatch('secondary-menu-set-image-done', ['error' => 'empty_paths', 'where' => 'edit']);
            } catch (\Throwable) {
            }

            return;
        }

        Log::debug('[SecondaryMenu][Edit] preview paths generated', ['paths' => $paths]);
        $state = $this->form->getState();
        $state['image_path'] = $paths['medium'] ?? $paths['thumbnail'] ?? null;
        $state['image_path_sizes'] = $paths;
        $state['image_alt'] = $altOverride !== null && $altOverride !== ''
            ? $altOverride
            : ($media->alt_text ?? null);
        $state['image_media_id'] = $mediaId;

        $this->form->fill($state);

        try {
            $this->dispatch('secondary-menu-set-image-done', [
                'image_path' => $state['image_path'] ?? null,
                'sizes' => $state['image_path_sizes'] ?? null,
                'alt' => $state['image_alt'] ?? null,
                'media_id' => $mediaId,
                'where' => 'edit',
            ]);
        } catch (\Throwable) {
            // no-op
        }
    }
}

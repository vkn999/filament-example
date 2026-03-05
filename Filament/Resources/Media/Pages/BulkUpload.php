<?php

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Resources\Media\MediaResource;
use App\Models\Media;
use App\Models\Site;
use App\Services\ContentImageService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BulkUpload extends Page
{
    protected static string $resource = MediaResource::class;

    protected string $view = 'filament.resources.media.pages.bulk-upload';

    protected static ?string $title = 'Массовая загрузка медиа';

    protected static ?string $navigationLabel = 'Массовая загрузка';

    public function mount(): void
    {
        // Страница теперь статическая, mount пустой
    }

    protected function getViewData(): array
    {
        return [
            'sites' => Site::orderBy('name')->get(),
        ];
    }

    // Новый метод для обработки AJAX загрузки
    public function handleAjaxUpload(Request $request)
    {
        $request->validate([
            'files' => 'required|array|min:1|max:20',
            'files.*' => 'image|max:65536',
        ]);

        $files = $request->file('files');
        $siteId = $request->input('site_id');

        // Проверка общего размера
        $totalBytes = 0;
        foreach ($files as $file) {
            $totalBytes += $file->getSize();
        }

        if ($totalBytes > 64 * 1024 * 1024) {
            return response()->json([
                'success' => false,
                'message' => 'Общий размер файлов не должен превышать 64 МБ.',
            ], 422);
        }

        $contentImageService = app(ContentImageService::class);
        $disk = Storage::disk('public');
        $resolvedSiteId = $siteId ? (int) $siteId : null;

        $created = 0;
        $errors = [];

        foreach ($files as $file) {
            try {
                $path = $contentImageService->processContentImage($file, 'media');
                if (! $path) {
                    $errors[] = "Файл {$file->getClientOriginalName()} не удалось обработать";

                    continue;
                }

                $fullPath = $disk->path($path);
                $size = $disk->size($path);

                // Получаем размеры изображения
                $width = null;
                $height = null;
                try {
                    $dimensions = getimagesize($fullPath);
                    if ($dimensions) {
                        $width = $dimensions[0];
                        $height = $dimensions[1];
                    }
                } catch (\Throwable $e) {
                    // Игнорируем ошибки получения размеров
                }

                // Определяем MIME тип
                $mime = $disk->mimeType($path) ?: 'image/webp';

                Media::create([
                    'site_id' => $resolvedSiteId,
                    'original_name' => $file->getClientOriginalName(),
                    'filename' => basename($path),
                    'path' => $path,
                    'disk' => 'public',
                    'mime_type' => $mime,
                    'size' => $size,
                    'width' => $width,
                    'height' => $height,
                    'uploaded_by' => auth()->id(),
                ]);

                $created++;
            } catch (\Throwable $e) {
                \Log::error('Media upload error: '.$e->getMessage());
                $errors[] = "Ошибка при загрузке {$file->getClientOriginalName()}: ".$e->getMessage();
            }
        }

        if ($created > 0) {
            Notification::make()
                ->title('Успешно')
                ->body("Загружено файлов: {$created}")
                ->success()
                ->send();

            return response()->json([
                'success' => true,
                'message' => "Успешно загружено файлов: {$created}",
                'created' => $created,
                'errors' => $errors,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Не удалось загрузить файлы',
            'errors' => $errors,
        ], 422);
    }
}

<?php

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Resources\Media\MediaResource;
use App\Services\MediaService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CreateMedia extends CreateRecord
{
    protected static string $resource = MediaResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Keep data intact; actual creation is handled in handleRecordCreation()
        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $disk = Storage::disk('public');
        $tempPath = $data['file'] ?? null;

        if (! $tempPath || ! $disk->exists($tempPath)) {
            throw new RuntimeException('Файл для загрузки не найден. Пожалуйста, выберите файл и попробуйте снова.');
        }

        $fullTempPath = $disk->path($tempPath);

        // Create an UploadedFile instance from the temp file
        $uploadedFile = new UploadedFile(
            $fullTempPath,
            basename((string) $tempPath),
            $disk->mimeType($tempPath),
            null,
            true
        );

        // Process the upload with MediaService
        $mediaService = app(MediaService::class);
        $media = $mediaService->processUpload($uploadedFile, $data['site_id'] ?? null);

        // Clean up temp file
        $disk->delete($tempPath);

        // Update metadata if provided
        if (isset($data['title']) || isset($data['alt_text']) || isset($data['description'])) {
            $mediaService->updateMetadata($media, [
                'title' => $data['title'] ?? null,
                'alt_text' => $data['alt_text'] ?? null,
                'description' => $data['description'] ?? null,
            ]);
        }

        return $media;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

<?php

namespace App\Livewire;

use App\Models\Media;
use App\Models\Site;
use App\Services\ContentImageService;
use App\Services\MediaService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

class MediaGalleryModal extends Component
{
    use WithFileUploads, WithPagination;

    public bool $isOpen = false;

    public $selectedMedia;

    public ?int $selectedMediaId = null;

    public array $uploadedFiles = [];

    public $search = '';

    public $siteFilter; // gallery filter

    public $uploadSiteId; // site chooser for upload (nullable)

    public $activeTab = 'gallery'; // 'gallery' or 'upload'

    public $editorState; // editor state passed from the toolbar button

    // Inline edit fields for selected media
    public string $editFilenameBase = '';

    public string $editAlt = '';

    public ?int $editSiteId = null;

    protected $rules = [
        'uploadedFiles' => 'required|array|min:1|max:20',
        'uploadedFiles.*' => 'image|max:65536', // per-file up to 64MB; total validated separately
        'uploadSiteId' => 'nullable|integer|exists:sites,id',
    ];

    public function mount(): void
    {
        $this->siteFilter = session('current_site_id');
        $this->uploadSiteId = $this->siteFilter; // по умолчанию сайт загрузки = текущий сайт (необязательно)
        // Сообщаем браузеру wire:id компонента для надёжного открытия модалки из JS
        try {
            $this->dispatch('media-gallery-registered', ['id' => $this->id]);
        } catch (Throwable) {
            // no-op
        }
    }

    #[On('openMediaGallery')]
    public function openMediaGallery(): void
    {
        // Управляем модальным окном через Filament modal
        $this->activeTab = 'gallery';
        $this->resetUploadForm();

        try {
            $this->dispatch('open-modal', id: 'media-gallery-modal');
        } catch (Throwable) {
            // no-op
        }
    }

    #[On('open-media-gallery')]
    public function openMediaGalleryBrowserEvent(): void
    {
        $this->openMediaGallery();
    }

    #[On('openCustomModal')]
    public function openCustomModal($data = null, $editorState = null): void
    {
        // Livewire v3 может передавать payload по-разному:
        // - JS: Livewire.dispatch('openCustomModal', { editorState: 'post:preview' })
        //   => в PHP придёт ассоциативный массив с ключом editorState
        // - JS: Livewire.dispatch('openCustomModal', 'post:preview')
        //   => в PHP придёт строка первым аргументом
        // - JS: Livewire.dispatch('openCustomModal', 'post:preview', ...)
        //   => editorState может оказаться вторым аргументом

        $resolved = null;

        if (is_array($data) && array_key_exists('editorState', $data)) {
            $resolved = $data['editorState'];
        } elseif (is_string($data) && $data !== '') {
            $resolved = $data;
        } elseif (is_string($editorState) && $editorState !== '') {
            $resolved = $editorState;
        }

        $this->editorState = $resolved;
        $this->openMediaGallery();
    }

    public function closeModal(): void
    {
        $this->isOpen = false;
        $this->selectedMedia = null;
        $this->resetUploadForm();

        try {
            $this->dispatch('close-modal', id: 'media-gallery-modal');
        } catch (Throwable) {
            // no-op
        }
    }

    public function selectMedia($mediaId): void
    {
        $mediaId = (int) $mediaId;
        if ($this->selectedMediaId === $mediaId) {
            // Toggle off on a repeated click
            $this->selectedMediaId = null;
            $this->selectedMedia = null;
            $this->editFilenameBase = '';
            $this->editAlt = '';
            $this->editSiteId = null;

            return;
        }

        $this->selectedMediaId = $mediaId;
        $this->selectedMedia = Media::find($mediaId);
        if ($this->selectedMedia) {
            $this->editFilenameBase = pathinfo((string) $this->selectedMedia->filename, PATHINFO_FILENAME) ?: '';
            $this->editAlt = (string) ($this->selectedMedia->alt_text ?? '');
            $this->editSiteId = $this->selectedMedia->site_id; // Загружаем текущий сайт
        } else {
            $this->editFilenameBase = '';
            $this->editAlt = '';
        }
    }

    public function selectAndInsertMedia($mediaId): void
    {
        $this->selectedMedia = Media::find($mediaId);
        $this->insertMedia();
    }

    public function insertMedia(): void
    {
        if ($this->selectedMedia) {
            // Guarantee non-empty alt when inserting: prefer current typed alt (not necessarily saved), then saved alt, then filename base
            $alt = trim($this->editAlt) ?: $this->selectedMedia->effective_alt;

            $payload = [
                'id' => $this->selectedMedia->id,
                'url' => $this->selectedMedia->url,
                'alt_text' => $alt,
                'title' => $this->selectedMedia->title,
                'width' => $this->selectedMedia->width,
                'height' => $this->selectedMedia->height,
                'editorState' => $this->editorState,
            ];

            // Базовый сценарий: вставка изображения в RichEditor / TipTap
            $this->dispatch('media-selected', $payload);

            // Дополнительный сценарий: выбор превью для поста
            if (is_string($this->editorState) && str_starts_with($this->editorState, 'post:preview')) {
                $this->dispatch('post-preview-media-selected', $payload);
            }

            // Дополнительный сценарий: выбор превью для страницы
            if (is_string($this->editorState) && str_starts_with($this->editorState, 'page:preview')) {
                $this->dispatch('page-preview-media-selected', $payload);
            }

            // Дополнительный сценарий: выбор изображения для SecondaryMenu
            if (is_string($this->editorState) && str_starts_with($this->editorState, 'secondary:menu')) {
                // Передаём payload как объект и дублируем mediaId отдельным аргументом для надёжности
                $this->dispatch('secondary-menu-media-selected', $payload, ['media_id' => $this->selectedMedia->id], $this->selectedMedia->id);
            }

            $this->closeModal();
        }
    }

    public function uploadNewMedia(): void
    {
        $this->validate();

        // Санитизируем список файлов и переиндексируем, чтобы исключить «дыры»
        $files = array_values(array_filter($this->uploadedFiles));
        if ($files === []) {
            $this->addError('uploadedFiles', 'Пожалуйста, выберите хотя бы один файл.');

            return;
        }

        // Проверка общего размера пачки файлов — не более 64 МБ
        $totalBytes = 0;
        foreach ($files as $file) {
            $totalBytes += method_exists($file, 'getSize') ? (int) ($file->getSize() ?? 0) : 0;
        }
        if ($totalBytes > 64 * 1024 * 1024) {
            $this->addError('uploadedFiles', 'Общий размер файлов не должен превышать 64 МБ.');

            return;
        }

        $contentImageService = app(ContentImageService::class);
        $disk = Storage::disk('public');

        // Разрешаем пустой сайт: приводим к int или null
        $resolvedSiteId = $this->uploadSiteId ?? $this->siteFilter;
        if ($resolvedSiteId === '' || $resolvedSiteId === false) {
            $resolvedSiteId = null;
        }
        $resolvedSiteId = $resolvedSiteId !== null ? (int) $resolvedSiteId : null;

        $created = 0;
        foreach ($files as $file) {
            if (! $file) {
                continue;
            }
            // Конвертация в webp + транслитерация имени
            $path = $contentImageService->processContentImage($file, 'media');
            if (! $path) {
                continue;
            }

            $fullPath = $disk->path($path);
            $size = $disk->size($path);
            $width = null;
            $height = null;
            try {
                $dim = @getimagesize($fullPath);
                if ($dim) {
                    $width = $dim[0] ?? null;
                    $height = $dim[1] ?? null;
                }
            } catch (Throwable) {
                // ignore dimension errors
            }

            // Определяем корректный MIME по сохранённому файлу
            $mime = null;
            try {
                $mime = $disk->mimeType($path) ?: null;
            } catch (Throwable) {
            }
            if (! $mime) {
                $mime = 'image/webp';
            }

            $media = Media::create([
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

            if ($media) {
                $created++;
            }
        }

        $this->activeTab = 'gallery';
        $this->resetUploadForm();
        $this->selectedMedia = null; // Предлагаем выбрать изображение вручную перед вставкой
        $this->resetPage(); // Обновим пагинацию, чтобы увидеть новые элементы

        if ($created > 0) {
            Session::flash('success', trans_choice('Загружен :count файл|Загружено :count файла|Загружено :count файлов', $created));
        } else {
            Session::flash('error', 'Не удалось загрузить файлы.');
        }
    }

    public function deleteMedia($mediaId): void
    {
        $media = Media::find($mediaId);

        if (! $media) {
            Session::flash('error', 'Файл не найден.');

            return;
        }

        try {
            $mediaService = app(MediaService::class);
            $mediaService->deleteMedia($media);

            if ($this->selectedMedia && (int) $this->selectedMedia->id === (int) $mediaId) {
                $this->selectedMedia = null;
                $this->selectedMediaId = null;
                $this->editFilenameBase = '';
                $this->editAlt = '';
            }

            // Обновим список и пагинацию
            $this->resetPage();

            Session::flash('success', 'Файл удален!');
        } catch (Throwable) {
            Session::flash('error', 'Не удалось удалить файл.');
        }
    }

    public function saveSelectedMediaChanges(): void
    {
        if (! $this->selectedMedia) {
            Session::flash('error', 'Сначала выберите файл.');

            return;
        }

        $validated = $this->validate([
            'editFilenameBase' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9]+[A-Za-z0-9\-_]*$/'],
            'editAlt' => ['nullable', 'string', 'max:255'],
            'editSiteId' => ['nullable', 'integer', 'exists:sites,id'], // Валидация сайта
        ]);

        try {
            $mediaService = app(MediaService::class);

            // Используем новый универсальный метод из сервиса
            $this->selectedMedia = $mediaService->updateMedia($this->selectedMedia, [
                'title' => $this->selectedMedia->title, // сохраняем старый или берем из ввода, если добавите
                'alt_text' => $validated['editAlt'],
                'site_id' => $validated['editSiteId'],
            ]);

            // Переименование если нужно
            $currentBase = pathinfo((string) $this->selectedMedia->filename, PATHINFO_FILENAME) ?: '';
            if ($validated['editFilenameBase'] !== $currentBase) {
                $this->selectedMedia = $mediaService->renameMedia($this->selectedMedia, $validated['editFilenameBase']);
            }

            // Обновляем поля в форме
            $this->editFilenameBase = pathinfo((string) $this->selectedMedia->filename, PATHINFO_FILENAME) ?: '';
            $this->editAlt = (string) ($this->selectedMedia->alt_text ?? '');
            $this->editSiteId = $this->selectedMedia->site_id;

            Session::flash('success', 'Изменения сохранены.');
        } catch (Throwable) {
            Session::flash('error', 'Не удалось сохранить изменения.');
        }
    }

    private function resetUploadForm(): void
    {
        $this->uploadedFiles = [];
        $this->uploadSiteId = $this->siteFilter; // сбрасываем выбор сайта к текущему сайту
    }

    public function updatedUploadedFiles(): void
    {
        if (! is_array($this->uploadedFiles)) {
            $this->uploadedFiles = [];

            return;
        }

        // Убираем null/false и переиндексируем, чтобы не было «дырок»
        $this->uploadedFiles = array_values(array_filter($this->uploadedFiles));

        if (count($this->uploadedFiles) > 20) {
            $this->uploadedFiles = array_slice($this->uploadedFiles, 0, 20);
            // Сообщаем об ограничении; в модалке используем addError
            $this->addError('uploadedFiles', 'Можно загрузить максимум 20 файлов за раз.');
        }

        // Per-file валидация (64 МБ на файл)
        $this->validate([
            'uploadedFiles.*' => 'image|max:65536',
        ]);
    }

    public function removeFile($index): void
    {
        if (isset($this->uploadedFiles[$index])) {
            unset($this->uploadedFiles[$index]);
            $this->uploadedFiles = array_values($this->uploadedFiles);
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingSiteFilter(): void
    {
        $this->resetPage();
        $this->selectedMediaId = null;
        $this->selectedMedia = null;
        $this->editFilenameBase = '';
        $this->editAlt = '';
        $this->editSiteId = null;
    }

    public function render(): Factory|View
    {
        $search = trim((string) $this->search);
        $query = Media::query()
            ->images()
            ->search($search)
            ->when($this->siteFilter, fn ($q) => $q->forSite($this->siteFilter))
            ->latest();

        return view('livewire.media-gallery-modal', [
            'mediaItems' => $query->paginate(12),
            'sites' => Site::all(),
        ]);
    }
}

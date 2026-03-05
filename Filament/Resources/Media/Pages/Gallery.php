<?php

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Resources\Media\MediaResource;
use App\Models\Media;
use App\Models\Site;
use App\Services\MediaService;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Session;
use Livewire\WithPagination;
use Throwable;

/**
 * @property-read LengthAwarePaginator<int, Media> $mediaItems
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Site> $sites
 */
class Gallery extends Page
{
    use WithPagination;

    protected static string $resource = MediaResource::class;

    protected static ?string $title = 'Медиагалерея';

    protected string $view = 'filament.resources.media.pages.gallery';

    // Filters and search
    public ?int $siteFilter = null;

    public string $search = '';

    // Selection state
    /** @var array<int, int> */
    public array $selectedIds = [];

    // Inline edit fields (when exactly one selected)
    public string $editFilenameBase = '';

    public string $editAlt = '';

    public ?int $editSiteId = null;

    /** @var array<string, array{except: mixed}> */
    protected array $queryString = [
        'search' => ['except' => ''],
        'siteFilter' => ['except' => null],
    ];

    public function mount(): void
    {
        $this->siteFilter = session('current_site_id') ?: null;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingSiteFilter(): void
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->syncEditFieldsFromSelection();
    }

    /** @return LengthAwarePaginator<int, Media> */
    public function getMediaItemsProperty(): LengthAwarePaginator
    {
        $search = trim($this->search);

        /** @var LengthAwarePaginator<int, Media> $paginator */
        $paginator = Media::query()
            ->images()
            ->search($search)
            ->when($this->siteFilter, fn (Builder $builder) => $builder->where('site_id', $this->siteFilter))
            ->latest()
            ->paginate(24);

        return $paginator;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Site> */
    public function getSitesProperty(): \Illuminate\Database\Eloquent\Collection
    {
        return Site::query()->orderBy('name')->get();
    }

    public function toggleSelect(int $mediaId): void
    {
        $key = array_search($mediaId, $this->selectedIds, true);
        if ($key !== false) {
            unset($this->selectedIds[$key]);
            $this->selectedIds = array_values($this->selectedIds);
        } else {
            $this->selectedIds[] = $mediaId;
        }
        $this->syncEditFieldsFromSelection();
    }

    protected function syncEditFieldsFromSelection(): void
    {
        if (count($this->selectedIds) === 1) {
            $media = Media::find($this->selectedIds[0]);
            if ($media instanceof Media) {
                $this->editFilenameBase = pathinfo((string) $media->filename, PATHINFO_FILENAME) ?: '';
                $this->editAlt = (string) ($media->alt_text ?? '');
                $this->editSiteId = $media->site_id;

                return;
            }
        }
        $this->editFilenameBase = '';
        $this->editAlt = '';
        $this->editSiteId = null;
    }

    public function saveSelected(): void
    {
        if (count($this->selectedIds) !== 1) {
            Session::flash('error', 'Выберите один файл для редактирования.');

            return;
        }

        $validated = $this->validate([
            'editFilenameBase' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9]+[A-Za-z0-9\-_]*$/'],
            'editAlt' => ['nullable', 'string', 'max:255'],
            'editSiteId' => ['nullable', 'integer', 'exists:sites,id'],
        ]);

        $media = Media::find($this->selectedIds[0]);
        if (! ($media instanceof Media)) {
            Session::flash('error', 'Файл не найден.');

            return;
        }

        try {
            $mediaService = app(MediaService::class);

            $mediaService->updateMedia($media, [
                'alt_text' => $validated['editAlt'],
                'site_id' => $validated['editSiteId'],
            ]);

            $currentBase = pathinfo((string) $media->filename, PATHINFO_FILENAME) ?: '';
            if ($validated['editFilenameBase'] !== $currentBase) {
                $media = $mediaService->renameMedia($media, $validated['editFilenameBase']);
            }

            $this->syncEditFieldsFromSelection();

            Session::flash('success', 'Изменения сохранены.');
        } catch (Throwable) {
            Session::flash('error', 'Не удалось сохранить изменения.');
        }
    }

    public function deleteSelected(): void
    {
        if ($this->selectedIds === []) {
            Session::flash('error', 'Не выбрано ни одного файла.');

            return;
        }

        $mediaService = app(MediaService::class);
        $deleted = 0;
        foreach ($this->selectedIds as $selectedId) {
            $media = Media::find($selectedId);
            if ($media && $mediaService->deleteMedia($media)) {
                $deleted++;
            }
        }
        $this->selectedIds = [];
        $this->resetPage();

        if ($deleted > 0) {
            Session::flash('success', "Удалено файлов: {$deleted}.");
        } else {
            Session::flash('error', 'Не удалось удалить файлы.');
        }
    }
}

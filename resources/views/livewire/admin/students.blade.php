<?php

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/*
 * Student Masterlist: a READ-ONLY view of the `students` table (the records the Data Center import maintains).
 *
 * There is no write action here: students change only through an import. Queries select explicit columns, so
 * google_subject and any other identity-provider data is never read. Only ids (never models) are stored in
 * component state, so nothing about a student is serialized into the page snapshot. The search text is kept out
 * of the URL on purpose (names and IDs would otherwise land in browser history and access logs); the filters
 * and the opened student's internal id may be deep-linked.
 */
new #[Layout('components.layouts.admin')] #[Title('Student Masterlist')] class extends Component
{
    use WithPagination;

    private const PER_PAGE = 25;

    private const MAX_SEARCH = 100;

    private const STATUSES = ['ACTIVE', 'INACTIVE'];

    public string $search = '';

    #[Url(as: 'college', except: '')]
    public string $college = '';

    #[Url(as: 'course', except: '')]
    public string $course = '';

    #[Url(as: 'year', except: '')]
    public string $year = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    /** Internal students.id of the opened student (deep link ?student=). */
    #[Url(as: 'student')]
    public ?int $selectedId = null;

    /** Whether the details modal is open. */
    public bool $detailOpen = false;

    public string $notice = '';

    // ------------------------------------------------------------------ actions

    public function mount(): void
    {
        $this->admin();
        $this->sanitizeFilters();

        if ($this->selectedId !== null) {
            if ($this->exists($this->selectedId)) {
                $this->detailOpen = true;
            } else {
                $this->selectedId = null;
                $this->notice = __('That student does not exist.');
            }
        }
    }

    public function selectStudent(int $id): void
    {
        $this->admin();
        $this->notice = '';

        if ($this->exists($id)) {
            $this->selectedId = $id;
            $this->detailOpen = true;
        }
    }

    /** The modal was dismissed in the browser (close button, Escape, backdrop): forget the selection and the ?student= link. */
    public function updatedDetailOpen(bool $open): void
    {
        $this->admin();

        if (! $open) {
            $this->selectedId = null;
        }
    }

    public function updatedSearch(): void
    {
        $this->search = mb_substr($this->search, 0, self::MAX_SEARCH);
        $this->resetPage();
    }

    public function updatedCollege(): void
    {
        $this->sanitizeFilters();
        $this->resetPage();
    }

    public function updatedCourse(): void
    {
        $this->sanitizeFilters();
        $this->resetPage();
    }

    public function updatedYear(): void
    {
        $this->sanitizeFilters();
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->sanitizeFilters();
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->admin();
        $this->reset('search', 'college', 'course', 'year', 'status');
        $this->resetPage();
    }

    // ------------------------------------------------------------------ view data

    /** @return list<string> */
    #[Computed]
    public function colleges(): array
    {
        return DB::table('students')->distinct()->orderBy('current_college')->pluck('current_college')->all();
    }

    /** Courses, narrowed to the chosen college. @return list<string> */
    #[Computed]
    public function courses(): array
    {
        return DB::table('students')
            ->when($this->college !== '', fn ($q) => $q->where('current_college', $this->college))
            ->distinct()->orderBy('current_course')->pluck('current_course')->all();
    }

    /** Year levels present in the data, in the configured order first. @return list<string> */
    #[Computed]
    public function years(): array
    {
        $present = DB::table('students')->distinct()->pluck('current_year_level')->all();
        $configured = array_keys((array) config('comelec.import.year_levels'));
        $known = array_values(array_intersect($configured, $present));
        $other = array_values(array_diff($present, $configured));
        sort($other);

        return [...$known, ...$other];
    }

    #[Computed]
    public function students(): LengthAwarePaginator
    {
        $query = DB::table('students')->select([
            'id', 'institutional_id', 'first_name', 'middle_name', 'last_name',
            'current_college', 'current_course', 'current_year_level', 'status',
        ]);

        // Every word must match the student ID or one of the name parts. '!' is the LIKE escape character
        // (portable: MySQL/MariaDB and SQLite treat a backslash escape differently).
        foreach ($this->searchWords() as $word) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
            $query->where(function ($q) use ($like) {
                foreach (['institutional_id', 'first_name', 'middle_name', 'last_name'] as $column) {
                    $q->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [$like]);
                }
            });
        }

        return $query
            ->when($this->college !== '', fn ($q) => $q->where('current_college', $this->college))
            ->when($this->course !== '', fn ($q) => $q->where('current_course', $this->course))
            ->when($this->year !== '', fn ($q) => $q->where('current_year_level', $this->year))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->orderBy('last_name')->orderBy('first_name')->orderBy('institutional_id')->orderBy('id')
            ->paginate(self::PER_PAGE);
    }

    /** The opened student plus the import that last loaded them. Explicit columns only. */
    #[Computed]
    public function student(): ?object
    {
        if ($this->selectedId === null) {
            return null;
        }

        return DB::table('students')
            ->leftJoin('import_batches', 'import_batches.id', '=', 'students.last_import_batch_id')
            ->where('students.id', $this->selectedId)
            ->select([
                'students.id', 'students.institutional_id', 'students.institutional_email',
                'students.first_name', 'students.middle_name', 'students.last_name',
                'students.current_college', 'students.current_course', 'students.current_year_level', 'students.status',
                'students.created_at', 'students.updated_at',
                'import_batches.id as batch_id', 'import_batches.source_filename', 'import_batches.academic_year',
                'import_batches.semester', 'import_batches.status as batch_status', 'import_batches.completed_at',
            ])
            ->first();
    }

    /** The most recent placements the Data Center recorded for the opened student. @return list<object> */
    #[Computed]
    public function placements(): array
    {
        if ($this->selectedId === null) {
            return [];
        }

        return DB::table('student_enrollments')
            ->join('import_batches', 'import_batches.id', '=', 'student_enrollments.import_batch_id')
            ->where('student_enrollments.student_id', $this->selectedId)
            ->orderByDesc('student_enrollments.effective_from')->orderByDesc('student_enrollments.id')
            ->limit(5)
            ->select([
                'student_enrollments.college', 'student_enrollments.course', 'student_enrollments.year_level',
                'student_enrollments.status', 'student_enrollments.effective_from',
                'import_batches.id as batch_id', 'import_batches.academic_year', 'import_batches.semester',
            ])
            ->get()->all();
    }

    // ------------------------------------------------------------------ internals

    private function admin(): void
    {
        abort_unless(Auth::guard('admin')->check(), 403);
    }

    private function exists(int $id): bool
    {
        return DB::table('students')->where('id', $id)->exists();
    }

    /** @return list<string> */
    private function searchWords(): array
    {
        $text = trim((string) preg_replace('/[\s,]+/u', ' ', mb_substr($this->search, 0, self::MAX_SEARCH)));

        return $text === '' ? [] : array_slice(explode(' ', $text), 0, 5);
    }

    /** Drop any filter value that is not offered, so a hand-edited URL cannot select something odd. */
    private function sanitizeFilters(): void
    {
        if ($this->college !== '' && ! in_array($this->college, $this->colleges(), true)) {
            $this->college = '';
        }
        if ($this->course !== '' && ! in_array($this->course, $this->courses(), true)) {
            $this->course = '';
        }
        if ($this->year !== '' && ! in_array($this->year, $this->years(), true)) {
            $this->year = '';
        }
        if (! in_array($this->status, ['', ...self::STATUSES], true)) {
            $this->status = '';
        }
    }
}; ?>

@php
    $fmtTime = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('M j, Y g:i A') : '—';
    $fullName = fn ($s) => trim($s->last_name.', '.$s->first_name.' '.($s->middle_name ?? ''));
    $filtered = $search !== '' || $college !== '' || $course !== '' || $year !== '' || $status !== '';
    $page = $this->students;
    $s = $this->student;
@endphp

<div class="space-y-8">
    <section class="space-y-2">
        <h2 class="ui-section-title">{{ __('Student Masterlist') }}</h2>
        <p class="max-w-3xl text-sm text-muted">
            {{ __('A read-only list of the student records loaded by the Data Center. Records change only through an import; nothing can be edited here.') }}
        </p>
    </section>

    @if ($notice !== '')
        <x-ui.alert tone="warning" wire:key="notice">{{ $notice }}</x-ui.alert>
    @endif

    <x-ui.panel :title="__('Find students')" :description="__('Search by student ID or name, and narrow by college, course, year level and status.')">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <flux:field class="md:col-span-2 xl:col-span-1">
                <flux:label>{{ __('Search') }}</flux:label>
                <flux:input type="search" icon="magnifying-glass" wire:model.live.debounce.300ms="search" maxlength="100" autocomplete="off" :placeholder="__('Student ID or name')" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('College') }}</flux:label>
                <flux:select wire:model.live="college">
                    <flux:select.option value="">{{ __('All colleges') }}</flux:select.option>
                    @foreach ($this->colleges as $option)
                        <flux:select.option :value="$option">{{ $option }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Course') }}</flux:label>
                <flux:select wire:model.live="course">
                    <flux:select.option value="">{{ __('All courses') }}</flux:select.option>
                    @foreach ($this->courses as $option)
                        <flux:select.option :value="$option">{{ $option }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Year level') }}</flux:label>
                <flux:select wire:model.live="year">
                    <flux:select.option value="">{{ __('All year levels') }}</flux:select.option>
                    @foreach ($this->years as $option)
                        <flux:select.option :value="$option">{{ $option }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Status') }}</flux:label>
                <flux:select wire:model.live="status">
                    <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                    <flux:select.option value="ACTIVE">{{ __('Active') }}</flux:select.option>
                    <flux:select.option value="INACTIVE">{{ __('Inactive') }}</flux:select.option>
                </flux:select>
            </flux:field>
        </div>

        @if ($filtered)
            <div class="mt-4">
                <x-ui.button size="sm" icon="x-mark" wire:click="clearFilters">{{ __('Clear search and filters') }}</x-ui.button>
            </div>
        @endif
    </x-ui.panel>

    <x-ui.panel :title="__('Students')" :description="$page->total() === 0 ? __('No students to show.') : __('Showing :from–:to of :total students.', ['from' => number_format($page->firstItem()), 'to' => number_format($page->lastItem()), 'total' => number_format($page->total())])">
        @if ($page->total() === 0)
            @if ($filtered)
                <x-ui.empty-state icon="magnifying-glass" :title="__('No students match')" :description="__('Try a different spelling, or clear the search and filters.')" />
            @else
                <x-ui.empty-state icon="user-group" :title="__('No students yet')" :description="__('Student records appear here after a Data Center import has been confirmed and processed.')" />
            @endif
        @else
            <div class="relative -mx-4 overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,college,course,year,status,nextPage,previousPage,gotoPage,clearFilters">
                <table class="w-full min-w-[48rem] text-left text-sm">
                    <caption class="sr-only">{{ __('Student Masterlist') }}</caption>
                    <thead>
                        <tr class="border-b border-line text-xs text-muted">
                            <th scope="col" class="px-4 py-2 font-semibold">{{ __('Student ID') }}</th>
                            <th scope="col" class="px-2 py-2 font-semibold">{{ __('Name') }}</th>
                            <th scope="col" class="px-2 py-2 font-semibold">{{ __('College / course') }}</th>
                            <th scope="col" class="px-2 py-2 font-semibold">{{ __('Year level') }}</th>
                            <th scope="col" class="px-2 py-2 font-semibold">{{ __('Status') }}</th>
                            <th scope="col" class="px-4 py-2"><span class="sr-only">{{ __('Details') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($page as $row)
                            <tr wire:key="student-{{ $row->id }}" @class(['bg-surface-subtle' => $selectedId === $row->id])>
                                <td class="px-4 py-3 font-mono text-xs">{{ $row->institutional_id }}</td>
                                <td class="px-2 py-3 font-semibold">{{ $fullName($row) }}</td>
                                <td class="px-2 py-3 text-muted">
                                    <span class="block">{{ $row->current_college }}</span>
                                    <span class="block text-xs">{{ $row->current_course }}</span>
                                </td>
                                <td class="px-2 py-3 text-muted">{{ $row->current_year_level }}</td>
                                <td class="px-2 py-3"><x-ui.status-badge :status="$row->status" /></td>
                                <td class="px-4 py-3 text-right">
                                    <x-ui.button size="sm" wire:click="selectStudent({{ $row->id }})" :aria-label="__('View details for :id', ['id' => $row->institutional_id])">{{ __('View') }}</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($page->hasPages())
                <nav class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-muted" aria-label="{{ __('Student pages') }}">
                    <x-ui.button size="sm" wire:click="previousPage" :disabled="$page->onFirstPage()">{{ __('Previous') }}</x-ui.button>
                    <span class="tabular-nums">{{ __('Page :p of :n', ['p' => $page->currentPage(), 'n' => $page->lastPage()]) }}</span>
                    <x-ui.button size="sm" wire:click="nextPage" :disabled="! $page->hasMorePages()">{{ __('Next') }}</x-ui.button>
                </nav>
            @endif
        @endif
    </x-ui.panel>

    {{-- Read-only details. Closing (button, Escape, backdrop) clears the selection and the ?student= link. --}}
    <flux:modal name="student-details" wire:model.live="detailOpen" scroll="body" class="w-full max-w-3xl bg-surface! text-ink">
        @if ($s !== null)
            <section aria-labelledby="student-title" class="space-y-6" wire:key="student-{{ $s->id }}-detail" x-init="$el.closest('dialog')?.setAttribute('aria-labelledby', 'student-title')">
                <div class="min-w-0 pe-10">
                    <h2 id="student-title" class="ui-section-title break-words">{{ $fullName($s) }}</h2>
                    <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted">
                        <span class="font-mono">{{ $s->institutional_id }}</span>
                        <x-ui.status-badge :status="$s->status" />
                    </p>
                </div>

                <x-ui.alert tone="info">{{ __('Read-only. A student record changes only when a Data Center import is confirmed and processed.') }}</x-ui.alert>

                <div class="grid gap-6 sm:grid-cols-2">
                    <div class="min-w-0">
                        <h3 class="ui-eyebrow">{{ __('Identity') }}</h3>
                        <dl class="mt-2 space-y-2 text-sm">
                            <div><dt class="text-muted">{{ __('Student ID') }}</dt><dd class="font-mono">{{ $s->institutional_id }}</dd></div>
                            <div><dt class="text-muted">{{ __('Last name') }}</dt><dd>{{ $s->last_name }}</dd></div>
                            <div><dt class="text-muted">{{ __('First name') }}</dt><dd>{{ $s->first_name }}</dd></div>
                            <div><dt class="text-muted">{{ __('Middle name') }}</dt><dd>{{ $s->middle_name ?? '—' }}</dd></div>
                            <div><dt class="text-muted">{{ __('Institutional email') }}</dt><dd class="break-all">{{ $s->institutional_email }}</dd></div>
                        </dl>
                    </div>

                    <div class="min-w-0">
                        <h3 class="ui-eyebrow">{{ __('Current academic placement') }}</h3>
                        <dl class="mt-2 space-y-2 text-sm">
                            <div><dt class="text-muted">{{ __('College') }}</dt><dd>{{ $s->current_college }}</dd></div>
                            <div><dt class="text-muted">{{ __('Course') }}</dt><dd>{{ $s->current_course }}</dd></div>
                            <div><dt class="text-muted">{{ __('Year level') }}</dt><dd>{{ $s->current_year_level }}</dd></div>
                            <div><dt class="text-muted">{{ __('Status') }}</dt><dd><x-ui.status-badge :status="$s->status" /></dd></div>
                            <div><dt class="text-muted">{{ __('Record updated') }}</dt><dd>{{ $fmtTime($s->updated_at) }}</dd></div>
                        </dl>
                    </div>
                </div>

                <div class="space-y-3">
                    <h3 class="ui-eyebrow">{{ __('Data Center import') }}</h3>
                    @if ($s->batch_id === null)
                        <x-ui.alert tone="warning">{{ __('This record was not loaded by a Data Center import.') }}</x-ui.alert>
                    @else
                        <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            <div class="min-w-0"><dt class="text-muted">{{ __('Last loaded by import') }}</dt><dd class="break-words">#{{ $s->batch_id }} · {{ $s->source_filename }}</dd></div>
                            <div><dt class="text-muted">{{ __('Term') }}</dt><dd>{{ $s->academic_year }} · {{ $s->semester }}</dd></div>
                            <div><dt class="text-muted">{{ __('Import status') }}</dt><dd><x-ui.status-badge :status="$s->batch_status" /></dd></div>
                            <div><dt class="text-muted">{{ __('Import completed') }}</dt><dd>{{ $fmtTime($s->completed_at) }}</dd></div>
                        </dl>
                    @endif

                    @if (count($this->placements) > 0)
                        <p class="text-xs text-muted">{{ __('Placements recorded by imports (most recent :n).', ['n' => count($this->placements)]) }}</p>
                        <div class="relative -mx-1 overflow-x-auto">
                            <table class="w-full min-w-[34rem] text-left text-sm">
                                <caption class="sr-only">{{ __('Placements recorded by imports') }}</caption>
                                <thead>
                                    <tr class="border-b border-line text-xs text-muted">
                                        <th scope="col" class="px-1 py-2 font-semibold">{{ __('Import') }}</th>
                                        <th scope="col" class="px-2 py-2 font-semibold">{{ __('Term') }}</th>
                                        <th scope="col" class="px-2 py-2 font-semibold">{{ __('College / course') }}</th>
                                        <th scope="col" class="px-2 py-2 font-semibold">{{ __('Year') }}</th>
                                        <th scope="col" class="px-2 py-2 font-semibold">{{ __('Status') }}</th>
                                        <th scope="col" class="px-2 py-2 font-semibold">{{ __('Effective') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    @foreach ($this->placements as $placement)
                                        <tr wire:key="placement-{{ $placement->batch_id }}">
                                            <td class="px-1 py-2 tabular-nums">#{{ $placement->batch_id }}</td>
                                            <td class="px-2 py-2 text-muted">{{ $placement->academic_year }} · {{ $placement->semester }}</td>
                                            <td class="px-2 py-2 text-muted">{{ $placement->college }} · {{ $placement->course }}</td>
                                            <td class="px-2 py-2 text-muted">{{ $placement->year_level }}</td>
                                            <td class="px-2 py-2"><x-ui.status-badge :status="$placement->status" /></td>
                                            <td class="px-2 py-2 text-muted">{{ $fmtTime($placement->effective_from) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </section>
        @endif
    </flux:modal>
</div>

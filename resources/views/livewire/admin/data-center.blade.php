<?php

use App\Jobs\ValidateImportBatch;
use App\Models\AdminUser;
use App\Models\ImportBatch;
use App\Services\StudentImport\BatchStateMachine;
use App\Services\StudentImport\ImportBatchService;
use App\Services\StudentImport\ImportTransitionException;
use App\Services\StudentImport\RowClassifier;
use App\Services\StudentImport\SourceFileException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/*
 * Student Import (the Data Center import): admin UI for the Phase 03A backend.
 *
 * This component only calls ImportBatchService and the existing queued jobs. It never writes a batch status
 * or counter, never parses a file, never reads raw_row_json, and takes the uploader from the admin guard.
 * Heavy work (validation, processing) always runs on the queue, never inside a Livewire request.
 */
new #[Layout('components.layouts.admin')] #[Title('Student Import')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    private const PAGE_ROWS = 25;

    private const PAGE_BATCHES = 10;

    /** Coarse failure codes (SourceFileException reasons + service codes) -> a message that carries no data. */
    private const FAILURES = [
        'UNSUPPORTED_FILE_TYPE' => 'The file type is not supported.',
        'FILE_TOO_LARGE' => 'The file is larger than the allowed size.',
        'FILE_UNREADABLE' => 'The stored file could not be read.',
        'EMPTY_FILE' => 'The file has no data rows.',
        'INVALID_ENCODING' => 'The file is not valid UTF-8 text (CSV) or could not be decoded.',
        'MISSING_REQUIRED_COLUMNS' => 'The file is missing one or more required columns.',
        'DUPLICATE_COLUMNS' => 'Two or more columns map to the same field.',
        'CHECKSUM_MISMATCH' => 'The stored file no longer matches the file that was uploaded, so it was not used.',
        'VALIDATION_ERROR' => 'Validation stopped because of an unexpected error. Nothing was applied.',
        'PROCESSING_ERROR' => 'Processing stopped because of an unexpected error. Rows already applied stay applied.',
        'INTEGRITY_MISMATCH' => 'The final reconciliation did not match, so the import was not marked complete. Nothing was corrected automatically.',
    ];

    public $file = null;

    public string $academicYear = '';

    public string $semester = '';

    #[Url(as: 'batch')]
    public ?int $batchId = null;

    #[Url(as: 'show')]
    public string $filter = '';

    public bool $acknowledged = false;

    /** Whether the import-details modal is open. The selected batch (?batch=) stays the source of truth for what it shows. */
    public bool $detailOpen = false;

    public string $notice = '';

    public string $noticeTone = 'success';

    /** ISO time a job was queued for the selected batch and has not visibly started yet. Bounded by AWAIT_SECONDS. */
    #[Locked]
    public ?string $awaitingSince = null;

    /** Batch state captured BEFORE the job was queued; any difference later means the job has already run. */
    #[Locked]
    public ?string $awaitingFingerprint = null;

    private const AWAIT_SECONDS = 120;

    public function mount(): void
    {
        // A deep link (?batch=<id>) opens the modal; an unknown id keeps the page-level "does not exist" alert instead.
        $this->detailOpen = $this->batchId !== null && ImportBatch::query()->whereKey($this->batchId)->exists();
    }

    // ------------------------------------------------------------------ actions

    public function stageFile(): void
    {
        $admin = $this->admin();
        $this->resetNotice();

        $this->academicYear = trim($this->academicYear);
        $this->semester = trim($this->semester);

        $maxKb = max(1, intdiv((int) config('comelec.import.max_file_bytes'), 1024));
        $this->validate([
            'file' => ['required', 'file', 'extensions:'.implode(',', (array) config('comelec.import.extensions')), 'max:'.$maxKb],
            'academicYear' => ['required', 'string', 'max:255'],
            'semester' => ['required', 'string', 'max:255'],
        ], [], ['file' => 'file', 'academicYear' => 'academic year', 'semester' => 'semester']);

        $name = (string) $this->file->getClientOriginalName();
        if (mb_strlen($name) > 255) {
            $this->addError('file', __('The file name is too long. Rename the file and try again.'));

            return;
        }

        try {
            // The uploader id comes from the admin guard only.
            $batch = app(ImportBatchService::class)->stage($this->file->getRealPath(), $name, $admin->id, $this->academicYear, $this->semester);
        } catch (SourceFileException $e) {
            $this->addError('file', __(self::FAILURES[$e->reason] ?? 'The file could not be used.'));

            return;
        } catch (Throwable $e) {
            report($e);
            $this->addError('file', __('The file could not be staged. Nothing was imported.'));

            return;
        } finally {
            $this->discardUpload();
        }

        $this->reset('file', 'academicYear', 'semester');
        $this->open($batch->id);

        if ($this->queueValidation($batch, $admin)) {
            $this->say(__('File staged. Validation has been queued and will run in the background.'));
        } else {
            $this->say(__('File staged, but validation could not be queued. Use "Queue validation" below to try again.'), 'warning');
        }
    }

    public function selectBatch(int $id): void
    {
        $this->admin();

        if (ImportBatch::query()->whereKey($id)->exists()) {
            $this->resetNotice();
            $this->open($id);
        }
    }

    public function closeBatch(): void
    {
        $this->admin();
        $this->batchId = null;
        $this->detailOpen = false;
        $this->resetSelection();
    }

    /** The modal was dismissed in the browser (close button, Escape, backdrop): forget the selection and the ?batch= link. */
    public function updatedDetailOpen(bool $open): void
    {
        $this->admin();

        if (! $open) {
            $this->batchId = null;
            $this->resetSelection();
        }
    }

    /** Queue (re-)validation. Only where the state machine allows a move to VALIDATING. */
    public function revalidate(int $id): void
    {
        $admin = $this->admin();
        $this->resetNotice();
        $batch = $this->find($id);

        if ($batch === null || ! $this->allowed($batch)['revalidate']) {
            $this->say(__('Validation cannot be queued for this import in its current state.'), 'warning');

            return;
        }

        $queued = $this->queueValidation($batch, $admin);

        $this->say(
            $queued ? __('Validation has been queued.') : __('Validation could not be queued. Try again shortly.'),
            $queued ? 'success' : 'warning',
        );
    }

    /** Confirm a PREVIEWED batch, then queue background processing through the service. */
    public function confirm(int $id): void
    {
        $admin = $this->admin();
        $this->resetNotice();
        $this->resetErrorBag('acknowledged');
        $batch = $this->find($id);
        $service = app(ImportBatchService::class);

        if ($batch === null || ! $this->allowed($batch)['confirm']) {
            $this->say(__('This import cannot be confirmed in its current state.'), 'warning');

            return;
        }

        $counts = $service->classificationCounts($id);
        $applicable = $this->applicable($counts);
        $unapplied = array_sum($counts) - $applicable;

        if ($applicable === 0) {
            $this->say(__('Nothing in this import can be applied, so it cannot be confirmed. Correct the file and upload it again.'), 'danger');

            return;
        }

        if ($unapplied > 0 && ! $this->acknowledged) {
            $this->addError('acknowledged', __('Confirm that you have reviewed the rows that will not be applied.'));

            return;
        }

        try {
            $service->confirm($batch, $admin->id);
        } catch (ImportTransitionException) {
            $this->say(__('This import changed state while you were reviewing it. Nothing was confirmed.'), 'warning');

            return;
        }

        $this->acknowledged = false;

        try {
            $service->startProcessing($batch, $admin->id);
            $this->say(__('Import confirmed. Processing has been queued and will run in the background.'));
        } catch (ImportTransitionException) {
            $this->say(__('Import confirmed, but processing could not be started from its current state.'), 'warning');
        } catch (Throwable $e) {
            report($e);
            $this->say(__('Import confirmed, but processing may not have been queued. Check the status below.'), 'warning');
        }
    }

    /** Start (CONFIRMED) or resume (FAILED after confirmation) processing. */
    public function startProcessing(int $id): void
    {
        $admin = $this->admin();
        $this->resetNotice();
        $batch = $this->find($id);

        if ($batch === null || ! $this->allowed($batch)['process']) {
            $this->say(__('Processing cannot be started for this import in its current state.'), 'warning');

            return;
        }

        try {
            app(ImportBatchService::class)->startProcessing($batch, $admin->id);
            $this->say(__('Processing has been queued and will run in the background.'));
        } catch (ImportTransitionException) {
            $this->say(__('This import changed state first. Nothing was started.'), 'warning');
        } catch (Throwable $e) {
            report($e);
            $this->say(__('Processing may not have been queued. Check the status below.'), 'warning');
        }
    }

    /** Poll target: clears the "waiting for a worker" flag once the batch has moved. Reads only. */
    public function tick(): void
    {
        if ($this->awaitingSince === null) {
            return;
        }

        if (! $this->isAwaiting() || $this->batchId === null || $this->fingerprint($this->batchId) !== $this->awaitingFingerprint) {
            $this->clearAwaiting();
        }
    }

    public function updatedAcknowledged(): void
    {
        $this->resetErrorBag('acknowledged');
    }

    public function updatedFilter(): void
    {
        if (! in_array($this->filter, ['', ...$this->classifications()], true)) {
            $this->filter = '';
        }
        $this->resetPage('preview');
    }

    // ------------------------------------------------------------------ view data

    #[Computed]
    public function batches(): LengthAwarePaginator
    {
        return ImportBatch::query()
            ->leftJoin('admin_users', 'admin_users.id', '=', 'import_batches.uploaded_by')
            ->select('import_batches.*', 'admin_users.display_name as uploader_name')
            ->orderByDesc('import_batches.created_at')
            ->orderByDesc('import_batches.id')
            ->paginate(self::PAGE_BATCHES, ['*'], 'batches');
    }

    #[Computed]
    public function batch(): ?ImportBatch
    {
        if ($this->batchId === null) {
            return null;
        }

        return ImportBatch::query()
            ->leftJoin('admin_users', 'admin_users.id', '=', 'import_batches.uploaded_by')
            ->select('import_batches.*', 'admin_users.display_name as uploader_name')
            ->where('import_batches.id', $this->batchId)
            ->first();
    }

    /** @return array<string, int> classification => rows */
    #[Computed]
    public function counts(): array
    {
        return $this->batch === null ? [] : app(ImportBatchService::class)->classificationCounts((int) $this->batch->id);
    }

    /** @return array{applicable: int, applied: int} */
    #[Computed]
    public function progress(): array
    {
        $query = DB::table('import_batch_rows')
            ->where('import_batch_id', $this->batchId)
            ->whereIn('classification', [RowClassifier::UPDATED, RowClassifier::NEW]);

        return [
            'applicable' => (int) (clone $query)->count(),
            'applied' => (int) (clone $query)->whereNotNull('processed_at')->count(),
        ];
    }

    /** @return array{revalidate: bool, confirm: bool, process: bool} */
    #[Computed]
    public function actions(): array
    {
        return $this->batch === null
            ? ['revalidate' => false, 'confirm' => false, 'process' => false]
            : $this->allowed($this->batch);
    }

    /** A coarse, fixed-text reason for a FAILED batch (the code is read from the audit event; no exception text exists here). */
    #[Computed]
    public function failure(): ?string
    {
        if ($this->batch === null || $this->batch->status !== BatchStateMachine::FAILED) {
            return null;
        }

        $metadata = DB::table('audit_logs')
            ->where('event_type', 'import.batch.failed')
            ->where('target_type', 'import_batch')
            ->where('target_id', (string) $this->batch->id)
            ->orderByDesc('id')
            ->value('metadata_json');

        $code = is_string($metadata) ? (json_decode($metadata, true)['failure_code'] ?? null) : null;

        return __(self::FAILURES[$code] ?? 'The import failed. Technical details are not shown here.');
    }

    /** Server-side paginated preview of the NORMALIZED staged rows (never raw_row_json). */
    #[Computed]
    public function rows(): ?LengthAwarePaginator
    {
        if ($this->batch === null) {
            return null;
        }

        return DB::table('import_batch_rows')
            ->where('import_batch_id', $this->batch->id)
            ->when(in_array($this->filter, $this->classifications(), true), fn ($q) => $q->where('classification', $this->filter))
            ->orderBy('source_row_number')
            ->orderBy('id')
            ->select(['id', 'source_row_number', 'institutional_id', 'classification', 'normalized_json', 'issues_json', 'processed_at'])
            ->paginate(self::PAGE_ROWS, ['*'], 'preview')
            ->through(fn (object $row) => $this->present($row));
    }

    /** Poll only while something is running, or a job is waiting for a worker. */
    #[Computed]
    public function polling(): bool
    {
        if ($this->isAwaiting()) {
            return true;
        }

        if ($this->batch !== null && in_array($this->batch->status, [BatchStateMachine::VALIDATING, BatchStateMachine::PROCESSING], true)) {
            return true;
        }

        return collect($this->batches->items())->contains(fn ($b) => in_array($b->status, [BatchStateMachine::VALIDATING, BatchStateMachine::PROCESSING], true));
    }

    // ------------------------------------------------------------------ internals

    private function admin(): AdminUser
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof AdminUser, 403);

        return $admin;
    }

    private function find(int $id): ?ImportBatch
    {
        return ImportBatch::query()->find($id);
    }

    /** What the EXISTING state machine allows right now. The UI offers nothing more, and every action re-checks it. */
    private function allowed(ImportBatch $batch): array
    {
        $status = (string) $batch->status;
        $confirmed = $batch->confirmed_at !== null;

        return [
            'revalidate' => in_array($status, [BatchStateMachine::STAGED, BatchStateMachine::PREVIEWED, BatchStateMachine::FAILED], true)
                && BatchStateMachine::canTransition($status, BatchStateMachine::VALIDATING, $confirmed),
            'confirm' => $status === BatchStateMachine::PREVIEWED
                && BatchStateMachine::canTransition($status, BatchStateMachine::CONFIRMED, $confirmed),
            'process' => in_array($status, [BatchStateMachine::CONFIRMED, BatchStateMachine::FAILED], true)
                && BatchStateMachine::canTransition($status, BatchStateMachine::PROCESSING, $confirmed),
        ];
    }

    private function queueValidation(ImportBatch $batch, AdminUser $admin): bool
    {
        // Capture BEFORE dispatching: a synchronous queue (or a very fast worker) may finish inside dispatch().
        $before = $this->fingerprint((int) $batch->id);

        try {
            dispatch(new ValidateImportBatch((int) $batch->id, $admin->id));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        $this->await((int) $batch->id, $before);

        return true;
    }

    /** Start waiting only if the batch has not already changed since $before. */
    private function await(int $id, string $before): void
    {
        if ($this->fingerprint($id) === $before) {
            $this->awaitingSince = now()->toIso8601String();
            $this->awaitingFingerprint = $before;
        } else {
            $this->clearAwaiting();
        }
    }

    /** Non-mutating: waiting, and not past the time limit (a worker that never starts must not poll forever). */
    private function isAwaiting(): bool
    {
        return $this->awaitingSince !== null
            && Carbon::parse($this->awaitingSince)->addSeconds(self::AWAIT_SECONDS)->isFuture();
    }

    private function clearAwaiting(): void
    {
        $this->awaitingSince = null;
        $this->awaitingFingerprint = null;
    }

    /** Changes whenever the batch moves or a validation / failure is recorded, even within one second. */
    private function fingerprint(int $id): string
    {
        $batch = ImportBatch::query()->find($id);
        $events = DB::table('audit_logs')
            ->where('target_type', 'import_batch')
            ->where('target_id', (string) $id)
            ->whereIn('event_type', ['import.batch.validated', 'import.batch.failed'])
            ->count();

        return implode('|', [$batch?->status, $batch?->updated_at?->format('Y-m-d H:i:s.u'), $events]);
    }

    /** @param array<string, int> $counts */
    private function applicable(array $counts): int
    {
        return (int) (($counts[RowClassifier::UPDATED] ?? 0) + ($counts[RowClassifier::NEW] ?? 0));
    }

    /** @return list<string> */
    private function classifications(): array
    {
        return [
            RowClassifier::UPDATED,
            RowClassifier::NEW,
            RowClassifier::DUPLICATE_IN_FILE,
            RowClassifier::INVALID,
            RowClassifier::NEEDS_EXCEPTION_REVIEW,
        ];
    }

    private function open(int $id): void
    {
        $this->batchId = $id;
        $this->detailOpen = true;
        $this->resetSelection();
    }

    private function resetSelection(): void
    {
        $this->filter = '';
        $this->acknowledged = false;
        $this->clearAwaiting();
        $this->resetPage('preview');
        $this->resetErrorBag();
    }

    private function say(string $message, string $tone = 'success'): void
    {
        $this->notice = $message;
        $this->noticeTone = $tone;
    }

    private function resetNotice(): void
    {
        $this->notice = '';
        $this->noticeTone = 'success';
    }

    private function discardUpload(): void
    {
        try {
            $this->file?->delete();
        } catch (Throwable) {
            // best effort: Livewire's temporary-upload cleanup also removes it
        }
    }

    /**
     * One staged row for display. Reads normalized_json and issues_json only; raw_row_json is never selected.
     *
     * @return array<string, mixed>
     */
    private function present(object $row): array
    {
        $normalized = json_decode((string) $row->normalized_json, true);
        $values = is_array($normalized) ? ($normalized['values'] ?? []) : [];
        $preview = is_array($normalized) ? ($normalized['preview'] ?? null) : null;
        $issues = collect(json_decode((string) $row->issues_json, true) ?: [])
            ->filter(fn ($i) => is_array($i) && isset($i['code']))
            ->map(fn ($i) => ['code' => (string) $i['code'], 'field' => $i['field'] ?? null])
            ->values()->all();

        $name = trim(($values['last_name'] ?? '').', '.trim(($values['first_name'] ?? '').' '.($values['middle_name'] ?? '')), ' ,');

        return [
            'key' => $row->id,
            'row' => $row->source_row_number,
            'student_id' => $row->institutional_id,
            'name' => $name,
            'college' => $values['college'] ?? null,
            'course' => $values['course'] ?? null,
            'year' => $preview['resulting_year_level'] ?? $values['year_level'] ?? null,
            'classification' => $row->classification,
            'issues' => $issues,
            'course_changed' => (bool) ($preview['course_changed'] ?? false),
            'changed_fields' => (array) ($preview['changed_fields'] ?? []),
            'has_preview' => $preview !== null,
            'applied' => $row->processed_at !== null,
        ];
    }
}; ?>

@php
    $classLabels = [
        'UPDATED' => ['Update existing student', 'success'],
        'NEW' => ['New student', 'success'],
        'DUPLICATE_IN_FILE' => ['Duplicate in file', 'warning'],
        'INVALID' => ['Invalid', 'danger'],
        'NEEDS_EXCEPTION_REVIEW' => ['Needs exception review', 'warning'],
    ];
    $issueLabels = [
        'ID_MISSING' => 'Student ID is missing.',
        'ID_TOO_LONG' => 'Student ID is too long.',
        'ID_CONTROL_CHARACTERS' => 'Student ID contains control characters.',
        'ID_NUMERIC_FORMAT_ARTIFACT' => 'Student ID looks like a spreadsheet number format (for example 2.0E7).',
        'LAST_NAME_MISSING' => 'Last name is missing.',
        'FIRST_NAME_MISSING' => 'First name is missing.',
        'NAME_TOO_LONG' => 'A name is too long.',
        'COLLEGE_MISSING' => 'College is missing.',
        'COLLEGE_TOO_LONG' => 'College is too long.',
        'COURSE_MISSING' => 'Course is missing.',
        'COURSE_TOO_LONG' => 'Course is too long.',
        'YEAR_MISSING' => 'Year level is missing.',
        'YEAR_INVALID' => 'Year level is not recognised.',
        'STATUS_INVALID' => 'Status must be ACTIVE or INACTIVE.',
        'DUPLICATE_ID_REPEAT' => 'Same ID repeated with identical content; the first occurrence is used and this one is skipped.',
        'DUPLICATE_ID_CONFLICT' => 'Same ID with different content; no occurrence is applied until the file is corrected.',
        'COLLEGE_CASE_VARIANT' => 'College spelling differs only by letter case.',
        'COURSE_CASE_VARIANT' => 'Course spelling differs only by letter case.',
        'NEW_STUDENT_REQUIRES_INSTITUTIONAL_EMAIL' => 'No existing student has this ID, and it is not a supported 7-digit legacy or 10-digit current ID for generating an institutional email.',
    ];
    $fieldLabels = ['first_name' => 'First name', 'middle_name' => 'Middle name', 'last_name' => 'Last name', 'college' => 'College', 'course' => 'Course', 'year_level' => 'Year level', 'status' => 'Status'];
    $fmtTime = fn ($t) => $t ? $t->format('M j, Y g:i A') : '—';
    $active = [\App\Services\StudentImport\BatchStateMachine::STAGED, \App\Services\StudentImport\BatchStateMachine::VALIDATING, \App\Services\StudentImport\BatchStateMachine::CONFIRMED, \App\Services\StudentImport\BatchStateMachine::PROCESSING];
    $b = $this->batch;
    $counts = $this->counts;
    $total = array_sum($counts);
    $applicable = ($counts['UPDATED'] ?? 0) + ($counts['NEW'] ?? 0);
    $unapplied = $total - $applicable;
    $maxMb = rtrim(rtrim(number_format(((int) config('comelec.import.max_file_bytes')) / 1048576, 1), '0'), '.');
@endphp

<div class="space-y-8">
    {{-- Polling exists only while something is running or a job is waiting for a worker. --}}
    @if ($this->polling)
        <div wire:key="data-center-poller" wire:poll.5s="tick" aria-hidden="true"></div>
    @endif

    <section class="space-y-2">
        <h2 class="ui-section-title">{{ __('Student Import') }}</h2>
        <p class="max-w-3xl text-sm text-muted">
            {{ __('Upload the official Data Center file (CSV or XLSX), review the classified preview, then confirm to apply it. Validation and processing run in the background; this page shows their recorded progress.') }}
        </p>
    </section>

    @if ($notice !== '')
        <x-ui.alert :tone="$noticeTone" wire:key="notice">{{ $notice }}</x-ui.alert>
    @endif

    <div class="grid items-start gap-6 xl:grid-cols-5">
        {{-- Upload --}}
        <x-ui.panel class="min-w-0 xl:col-span-2" :title="__('New import')" :description="__('Stages the file and queues validation. Nothing is applied yet.')">
            <form wire:submit="stageFile" class="space-y-4" novalidate>
                <flux:field>
                    <flux:label>{{ __('File (CSV or XLSX)') }}</flux:label>
                    <flux:input type="file" wire:model="file" accept=".csv,.xlsx" />
                    <flux:description>{{ __('Up to :mb MB. A lower hosting upload limit may also apply.', ['mb' => $maxMb]) }}</flux:description>
                    <div wire:loading wire:target="file" class="text-sm text-muted" role="status">{{ __('Uploading…') }}</div>
                    <flux:error name="file" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Academic year') }}</flux:label>
                    <flux:input wire:model="academicYear" maxlength="255" autocomplete="off" />
                    <flux:error name="academicYear" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Semester') }}</flux:label>
                    <flux:input wire:model="semester" maxlength="255" autocomplete="off" />
                    <flux:description>{{ __('Academic year and semester are free text. Leading and trailing spaces are trimmed; they are not otherwise validated and do not decide which data is newer.') }}</flux:description>
                    <flux:error name="semester" />
                </flux:field>

                <x-ui.button type="submit" variant="primary" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="stageFile,file">
                    {{ __('Upload and validate') }}
                </x-ui.button>
            </form>
        </x-ui.panel>

        {{-- Recent imports --}}
        <x-ui.panel class="min-w-0 xl:col-span-3" :title="__('Recent imports')" :description="__('Newest first. Counters are the values recorded on each batch.')">
            @php $list = $this->batches; @endphp
            @if ($list->isEmpty())
                <x-ui.empty-state icon="circle-stack" :title="__('No imports yet')" :description="__('Upload a file to create the first import.')" />
            @else
                <div class="relative -mx-4 overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-left text-sm">
                        <caption class="sr-only">{{ __('Recent imports') }}</caption>
                        <thead>
                            <tr class="border-b border-line text-xs text-muted">
                                <th scope="col" class="px-4 py-2 font-semibold">{{ __('Import') }}</th>
                                <th scope="col" class="px-2 py-2 font-semibold">{{ __('Term') }}</th>
                                <th scope="col" class="px-2 py-2 font-semibold">{{ __('Status') }}</th>
                                <th scope="col" class="px-2 py-2 text-right font-semibold">{{ __('Received') }}</th>
                                <th scope="col" class="px-2 py-2 text-right font-semibold">{{ __('Updated') }}</th>
                                <th scope="col" class="px-2 py-2 text-right font-semibold">{{ __('Not applied') }}</th>
                                <th scope="col" class="px-4 py-2"><span class="sr-only">{{ __('Open') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($list as $item)
                                <tr wire:key="batch-{{ $item->id }}" @class(['bg-surface-subtle' => $batchId === $item->id])>
                                    <td class="px-4 py-3">
                                        <p class="max-w-56 truncate font-semibold" title="{{ $item->source_filename }}">#{{ $item->id }} · {{ $item->source_filename }}</p>
                                        <p class="text-xs text-muted">{{ $item->uploader_name ?? '—' }} · {{ $fmtTime($item->created_at) }}</p>
                                    </td>
                                    <td class="px-2 py-3 text-muted">{{ $item->academic_year }} · {{ $item->semester }}</td>
                                    <td class="px-2 py-3"><x-ui.status-badge :status="$item->status" /></td>
                                    <td class="px-2 py-3 text-right tabular-nums">{{ number_format($item->records_received) }}</td>
                                    <td class="px-2 py-3 text-right tabular-nums">{{ number_format($item->records_updated) }}</td>
                                    <td class="px-2 py-3 text-right tabular-nums">{{ number_format($item->records_errored) }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <x-ui.button size="sm" wire:click="selectBatch({{ $item->id }})" :aria-label="__('Open import #:id', ['id' => $item->id])">{{ __('Open') }}</x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($list->hasPages())
                    <div class="mt-3 flex items-center justify-between gap-3 text-sm text-muted">
                        <span>{{ __('Page :p of :n', ['p' => $list->currentPage(), 'n' => $list->lastPage()]) }}</span>
                        <span class="flex gap-2">
                            <x-ui.button size="sm" wire:click="previousPage('batches')" :disabled="$list->onFirstPage()">{{ __('Previous') }}</x-ui.button>
                            <x-ui.button size="sm" wire:click="nextPage('batches')" :disabled="! $list->hasMorePages()">{{ __('Next') }}</x-ui.button>
                        </span>
                    </div>
                @endif
            @endif
        </x-ui.panel>
    </div>

    {{-- Selected import --}}
    @if ($batchId !== null && $b === null)
        <x-ui.alert tone="warning" wire:key="missing">{{ __('That import does not exist.') }}</x-ui.alert>
    @endif

    <flux:modal name="import-details" wire:model.live="detailOpen" scroll="body" class="w-full max-w-6xl bg-surface! text-ink">
    @if ($b !== null)
        <section aria-labelledby="batch-title" class="space-y-6" wire:key="batch-{{ $b->id }}-detail" x-init="$el.closest('dialog')?.setAttribute('aria-labelledby', 'batch-title')">
            <div class="flex flex-wrap items-start justify-between gap-3 pe-10">
                <div class="min-w-0">
                    <h2 id="batch-title" class="ui-section-title break-words">{{ __('Import #:id', ['id' => $b->id]) }} · {{ $b->source_filename }}</h2>
                    <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted">
                        <x-ui.status-badge :status="$b->status" />
                        <span>{{ $b->academic_year }} · {{ $b->semester }}</span>
                        <span>{{ __('Uploaded by :name', ['name' => $b->uploader_name ?? '—']) }}</span>
                    </p>
                </div>
            </div>

            <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    ['Rows received', $b->records_received],
                    ['Existing students updated', $b->records_updated],
                    ['New students created', $b->records_created],
                    ['Rows not applied', $b->records_errored],
                ] as [$label, $value])
                    <x-ui.stat-card
                        :label="__($label)"
                        :value="number_format($value)"
                        :hint="__('Recorded on the batch')"
                    />
                @endforeach
            </dl>

            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([['Staged', $b->created_at], ['Confirmed', $b->confirmed_at], ['Processing started', $b->started_at], ['Completed', $b->completed_at]] as [$label, $when])
                    <div><dt class="ui-eyebrow">{{ __($label) }}</dt><dd class="mt-0.5">{{ $fmtTime($when) }}</dd></div>
                @endforeach
            </dl>

            {{-- State-specific block --}}
            @switch($b->status)
            @case('STAGED')
                <x-ui.alert tone="info" :title="__('Waiting for validation')">
                    <div class="flex items-start gap-3">
                        <span
                            aria-hidden="true"
                            class="mt-1 inline-block h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-current border-r-transparent motion-reduce:animate-none"
                        ></span>

                        <div class="min-w-0 flex-1">
                            <p>
                                {{ __('The file is staged and validation is queued. This page refreshes while it runs. If it does not move, the queue worker may not be running.') }}
                            </p>

                            @if ($this->actions['revalidate'])
                                <div class="mt-2">
                                    <x-ui.button size="sm" wire:click="revalidate({{ $b->id }})">
                                        {{ __('Queue validation') }}
                                    </x-ui.button>
                                </div>
                            @endif
                        </div>
                    </div>
                </x-ui.alert>
                @break
                @case('VALIDATING')
                <x-ui.alert tone="info" :title="__('Validating')">
                    <div class="flex items-start gap-3">
                        <span
                            aria-hidden="true"
                            class="mt-1 inline-block h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-current border-r-transparent motion-reduce:animate-none"
                        ></span>

                        <div class="min-w-0 flex-1">
                            <p>
                                {{ __('The file is being checked and classified in the background. This page refreshes automatically.') }}
                            </p>
                            <p class="mt-1 text-sm text-muted">
                                {{ __('No student records are changed during validation.') }}
                            </p>
                        </div>
                    </div>
                </x-ui.alert>
                @break

                @case('CONFIRMED')
                    <x-ui.alert tone="info" :title="__('Confirmed, processing not started')">
                        {{ __('This import is confirmed. Processing has not started yet.') }}
                        @if ($this->actions['process'])
                            <div class="mt-2"><x-ui.button size="sm" variant="primary" wire:click="startProcessing({{ $b->id }})">{{ __('Start processing') }}</x-ui.button></div>
                        @endif
                    </x-ui.alert>
                    @break

                @case('PROCESSING')
                    @php $p = $this->progress; $pct = $p['applicable'] > 0 ? min(100, (int) floor($p['applied'] / $p['applicable'] * 100)) : 0; @endphp
                    <x-ui.panel :title="__('Processing')" :description="__('Applying rows in the background. This page refreshes automatically.')">
                        <div role="progressbar" aria-label="{{ __('Rows applied') }}" aria-valuemin="0" aria-valuemax="{{ $p['applicable'] }}" aria-valuenow="{{ $p['applied'] }}">
                            <div class="h-2.5 overflow-hidden rounded-full bg-surface-sunken"><div class="h-full rounded-full bg-accent" style="width: {{ $pct }}%"></div></div>
                        </div>
                        <p class="mt-2 text-sm text-muted tabular-nums">{{ __(':applied of :total applicable rows applied.', ['applied' => number_format($p['applied']), 'total' => number_format($p['applicable'])]) }}</p>
                    </x-ui.panel>
                    @break

                @case('COMPLETED')
                    <x-ui.alert tone="success" :title="__('Import completed')">
                        {{ __(':updated existing students were updated. :errored of :received rows were not applied.', ['updated' => number_format($b->records_updated), 'errored' => number_format($b->records_errored), 'received' => number_format($b->records_received)]) }}
                    </x-ui.alert>
                    @break

                @case('FAILED')
                    <x-ui.alert tone="danger" :title="$b->confirmed_at ? __('Processing failed') : __('Validation failed')">
                        <p>{{ $this->failure }}</p>
                        @if ($b->confirmed_at)
                            <p class="mt-1">{{ __('Rows already applied stay applied. Resuming continues from the first unprocessed row.') }}</p>
                        @else
                            <p class="mt-1">{{ __('Nothing was applied. If the file itself is the problem, correct it and upload it as a new import.') }}</p>
                        @endif
                        <div class="mt-2 flex flex-wrap gap-2">
                            @if ($this->actions['process'])
                                <x-ui.button size="sm" variant="primary" wire:click="startProcessing({{ $b->id }})">{{ __('Resume processing') }}</x-ui.button>
                            @endif
                            @if ($this->actions['revalidate'])
                                <x-ui.button size="sm" wire:click="revalidate({{ $b->id }})">{{ __('Re-run validation') }}</x-ui.button>
                            @endif
                        </div>
                    </x-ui.alert>
                    @break
            @endswitch

            {{-- Classification summary + review --}}
            @if ($total > 0)
                <x-ui.panel :title="__('Preview summary')" :description="__(':total staged rows by classification.', ['total' => number_format($total)])">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($counts as $classification => $n)
                            @php [$label, $tone] = $classLabels[$classification] ?? [$classification, 'neutral']; @endphp
                            <div class="flex items-center justify-between gap-3 rounded-ui border border-line bg-surface-subtle px-3 py-2">
                                <x-ui.badge :tone="$tone">{{ __($label) }}</x-ui.badge>
                                <span class="text-lg font-semibold tabular-nums">{{ number_format($n) }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-ui.panel>

                @if ($unapplied > 0)
                <x-ui.alert tone="warning" :title="__(':n rows will not be applied', ['n' => number_format($unapplied)])">
                    {{ __('Valid new students with unique 7-digit legacy or 10-digit current IDs will be created, with institutional emails generated from their IDs using the @student.buksu.edu.ph convention.') }}
                </x-ui.alert>
                @endif
            @endif

            @if ($b->status === 'PREVIEWED')
            <x-ui.panel :title="__('Confirm import')" :description="__('Applies :n eligible student rows in the background: updates existing students and creates valid new student records.', ['n' => number_format($applicable)])">
                    <div class="space-y-4">
                    <p class="text-sm text-muted">{{ __('Confirming cannot be undone from this page. Each processed student receives an append-only enrollment history entry.') }}</p>

                        @if ($unapplied > 0)
                            <flux:field variant="inline">
                                <flux:checkbox wire:model="acknowledged" :label="__('I have reviewed the :n rows that will not be applied.', ['n' => number_format($unapplied)])" />
                            </flux:field>
                        @endif

                        <div class="flex flex-wrap gap-2">
                            @if ($this->actions['confirm'])
                                <x-ui.button variant="primary" icon="check" :disabled="$applicable === 0"
                                    wire:click="confirm({{ $b->id }})"
                                    wire:confirm="{{ __('Confirm this import and start applying it? This cannot be undone from this page.') }}">{{ __('Confirm and start processing') }}</x-ui.button>
                            @endif
                            @if ($this->actions['revalidate'])
                                <x-ui.button wire:click="revalidate({{ $b->id }})">{{ __('Re-run validation') }}</x-ui.button>
                            @endif
                        </div>

                        @if ($applicable === 0)
                            <p class="text-sm text-danger-text">{{ __('Nothing in this import can be applied, so it cannot be confirmed.') }}</p>
                        @endif
                    </div>
                </x-ui.panel>
            @endif

            {{-- Row preview (normalized fields only) --}}
            @if ($total > 0)
                @php $page = $this->rows; @endphp
                <x-ui.panel :title="__('Row preview')" :description="__('Normalized values as they would be applied. The original source rows are not shown.')">
                    <div class="mb-3 flex flex-wrap gap-2" role="group" aria-label="{{ __('Filter rows by classification') }}">
                        <button type="button" wire:click="$set('filter', '')" aria-pressed="{{ $filter === '' ? 'true' : 'false' }}"
                            @class(['min-h-9 rounded-full border px-3 text-xs font-semibold focus-ui', 'border-accent bg-accent text-accent-foreground' => $filter === '', 'border-line text-muted hover:border-line-strong hover:text-ink' => $filter !== ''])>{{ __('All') }} ({{ number_format($total) }})</button>
                        @foreach ($counts as $classification => $n)
                            <button type="button" wire:click="$set('filter', '{{ $classification }}')" aria-pressed="{{ $filter === $classification ? 'true' : 'false' }}"
                                @class(['min-h-9 rounded-full border px-3 text-xs font-semibold focus-ui', 'border-accent bg-accent text-accent-foreground' => $filter === $classification, 'border-line text-muted hover:border-line-strong hover:text-ink' => $filter !== $classification])>{{ __($classLabels[$classification][0] ?? $classification) }} ({{ number_format($n) }})</button>
                        @endforeach
                    </div>

                    <div class="relative -mx-4 overflow-x-auto" wire:loading.class="opacity-60" wire:target="filter,nextPage,previousPage,gotoPage,$set">
                        <table class="w-full min-w-[56rem] text-left text-sm">
                            <caption class="sr-only">{{ __('Staged rows') }}</caption>
                            <thead>
                                <tr class="border-b border-line text-xs text-muted">
                                    <th scope="col" class="px-4 py-2 font-semibold">{{ __('File row') }}</th>
                                    <th scope="col" class="px-2 py-2 font-semibold">{{ __('Student ID') }}</th>
                                    <th scope="col" class="px-2 py-2 font-semibold">{{ __('Name') }}</th>
                                    <th scope="col" class="px-2 py-2 font-semibold">{{ __('College / course') }}</th>
                                    <th scope="col" class="px-2 py-2 font-semibold">{{ __('Year') }}</th>
                                    <th scope="col" class="px-2 py-2 font-semibold">{{ __('Classification') }}</th>
                                    <th scope="col" class="px-2 py-2 font-semibold">{{ __('Result') }}</th>
                                    <th scope="col" class="px-4 py-2 font-semibold">{{ __('Changes / issues') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line align-top">
                                @forelse ($page->items() as $r)
                                    @php
                                        [$label, $tone] = $classLabels[$r['classification']] ?? [$r['classification'], 'neutral'];
                                        $isApplicable = in_array($r['classification'], ['UPDATED', 'NEW'], true);
                                    @endphp
                                    <tr wire:key="row-{{ $r['key'] }}">
                                        <td class="px-4 py-3 tabular-nums text-muted">{{ $r['row'] ?? '—' }}</td>
                                        <td class="px-2 py-3 font-medium whitespace-nowrap">{{ $r['student_id'] !== '' ? $r['student_id'] : '—' }}</td>
                                        <td class="px-2 py-3">{{ $r['name'] !== '' ? $r['name'] : '—' }}</td>
                                        <td class="px-2 py-3">{{ $r['college'] ?? '—' }}<br><span class="text-muted">{{ $r['course'] ?? '—' }}</span></td>
                                        <td class="px-2 py-3 whitespace-nowrap">{{ $r['year'] ?? '—' }}</td>
                                        <td class="px-2 py-3"><x-ui.badge :tone="$tone">{{ __($label) }}</x-ui.badge></td>
                                        <td class="px-2 py-3 whitespace-nowrap">
                                            @if ($isApplicable && $r['applied'])
                                                <x-ui.badge tone="success">{{ __('Applied') }}</x-ui.badge>
                                            @elseif ($isApplicable && $b->status === 'FAILED')
                                                <x-ui.badge tone="warning">{{ __('Not applied yet') }}</x-ui.badge>
                                            @elseif ($isApplicable)
                                                <x-ui.badge tone="info">{{ __('Will apply') }}</x-ui.badge>
                                            @else
                                                <x-ui.badge tone="neutral">{{ __('Not applied') }}</x-ui.badge>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            @if ($r['has_preview'])
                                                @if ($r['course_changed'])
                                                    <p>{{ __('Course changed: year level becomes :y.', ['y' => $r['year'] ?? '—']) }}</p>
                                                @endif
                                                <p class="text-muted">
                                                    {{ $r['changed_fields'] === [] ? __('No changes to the current record.') : __('Changes: :f', ['f' => collect($r['changed_fields'])->map(fn ($f) => __($fieldLabels[$f] ?? $f))->implode(', ')]) }}
                                                </p>
                                            @endif
                                            @foreach ($r['issues'] as $issue)
                                                <p><span class="font-mono text-xs text-muted">{{ $issue['code'] }}</span> {{ __($issueLabels[$issue['code']] ?? '') }}</p>
                                            @endforeach
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="px-4 py-8 text-center text-muted">{{ __('No rows match this filter.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3 text-sm text-muted">
                        <span class="tabular-nums">
                            @if ($page->total() > 0)
                                {{ __('Rows :from–:to of :total', ['from' => number_format($page->firstItem()), 'to' => number_format($page->lastItem()), 'total' => number_format($page->total())]) }}
                            @endif
                        </span>
                        @if ($page->hasPages())
                            <span class="flex items-center gap-2">
                                <x-ui.button size="sm" wire:click="previousPage('preview')" :disabled="$page->onFirstPage()">{{ __('Previous') }}</x-ui.button>
                                <span class="tabular-nums">{{ __('Page :p of :n', ['p' => $page->currentPage(), 'n' => $page->lastPage()]) }}</span>
                                <x-ui.button size="sm" wire:click="nextPage('preview')" :disabled="! $page->hasMorePages()">{{ __('Next') }}</x-ui.button>
                            </span>
                        @endif
                    </div>
                </x-ui.panel>
            @elseif (in_array($b->status, ['PREVIEWED', 'COMPLETED'], true))
                <x-ui.empty-state icon="document-magnifying-glass" :title="__('No staged rows')" :description="__('This import has no staged rows to preview.')" />
            @endif
        </section>
    @endif
    </flux:modal>
</div>

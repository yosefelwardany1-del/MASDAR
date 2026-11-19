<?php

namespace App\Http\Controllers;

use App\Jobs\IO\RunResourceImportJob;
use App\Models\ImportRun;
use App\Support\IO\CsvTemplateGenerator;
use App\Support\IO\ImportResult;
use App\Support\IO\ImportRunner;
use App\Support\IO\ResourceExporter;
use App\Support\IO\ResourceRegistry;
use App\Support\IO\ResourceSchema;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Single entry point for the bulk Import/Export platform. Works for ANY registered
 * resource (ResourceRegistry) — no per-module controllers. Two gates: the route asks for
 * the import_export screen, and this controller asks for the resource's own screen.
 */
class ImportExportController extends Controller
{
    public function __construct(
        private ResourceRegistry $registry,
        private CsvTemplateGenerator $templates,
        private ResourceExporter $exporter,
        private ImportRunner $runner,
    ) {}

    /**
     * Import / Export Center — the resources the user may access + THEIR OWN recent
     * imports. Only their own: an import's errors quote the file's rows back.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $resources = collect($this->registry->all())
            ->filter(fn (ResourceSchema $schema) => (bool) $user->can($schema->permissionFor('view')))
            ->values();

        $history = ImportRun::where('user_id', $user->id)->latest('id')->limit(25)->get();

        return view('import-export.index', compact('resources', 'history'));
    }

    public function template(Request $request, string $resource)
    {
        $schema = $this->registry->get($resource);
        $this->authorizeModule($schema, 'view');
        $format = $this->format($request);

        $content = $format === 'xlsx' ? $this->templates->generateXlsx($schema) : $this->templates->generate($schema);

        return $this->download($content, $this->templates->filename($schema, $format), $format);
    }

    public function export(Request $request, string $resource)
    {
        $schema = $this->registry->get($resource);
        $this->authorizeModule($schema, 'view');
        $format = $this->format($request);

        $content = $format === 'xlsx' ? $this->exporter->toXlsx($schema, $request->user()) : $this->exporter->toCsv($schema, $request->user());

        return $this->download($content, $this->exporter->filename($schema, $format), $format);
    }

    public function preview(Request $request, string $resource)
    {
        $schema = $this->registry->get($resource);
        $this->authorizeModule($schema, 'edit');
        // Validate by uploaded filename extension, not `mimes`: PHP's content-based MIME
        // guess is unreliable for JSON/CSV (no magic bytes), so `mimes` rejects valid
        // exports on some servers. Each schema's parser validates the content downstream.
        $request->validate(['file' => ['required', 'file', 'extensions:csv,txt,xlsx,xls,json', 'max:20480']]);

        return $this->dispatchOrRun($request, $schema, $request->file('file'), preview: true);
    }

    public function import(Request $request, string $resource)
    {
        $schema = $this->registry->get($resource);
        $this->authorizeModule($schema, 'edit');
        $request->validate(['file' => ['required', 'file', 'extensions:csv,txt,xlsx,xls,json', 'max:20480']]);

        return $this->dispatchOrRun($request, $schema, $request->file('file'), preview: false);
    }

    /**
     * Progress for a queued run. Polled by the import screen every couple of seconds.
     *
     * Only the person who uploaded it: its error messages quote row contents back, and a
     * colleague with the same permissions has no business reading somebody else's upload.
     */
    public function status(Request $request, ImportRun $run)
    {
        $schema = $this->registry->get($run->resource);
        $this->authorizeModule($schema, $run->is_preview ? 'view' : 'edit');

        abort_unless($run->user_id === $request->user()->id, 403);

        return response()->json($run->toStatusArray());
    }

    private function authorizeModule(ResourceSchema $schema, string $verb): void
    {
        abort_unless(
            (bool) optional(auth()->user())->can($schema->permissionFor($verb)),
            403
        );
    }

    /**
     * Run this import here, or hand it to a worker.
     *
     * UNDER config('io.queue_threshold') the import runs inside the request and returns
     * its result in the response: a twelve-row file must not start depending on a worker
     * being up to fix a timeout it never suffered.
     *
     * OVER the threshold, BOTH the preview and the commit queue. Queuing only the
     * commit would move the timeout rather than remove it: a 5,000-row file times out
     * on validation alone, and validation is precisely where somebody needs an answer
     * before they write anything.
     */
    private function dispatchOrRun(Request $request, ResourceSchema $schema, UploadedFile $file, bool $preview)
    {
        $extension = strtolower($file->getClientOriginalExtension());

        // A whole-document JSON import is one document, not N rows — there is nothing
        // to threshold on and no per-row progress to report, so it stays synchronous.
        if ($extension === 'json') {
            abort_unless($schema->jsonImporter() !== null, 422, __('This resource does not accept JSON files.'));

            $result = $this->runner->runJson($schema, $file->getRealPath(), ! $preview);

            return $this->respondSynchronously($request, $schema, $file, $result, $preview);
        }

        // B22: an unreadable file, or one without the template's required columns, goes no further.
        $unknown = $this->runner->checkHeader($schema, $file->getRealPath(), $extension);
        $rows = $this->runner->parse($file->getRealPath(), $extension);

        if ($this->runner->shouldQueue($schema, count($rows))) {
            return $this->queueRun($request, $schema, $file, $rows, $preview);
        }

        $result = $this->runner->runRows($schema, $rows, ! $preview);
        if ($unknown !== []) {
            array_unshift($result->notices, ['row' => 1, 'messages' => [__('These columns are not in the template and were ignored: :columns.', ['columns' => implode(', ', $unknown)])]]);
        }

        return $this->respondSynchronously($request, $schema, $file, $result, $preview);
    }

    /**
     * Persist the upload and hand the run to RunResourceImportJob.
     *
     * The file goes to the PRIVATE disk, never the public one: an import file is a
     * spreadsheet of prices, customers and contacts, and the worker cannot read PHP's
     * temp file because the request is long over by the time it starts.
     */
    private function queueRun(Request $request, ResourceSchema $schema, UploadedFile $file, array $rows, bool $preview)
    {
        $path = $file->store(config('io.upload_dir', 'imports'), config('io.disk'));

        $run = ImportRun::queue($request->user(), $schema->key(), $file->getClientOriginalName(), $path, $preview, count($rows));

        RunResourceImportJob::dispatch($run->id);

        $payload = $run->toStatusArray() + [
            'queued' => true,
            'poll_url' => route('io.run-status', $run),
            'message' => __(':count rows queued. You can leave this page — the import continues in the background.', ['count' => count($rows)]),
        ];

        // A preview has always answered in JSON, whoever asked — it is consumed by the
        // import screen's script, never rendered as a page. Keep that contract.
        if ($preview || $request->wantsJson()) {
            return response()->json($payload, 202);
        }

        return back()->with('success', $payload['message'])->with('import_run_id', $run->id);
    }

    /**
     * Record and return a run that finished inside the request. A PREVIEW writes no
     * ImportRun (see ImportRun::recordFinished); a queued preview does get a row, because
     * a background job needs somewhere to report to.
     */
    private function respondSynchronously(Request $request, ResourceSchema $schema, UploadedFile $file, ImportResult $result, bool $preview)
    {
        if (! $preview) {
            ImportRun::recordFinished($request->user(), $schema->key(), $file->getClientOriginalName(), $result);
        }

        if ($preview || $request->wantsJson()) {
            return response()->json($result->toArray());
        }

        return back()->with('success', __('Import complete — :created created, :updated updated, :failed failed.', ['created' => $result->created, 'updated' => $result->updated, 'failed' => $result->failed()]))
            ->with('import_result', $result->toArray());
    }

    private function format(Request $request): string
    {
        return strtolower($request->query('format', 'csv')) === 'xlsx' ? 'xlsx' : 'csv';
    }

    private function download(string $content, string $filename, string $format)
    {
        $mime = $format === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv; charset=UTF-8';

        return response($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}

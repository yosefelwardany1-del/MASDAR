<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\Attachments\AttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Document attachments for every registered attachable (config/attachments.php):
 * upload needs `{screen}.edit` on the parent, download `{screen}.view`, delete
 * `{screen}.edit` (AttachmentPolicy). Files are never exposed by a guessable URL.
 */
class AttachmentController extends Controller
{
    public function __construct(private AttachmentService $attachments) {}

    public function store(Request $request, string $type, int $id)
    {
        $attachable = $this->resolveAttachable($type, $id);
        abort_unless($request->user()->can($attachable->attachmentModule().'.edit'), 403);

        $maxKb = (int) config('attachments.max_size_kb', 10240);
        $maxFiles = (int) config('attachments.max_files_per_request', 20);
        $mimes = implode(',', config('attachments.allowed_mimes', ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx']));

        $request->validate([
            'files' => ['required', 'array', "max:{$maxFiles}"],
            'files.*' => ['file', "max:{$maxKb}", "mimes:{$mimes}"],
            'title' => ['nullable', 'string', 'max:255'],
        ], [
            'files.*.mimes' => __('Only PDF, JPG, PNG, DOCX and XLSX files are allowed.'),
            'files.*.max' => __('Each file may not be larger than :size MB.', ['size' => round($maxKb / 1024, 1)]),
        ]);

        foreach ($request->file('files') as $file) {
            $this->attachments->store($attachable, $file, $request->user()->id, $request->input('title'));
        }

        return back()->with('success', __('Attachments uploaded.'));
    }

    public function download(Attachment $attachment): StreamedResponse
    {
        $this->authorize('view', $attachment);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->file_name);
    }

    public function destroy(Attachment $attachment)
    {
        $this->authorize('delete', $attachment);
        $this->attachments->delete($attachment);

        return back()->with('success', __('Attachment deleted.'));
    }

    /** A whitelisted attachable type and id; anything else is a 404. */
    private function resolveAttachable(string $type, int $id)
    {
        $class = config("attachments.attachables.{$type}") ?? throw new NotFoundHttpException;

        return $class::findOrFail($id);
    }
}

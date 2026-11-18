<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\DocumentSend;
use App\Models\Setting;
use App\Services\Documents\DocumentRegistry;
use App\Services\Documents\DocumentSender;
use App\Services\Documents\PdfRenderer;
use Illuminate\Http\Request;

/**
 * A document as a PDF, and the page that sends it by email or WhatsApp (spec §5.8). The
 * document's own print page renders it and decides who may see it; sending needs the edit level
 * of the document's screen, and a draft is never sent.
 */
class DocumentController extends Controller
{
    public function __construct(private DocumentRegistry $documents, private PdfRenderer $pdf, private DocumentSender $sender) {}

    public function pdf(Request $request, string $type, int $id)
    {
        $model = $this->documents->find($type, $id);
        $html = $this->documents->render($type, $model, $request);
        $reference = $this->documents->reference($type, $model, $request);

        return response($this->pdf->fromHtml($html), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->documents->filename($type, $reference).'"',
        ]);
    }

    public function create(Request $request, string $type, int $id)
    {
        $model = $this->documents->find($type, $id);
        // Seeing the page is the gate for seeing the document.
        $this->documents->render($type, $model, $request);
        $reference = $this->documents->reference($type, $model, $request);
        $party = $this->documents->party($type, $model, $request);
        $company = (string) Setting::current()->company_name;
        $title = $this->documents->title($type);

        return view('documents.send', [
            'type' => $type, 'model' => $model, 'reference' => $reference, 'title' => $title,
            'client' => $request->integer('client') ?: null,
            'party' => $party, 'contacts' => $this->documents->contacts($party),
            'notReady' => $this->documents->notReady($type, $model),
            'maySend' => $this->documents->maySend($type, $request->user()),
            'pdfReady' => $this->pdf->available(),
            'emailReady' => $this->sender->emailReady(),
            'whatsappReady' => $this->sender->whatsappReady(),
            'back' => $this->documents->backUrl($type, $model),
            'subject' => "{$title} {$reference} — {$company}",
            'message' => "Dear {$party?->name},\n\nPlease find attached our {$title} {$reference}.\n\nBest regards,\n{$request->user()->name}\n{$company}",
            'sends' => DocumentSend::query()->where('document_type', $type)->where('document_id', $model->getKey())->with('user')->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function store(Request $request, string $type, int $id)
    {
        $model = $this->documents->find($type, $id);
        abort_unless($this->documents->maySend($type, $request->user()), 403);
        if ($why = $this->documents->notReady($type, $model)) {
            return back()->with('error', $why);
        }
        $data = $request->validate([
            'channel' => 'required|in:email,whatsapp',
            'to' => 'nullable|array|max:10', 'to.*' => 'nullable|string|max:160',
            'to_other' => 'nullable|string|max:500',
            'cc' => 'nullable|string|max:500',
            'subject' => 'nullable|string|max:255',
            'message' => 'nullable|string|max:5000',
            'phone' => 'nullable|string|max:40',
        ]);

        $html = $this->documents->render($type, $model, $request);
        $reference = $this->documents->reference($type, $model, $request);
        $filename = $this->documents->filename($type, $reference);
        $pdf = $this->pdf->fromHtml($html);
        $user = $request->user();

        $send = $data['channel'] === 'email'
            ? $this->sender->email($type, $model->getKey(), $reference, $pdf, $filename,
                array_merge($data['to'] ?? [], [(string) ($data['to_other'] ?? '')]), [(string) ($data['cc'] ?? '')],
                (string) ($data['subject'] ?? ''), (string) ($data['message'] ?? ''), $user)
            : $this->sender->whatsapp($type, $model->getKey(), $reference, $pdf, $filename, (string) ($data['phone'] ?? ''), $user);

        $redirect = redirect()->route('documents.send.create', ['type' => $type, 'id' => $id] + ($request->integer('client') ? ['client' => $request->integer('client')] : []));

        return $send->status === 'sent'
            ? $redirect->with('success', __(':document sent to :to.', ['document' => $reference, 'to' => $send->recipients]))
            : $redirect->with('error', __('Not sent: :reason', ['reason' => $send->error]));
    }
}

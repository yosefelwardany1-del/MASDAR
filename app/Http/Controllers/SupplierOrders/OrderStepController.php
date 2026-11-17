<?php

namespace App\Http\Controllers\SupplierOrders;

use App\Http\Controllers\Controller;
use App\Models\OrderStepDefinition;
use App\Models\SupplierOrder;
use App\Services\SupplierOrders\FollowUpBoard;
use App\Services\SupplierOrders\OrderStepService;
use Illuminate\Http\Request;

/**
 * The steps of one supplier order (S2's columns): mark done (with date, note, files and, for a
 * payment step, the payment), not applicable (skip) or open again. Each is an event written by
 * OrderStepService; the latest event is the step's state.
 */
class OrderStepController extends Controller
{
    public function __construct(private OrderStepService $steps, private FollowUpBoard $board) {}

    public function done(Request $request, SupplierOrder $supplierOrder, OrderStepDefinition $step)
    {
        abort_unless($this->board->visibleTo($supplierOrder, $request->user()), 404);
        $maxKb = (int) config('attachments.max_size_kb', 10240);
        $mimes = implode(',', config('attachments.allowed_mimes', ['pdf', 'jpg', 'jpeg', 'png']));
        $data = $request->validate([
            'happened_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:2000'],
            'payment_id' => ['nullable', 'integer'],
            'files' => ['nullable', 'array', 'max:'.(int) config('attachments.max_files_per_request', 20)],
            'files.*' => ['file', "max:{$maxKb}", "mimes:{$mimes}"],
        ], [
            'files.*.mimes' => __('This kind of file is not allowed.'),
            'files.*.max' => __('Each file may not be larger than :size MB.', ['size' => round($maxKb / 1024, 1)]),
        ]);

        $this->steps->markDone(
            $supplierOrder, $step, $request->user(), $data['happened_on'], $data['note'] ?? null,
            isset($data['payment_id']) ? (int) $data['payment_id'] : null, $request->file('files', []),
        );

        return $this->back($supplierOrder, __(':step marked done.', ['step' => $step->displayName()]));
    }

    public function skip(Request $request, SupplierOrder $supplierOrder, OrderStepDefinition $step)
    {
        abort_unless($this->board->visibleTo($supplierOrder, $request->user()), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->steps->skip($supplierOrder, $step, $request->user(), $data['reason']);

        return $this->back($supplierOrder, __(':step marked not applicable.', ['step' => $step->displayName()]));
    }

    public function reopen(Request $request, SupplierOrder $supplierOrder, OrderStepDefinition $step)
    {
        abort_unless($this->board->visibleTo($supplierOrder, $request->user()), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->steps->reopen($supplierOrder, $step, $request->user(), $data['reason']);

        return $this->back($supplierOrder, __(':step reopened.', ['step' => $step->displayName()]));
    }

    private function back(SupplierOrder $order, string $message)
    {
        return redirect()->to(route('supplier-orders.show', $order).'#steps')->with('success', $message);
    }
}

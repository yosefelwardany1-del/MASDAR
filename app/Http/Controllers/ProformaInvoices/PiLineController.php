<?php

namespace App\Http\Controllers\ProformaInvoices;

use App\Http\Controllers\Controller;
use App\Models\Lookup;
use App\Models\PiLine;
use App\Models\Product;
use App\Models\ProformaInvoice;
use App\Services\Media\ImageStore;
use App\Services\ProformaInvoices\ProformaInvoiceService;
use App\Support\Access\Visibility;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The lines of a draft PI: add (an existing product, or a new one made on the spot with its
 * photo), change and remove. Supplier and supplier price are accepted only from someone who
 * may see them (Visibility::canSeeSupplierPrices); otherwise the line keeps its own.
 */
class PiLineController extends Controller
{
    use ScopesToVisibleClients;

    public function __construct(private ProformaInvoiceService $service) {}

    public function store(Request $request, ProformaInvoice $pi)
    {
        $this->abortUnlessVisible($request->user(), $pi);
        $data = $request->validate($this->rules($request, null) + [
            'product' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== 'new' && ! (ctype_digit($value) && Product::query()->whereKey((int) $value)->exists())) {
                    $fail(__('Pick a product, or "New product".'));
                }
            }],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.ImageStore::MAX_KB],
        ]);

        $new = $data['product'] === 'new';
        abort_if($new && ! $request->user()->can('products.edit'), 403, __('You may not add products.'));
        $data['product_id'] = $new ? null : (int) $data['product'];
        unset($data['product'], $data['photo'], $data['form']);

        $line = $this->service->addLine($pi, $data, $request->user(), $new ? $request->file('photo') : null);

        return redirect()->route('proforma-invoices.show', $pi)->withFragment('lines')
            ->with('success', __('Line :no added.', ['no' => $line->line_no]));
    }

    public function update(Request $request, ProformaInvoice $pi, PiLine $line)
    {
        $this->abortUnlessVisible($request->user(), $pi);
        abort_unless((int) $line->proforma_invoice_id === (int) $pi->id, 404);
        $data = $request->validate($this->rules($request, $line));
        unset($data['form']);

        $this->service->updateLine($line, $data, $request->user());

        return redirect()->route('proforma-invoices.show', $pi)->withFragment('lines')
            ->with('success', __('Line :no updated.', ['no' => $line->line_no]));
    }

    public function destroy(Request $request, ProformaInvoice $pi, PiLine $line)
    {
        $this->abortUnlessVisible($request->user(), $pi);
        abort_unless((int) $line->proforma_invoice_id === (int) $pi->id, 404);

        $this->service->removeLine($line, $request->user());

        return redirect()->route('proforma-invoices.show', $pi)->withFragment('lines')->with('success', __('Line removed.'));
    }

    /** Numbers as numeric(18,4) strings: at most 14 digits before the point and 4 after. */
    private function rules(Request $request, ?PiLine $line): array
    {
        $creating = $line === null;
        $quantity = ['numeric', 'decimal:0,4', 'gt:0', 'max:99999999999999'];
        $amount = ['numeric', 'decimal:0,4', 'min:0', 'max:99999999999999'];
        $units = array_keys(Lookup::options('unit'));
        if ($line) {
            $units[] = $line->unit;
        }

        $rules = [
            'form' => ['nullable', 'string', 'max:40'],
            'description' => [$creating ? 'required_if:product,new' : 'nullable', 'nullable', 'string', 'max:2000'],
            'unit' => [$creating ? 'required_if:product,new' : 'required', 'nullable', 'string', Rule::in($units)],
            'cartons' => ['required', ...$quantity],
            'qty_per_carton' => [$creating ? 'required_if:product,new' : 'required', 'nullable', ...$quantity],
            'unit_price' => ['required', ...$amount],
            'cbm_per_carton' => ['nullable', ...$amount],
            'kg_per_carton' => ['nullable', ...$amount],
        ];
        if (Visibility::canSeeSupplierPrices($request->user())) {
            $rules += [
                'supplier_id' => ['nullable', 'integer', Rule::exists('parties', 'id')->where('is_supplier', true)],
                'supplier_price' => ['nullable', ...$amount],
                'supplier_currency' => ['nullable', 'string', Rule::in(array_keys(Lookup::options('currency')))],
            ];
        }

        return $rules;
    }
}

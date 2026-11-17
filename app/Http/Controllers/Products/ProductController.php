<?php

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\Product;
use App\Services\Media\ImageStore;
use App\Services\Products\ProductService;
use App\Support\Access\Visibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Products screen (spec §3.2): the catalog with each item's photo and carton spec. The
 * usual supplier and the supplier's price are shown and written only for a user who may see
 * supplier prices (Visibility::canSeeSupplierPrices, spec §6); for anyone else they are not
 * on the page and an update leaves them as they were. Products are deactivated, never deleted.
 */
class ProductController extends Controller
{
    public function __construct(private ProductService $products, private ImageStore $images) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $canSeeSupplier = Visibility::canSeeSupplierPrices($user);
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'active' => in_array($request->query('active'), ['1', '0', 'all'], true) ? $request->query('active') : '1',
            'supplier' => $canSeeSupplier && ctype_digit((string) $request->query('supplier', '')) ? (int) $request->query('supplier') : null,
        ];

        $products = Product::query()
            ->when($canSeeSupplier, fn ($q) => $q->with('supplier'))
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('code', 'ilike', '%'.$filters['search'].'%')
                ->orWhere('name', 'ilike', '%'.$filters['search'].'%')
                ->orWhere('name_local', 'ilike', '%'.$filters['search'].'%')
                ->orWhere('hs_code', 'ilike', '%'.$filters['search'].'%')))
            ->when($filters['active'] !== 'all', fn ($q) => $q->where('active', $filters['active'] === '1'))
            ->when($filters['supplier'], fn ($q) => $q->where('supplier_id', $filters['supplier']))
            ->orderBy('name')
            ->paginate(25)->withQueryString();

        return view('products.index', [
            'products' => $products,
            'filters' => $filters,
            'canSeeSupplier' => $canSeeSupplier,
            'suppliers' => $canSeeSupplier ? $this->suppliers() : collect(),
            'units' => $this->lookupNames('unit'),
        ]);
    }

    public function create(Request $request)
    {
        return view('products.create', $this->formData($request, null));
    }

    public function store(Request $request): RedirectResponse
    {
        $product = $this->products->create($this->validated($request), $request->user(), $request->file('photo'));

        return redirect()->route('products.show', $product)->with('success', __(':code created.', ['code' => $product->code]));
    }

    public function show(Request $request, Product $product)
    {
        $canSeeSupplier = Visibility::canSeeSupplierPrices($request->user());

        return view('products.show', [
            'product' => $canSeeSupplier ? $product->load('supplier') : $product,
            'canSeeSupplier' => $canSeeSupplier,
            'units' => $this->lookupNames('unit'),
            'currencies' => $this->lookupNames('currency'),
        ]);
    }

    public function edit(Request $request, Product $product)
    {
        return view('products.edit', $this->formData($request, $product));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->products->update($product, $this->validated($request), $request->file('photo'));

        return redirect()->route('products.show', $product)->with('success', __(':code saved.', ['code' => $product->code]));
    }

    public function deactivate(Product $product): RedirectResponse
    {
        $this->products->deactivate($product);

        return redirect()->route('products.show', $product)->with('success', __(':code deactivated.', ['code' => $product->code]));
    }

    public function reactivate(Product $product): RedirectResponse
    {
        $this->products->reactivate($product);

        return redirect()->route('products.show', $product)->with('success', __(':code reactivated.', ['code' => $product->code]));
    }

    /** Any signed-in user: the PI print and the follow-up board show it too (plan contract). */
    public function photo(Product $product)
    {
        return $this->images->stream($product, 'photo');
    }

    public function destroyPhoto(Product $product): RedirectResponse
    {
        if ($this->images->has($product, 'photo')) {
            $this->products->removePhoto($product);
        }

        return redirect()->route('products.edit', $product)->with('success', __('Photo removed.'));
    }

    // ---- the form ------------------------------------------------------------------

    private function formData(Request $request, ?Product $product): array
    {
        $settings = BusinessSetting::current();
        $canSeeSupplier = Visibility::canSeeSupplierPrices($request->user());

        return [
            'product' => $product,
            'canSeeSupplier' => $canSeeSupplier,
            'suppliers' => $canSeeSupplier ? $this->suppliers($product?->supplier_id) : collect(),
            'units' => Lookup::options('unit'),
            'currencies' => Lookup::options('currency'),
            'defaults' => ['supplier_currency' => $settings->supplier_currency, 'sale_currency' => $settings->pi_currency],
            'photoRules' => ImageStore::MAX_KB,
        ];
    }

    /** @return array<string, mixed> validated product fields, narrowed to what the user may write */
    private function validated(Request $request): array
    {
        $lookup = fn (string $list) => Rule::exists('lookups', 'code')->where('list', $list);
        $amount = ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999999999'];
        $canSeeSupplier = Visibility::canSeeSupplierPrices($request->user());

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'name_local' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit' => ['required', 'string', $lookup('unit')],
            'qty_per_carton' => ['nullable', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999999999'],
            'carton_length_cm' => $amount,
            'carton_width_cm' => $amount,
            'carton_height_cm' => $amount,
            'cbm_per_carton' => $amount,
            'kg_per_carton' => $amount,
            'sale_price' => $amount,
            'sale_currency' => ['nullable', 'string', 'size:3', $lookup('currency'), 'required_with:sale_price'],
            'hs_code' => ['nullable', 'string', 'max:30'],
            'photo' => array_map(fn ($rule) => $rule === 'required' ? 'nullable' : $rule, ImageStore::rules()),
            'active' => ['boolean'],
        ];
        if ($canSeeSupplier) {
            $rules += [
                'supplier_id' => ['nullable', 'integer', Rule::exists('parties', 'id')->where('is_supplier', true)],
                'supplier_price' => $amount,
                'supplier_currency' => ['nullable', 'string', 'size:3', $lookup('currency'), 'required_with:supplier_price'],
            ];
        }

        $data = $request->validate($rules);
        unset($data['photo']);
        $data['active'] = $request->boolean('active');
        foreach ($data as $key => $value) {
            if ($value === '') {
                $data[$key] = null;
            }
        }
        // The browser fills CBM from the carton size; a form sent without it gets the same sum.
        if (($data['cbm_per_carton'] ?? null) === null && isset($data['carton_length_cm'], $data['carton_width_cm'], $data['carton_height_cm'])) {
            $data['cbm_per_carton'] = ProductService::cbm($data['carton_length_cm'], $data['carton_width_cm'], $data['carton_height_cm']);
        }

        return $data;
    }

    private function suppliers(?int $include = null)
    {
        return Party::query()->where('is_supplier', true)
            ->where(fn ($q) => $q->where('active', true)->when($include, fn ($q) => $q->orWhere('id', $include)))
            ->orderBy('name')->get(['id', 'code', 'name']);
    }

    /** @return array<string, string> every entry of a list (inactive ones too), for display */
    private function lookupNames(string $list): array
    {
        return Lookup::query()->ofList($list)->get()->mapWithKeys(fn (Lookup $l) => [$l->code => $l->displayName()])->all();
    }
}

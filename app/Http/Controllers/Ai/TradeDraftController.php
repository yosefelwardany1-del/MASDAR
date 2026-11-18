<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\Product;
use App\Models\Quotation;
use App\Services\Ai\Drafts\TradeDrafts;
use App\Services\Ai\StructuredOutputException;
use App\Services\Quotations\QuotationService;
use Illuminate\Http\Request;

/** AI drafts on the quotation and product pages (spec §5.8): proposed, reviewed, then saved by a person. */
class TradeDraftController extends Controller
{
    public function __construct(private TradeDrafts $drafts) {}

    /** Read the client's message into proposed lines, shown on the quotation for review. */
    public function readMessage(Request $request, Quotation $quotation)
    {
        $this->visible($request, $quotation);
        $data = $request->validate(['message' => 'required|string|max:8000']);
        try {
            $lines = $this->drafts->quotationLines($data['message'], Lookup::options('unit'));
        } catch (StructuredOutputException $e) {
            report($e);

            return back()->withInput()->with('error', __('The AI could not read the message: :reason. Add the lines by hand.', ['reason' => $e->getMessage()]));
        }

        return back()->with('ai_lines', $lines)->with('success', trans_choice('{0} The AI found no item in the message.|{1} The AI found one item: check it below.|[2,*] The AI found :count items: check them below.', count($lines), ['count' => count($lines)]));
    }

    /** Add the ticked proposed lines, with the prices given, through the quotation's writer. */
    public function addLines(Request $request, Quotation $quotation, QuotationService $quotations)
    {
        $this->visible($request, $quotation);
        $added = 0;
        foreach ((array) $request->input('lines', []) as $line) {
            if (empty($line['take'])) {
                continue;
            }
            $quotations->addLine($quotation, [
                'description' => $line['description'] ?? '', 'unit' => $line['unit'] ?? 'pcs',
                'unit_price' => filled($line['unit_price'] ?? null) ? $line['unit_price'] : '0',
                'cartons' => null, 'moq' => filled($line['quantity'] ?? null) ? $line['quantity'] : null,
            ], $request->user());
            $added++;
        }

        return redirect()->route('quotations.show', $quotation)->with('success', trans_choice('{0} No line added.|{1} One line added.|[2,*] :count lines added.', $added, ['count' => $added]));
    }

    /** A product's name and specification in Chinese, shown on its edit page to copy in. */
    public function chinese(Request $request, Product $product)
    {
        try {
            $suggestion = $this->drafts->chinese($product->name, $product->description);
        } catch (StructuredOutputException $e) {
            report($e);

            return back()->with('error', __('The AI could not translate it: :reason.', ['reason' => $e->getMessage()]));
        }

        return back()->with('ai_chinese', $suggestion);
    }

    private function visible(Request $request, Quotation $quotation): void
    {
        abort_unless($quotation->isDraft() && Party::query()->clients()->visibleTo($request->user())->whereKey($quotation->client_id)->exists(), 404);
    }
}

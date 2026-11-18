<?php

namespace App\Services\Documents;

use Illuminate\Http\Request;

/** A document could not be made or sent; the message says why and what to do instead. */
class DocumentException extends \RuntimeException
{
    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return back()->withInput()->with('error', $this->getMessage());
    }
}

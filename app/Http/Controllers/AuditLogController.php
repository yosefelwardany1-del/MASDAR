<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\AuditActions;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = AuditLog::with('user')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', (int) $request->query('user_id')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->query('action')))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        // Only the people who appear in the trail — never an enumeration of every account.
        $users = User::whereIn('id', AuditLog::query()->whereNotNull('user_id')->distinct()->select('user_id'))->orderBy('name')->get();
        // B25: every action the trail holds, by its name in the reader's language.
        $actions = AuditLog::query()->distinct()->pluck('action')
            ->mapWithKeys(fn (string $a) => [$a => AuditActions::label($a)])->sort()->all();

        return view('audit-logs.index', compact('logs', 'users', 'actions'));
    }
}

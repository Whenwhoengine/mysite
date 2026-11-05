<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AuditLogController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', AuditLog::class);
        $prefix = (string) $request->query('action', '');
        $prefix = preg_match('/^[a-z_.]{1,64}$/', $prefix) === 1 ? $prefix : '';
        $logs = AuditLog::query()
            ->when($prefix !== '', static fn ($q) => $q->where('action', 'like', $prefix.'%'))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.audit.index', ['logs' => $logs, 'action' => $prefix]);
    }
}

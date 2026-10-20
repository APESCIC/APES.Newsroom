<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Services\Membership\MemberCrmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function __invoke(Request $request, MemberCrmService $crm): Response
    {
        Gate::authorize('viewAny', Membership::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:all,free,paying'],
        ]);

        $search = $validated['q'] ?? null;
        $status = $validated['status'] ?? 'all';

        return Inertia::render('Admin/Members/Index', [
            'filters' => [
                'q' => $search ?? '',
                'status' => $status,
            ],
            'members' => $crm->paginate($search, $status),
        ]);
    }
}

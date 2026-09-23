<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Membership\MemberCrmService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function __invoke(Request $request, MemberCrmService $crm): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:all,free,paying'],
        ]);

        $search = $validated['q'] ?? null;
        $status = $validated['status'] ?? 'all';

        return Inertia::render('Staff/Members/Index', [
            'filters' => [
                'q' => $search ?? '',
                'status' => $status,
            ],
            'members' => $crm->paginate($search, $status),
        ]);
    }
}

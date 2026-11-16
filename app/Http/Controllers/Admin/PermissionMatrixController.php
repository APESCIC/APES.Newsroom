<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PermissionMatrixController extends Controller
{
    public function __invoke(): Response
    {
        Gate::authorize('access-admin-area');

        return Inertia::render('Admin/Permissions/Index', PermissionMatrix::forPage());
    }
}

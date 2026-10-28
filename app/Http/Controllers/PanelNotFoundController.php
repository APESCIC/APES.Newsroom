<?php

namespace App\Http\Controllers;

/**
 * Fallback for unknown /staff/* and /admin/* URLs. Registered inside each
 * panel's route group so the 404 renders after the session and role checks,
 * inside the panel layout (#354).
 */
class PanelNotFoundController extends Controller
{
    public function __invoke(): never
    {
        abort(404);
    }
}

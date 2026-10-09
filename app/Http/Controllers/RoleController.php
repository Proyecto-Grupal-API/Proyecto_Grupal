<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class RoleController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        return Inertia::render('Roles/Index', [
            'userRoles' => $user->roles ?? [],
            'twoFactorEnabled' => ! empty($user->two_factor_secret),
        ]);
    }
    public function assign(Request $request)
    {
        // The old endpoint allowed users to grant themselves arbitrary roles.
        // Existing self-declared roles cannot authorize reopening this endpoint.
        abort(403, 'La autoasignación de roles no está permitida.');
    }
}
<?php

declare(strict_types=1);

namespace App\\Http\\Controllers\\Setup;

use App\\Http\\Controllers\\Controller;
use App\\Services\\Identity\\SuperAdminBootstrapService;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\View;
use Illuminate\\Support\\Str;
use Illuminate\\Validation\\Rules\\Password;
use Illuminate\\Validation\\Rule;
use Illuminate\\View\\View as ViewResponse;

final class SuperAdminBootstrapController extends Controller
{
    public function show(SuperAdminBootstrapService $bootstrap): ViewResponse
    {
        abort_unless($bootstrap->isAvailable(), 404);

        return view('setup.super-admin');
    }

    public function store(
        Request $request,
        SuperAdminBootstrapService $bootstrap,
    ): JsonResponse|RedirectResponse {
        abort_unless($bootstrap->isAvailable(), 404);

        $validated = $request->validate([
            'bootstrap_token' => ['required', 'string', 'min:43', 'max:128'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(16)->mixedCase()->numbers()->symbols(), 'confirmed'],
        ]);

        $bootstrap->bootstrap(
            $validated['bootstrap_token'],
            [
                'name' => trim($validated['name']),
                'email' => Str::lower(trim($validated['email'])),
                'password' => $validated['password'],
            ],
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'The initial Super Admin account was created. Remove TLMS_BOOTSTRAP_TOKEN from the environment before continuing.',
            ], 201);
        }

        return redirect()->route('setup.super-admin.complete');
    }

    public function complete(SuperAdminBootstrapService $bootstrap): ViewResponse
    {
        abort_if($bootstrap->isAvailable(), 404);

        return view('setup.super-admin-complete');
    }
}

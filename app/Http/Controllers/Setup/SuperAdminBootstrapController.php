<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Services\Identity\SuperAdminBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

final class SuperAdminBootstrapController
{
    public function show(SuperAdminBootstrapService $bootstrap): View
    {
        abort_unless($bootstrap->isAvailable(), 404);

        return view('setup.super-admin');
    }

    public function store(
        Request $request,
        SuperAdminBootstrapService $bootstrap,
    ): JsonResponse|RedirectResponse {
        abort_unless($bootstrap->isAvailable(), 404);

        $request->merge([
            'name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name'),
            'email' => is_string($request->input('email')) ? Str::lower(trim($request->input('email'))) : $request->input('email'),
        ]);

        $validator = Validator::make($request->all(), [
            'bootstrap_token' => ['required', 'string', 'regex:/\\A[A-Za-z0-9_-]{43,128}\\z/'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(15), 'confirmed'],
        ]);

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'The submitted data is invalid.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            return redirect()->back()
                ->withErrors($validator)
                ->withInput($request->except(['bootstrap_token', 'password', 'password_confirmation']));
        }

        $validated = $validator->validated();

        $bootstrap->bootstrap(
            $validated['bootstrap_token'],
            [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ],
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'The initial Super Admin account was created. Remove TLMS_BOOTSTRAP_TOKEN from the environment and clear the cached configuration before continuing.',
            ], 201);
        }

        return redirect()->route('setup.super-admin.complete');
    }

    public function complete(SuperAdminBootstrapService $bootstrap): View
    {
        abort_unless($bootstrap->isBootstrapped(), 404);

        return view('setup.super-admin-complete');
    }
}

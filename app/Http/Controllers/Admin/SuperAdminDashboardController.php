<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SuperAdminDashboardController
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->view('admin.dashboard', [
            'user' => $user,
            'mfaRecoveryCodeReplacement' => $request->session()->get('mfa.recovery_code_replacement_once'),
        ])->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}

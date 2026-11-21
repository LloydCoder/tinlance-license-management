<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class SuperAdminDashboardController
{
    public function __invoke(Request $request): View
    {
        return view('admin.dashboard', [
            'user' => $request->user(),
        ]);
    }
}

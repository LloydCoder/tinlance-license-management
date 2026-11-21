<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WorkspaceController
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->status === 'active', 403);

        $isSuperAdmin = $user->vendorMemberships()
            ->where('status', 'active')
            ->whereHas('vendorAccount', static fn ($query) => $query->where('status', 'active'))
            ->whereHas('roles', static fn ($query) => $query
                ->where('scope', 'vendor')
                ->where('slug', 'super_admin'))
            ->exists();

        if ($isSuperAdmin) {
            return redirect('/admin');
        }

        $vendorMemberships = $user->vendorMemberships()
            ->where('status', 'active')
            ->whereHas('vendorAccount', static fn ($query) => $query->where('status', 'active'))
            ->with(['vendorAccount', 'roles'])
            ->orderBy('created_at')
            ->get();

        $customerMemberships = $user->customerMemberships()
            ->where('status', 'active')
            ->whereHas('customerOrganization', static fn ($query) => $query
                ->where('status', 'active')
                ->whereHas('vendorAccount', static fn ($vendorQuery) => $vendorQuery->where('status', 'active')))
            ->with(['customerOrganization.vendorAccount', 'roles'])
            ->orderBy('created_at')
            ->get();

        return view('workspace.dashboard', [
            'user' => $user,
            'vendorMemberships' => $vendorMemberships,
            'customerMemberships' => $customerMemberships,
        ]);
    }
}

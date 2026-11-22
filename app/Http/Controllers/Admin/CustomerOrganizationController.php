<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\CustomerOrganization;
use App\Models\User;
use App\Services\Authorization\CustomerOrganizationManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class CustomerOrganizationController
{
    public function index(
        Request $request,
        CustomerOrganizationManagementService $organizations,
    ): View {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('viewAny', CustomerOrganization::class);

        $now = (int) now()->getTimestamp();
        $passwordConfirmedAt = $request->session()->get('auth.password_confirmed_at');
        $passwordTimestamp = is_numeric($passwordConfirmedAt) ? (int) $passwordConfirmedAt : null;
        $passwordTimeout = max(1, (int) config('auth.password_timeout', 10800));
        $passwordConfirmationFresh = $passwordTimestamp !== null
            && $passwordTimestamp <= $now
            && $passwordTimestamp >= ($now - $passwordTimeout);

        $stepUpAt = $request->session()->get('auth.mfa_step_up_at');
        $stepUpMethod = $request->session()->get('auth.mfa_step_up_method');
        $stepUpTimestamp = is_numeric($stepUpAt) ? (int) $stepUpAt : null;
        $stepUpTtl = max(1, (int) config('tlms.security.step_up_ttl_seconds', 300));
        $mfaStepUpFresh = $stepUpTimestamp !== null
            && $stepUpTimestamp <= $now
            && $stepUpTimestamp >= ($now - $stepUpTtl)
            && is_string($stepUpMethod)
            && in_array($stepUpMethod, ['totp', 'passkey'], true);

        return view('admin.customers.index', [
            'organizations' => $organizations->paginate($actor),
            'passwordConfirmationFresh' => $passwordConfirmationFresh,
            'mfaStepUpFresh' => $mfaStepUpFresh,
            'hasTotp' => $actor->hasEnabledTwoFactorAuthentication(),
            'hasPasskeys' => $actor->hasPasskeysEnabled(),
            'canManageWrites' => $passwordConfirmationFresh && $mfaStepUpFresh,
        ]);
    }

    public function store(
        Request $request,
        CustomerOrganizationManagementService $organizations,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('create', CustomerOrganization::class);

        $validated = $request->validate($this->rules());
        $organizations->create(
            $actor,
            $this->attributes($validated),
            $validated['reason'],
            (string) $request->headers->get('X-Request-ID', ''),
        );

        return redirect()->route('admin.customers.index')->with('status', 'Customer organization created.');
    }

    public function update(
        Request $request,
        CustomerOrganization $organization,
        CustomerOrganizationManagementService $organizations,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('update', $organization);

        $validated = $request->validate($this->rules());
        $organizations->update(
            $actor,
            $organization,
            $this->attributes($validated),
            $validated['reason'],
            (string) $request->headers->get('X-Request-ID', ''),
        );

        return redirect()->route('admin.customers.index')->with('status', 'Customer organization profile updated.');
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        return [
            'vendor_account_id' => ['prohibited'],
            'status' => ['prohibited'],
            'legal_name' => ['required', 'string', 'min:2', 'max:255'],
            'display_name' => ['required', 'string', 'min:2', 'max:160'],
            'external_reference' => ['nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9._:-]+\z/D'],
            'billing_email' => ['nullable', 'email:rfc', 'max:254'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{legal_name: string, display_name: string, external_reference: ?string, billing_email: ?string}
     */
    private function attributes(array $validated): array
    {
        return [
            'legal_name' => $validated['legal_name'],
            'display_name' => $validated['display_name'],
            'external_reference' => $validated['external_reference'] ?? null,
            'billing_email' => $validated['billing_email'] ?? null,
        ];
    }
}

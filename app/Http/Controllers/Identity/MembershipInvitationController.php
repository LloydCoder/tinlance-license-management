<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Models\CustomerMembership;
use App\Models\CustomerMembershipInvitation;
use App\Models\CustomerOrganization;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Models\VendorMembershipInvitation;
use App\Services\Authorization\MembershipInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

final class MembershipInvitationController
{
    public function storeVendor(
        Request $request,
        VendorAccount $vendor,
        MembershipInvitationService $invitations,
    ): JsonResponse {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'name' => ['nullable', 'string', 'min:2', 'max:160'],
            'role_slug' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $membership = $invitations->inviteVendor(
            $actor,
            $vendor,
            $validated['email'],
            $validated['role_slug'],
            $validated['reason'],
            $validated['name'] ?? null,
        );

        $expiresAt = VendorMembershipInvitation::query()
            ->where('vendor_membership_id', $membership->getKey())
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->value('expires_at');

        return response()->json([
            'data' => [
                'membership_id' => $membership->getKey(),
                'status' => $membership->status,
                'invitation_expires_at' => $expiresAt,
            ],
        ], 201);
    }

    public function storeCustomer(
        Request $request,
        CustomerOrganization $organization,
        MembershipInvitationService $invitations,
    ): JsonResponse {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'name' => ['nullable', 'string', 'min:2', 'max:160'],
            'role_slug' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $membership = $invitations->inviteCustomer(
            $actor,
            $organization,
            $validated['email'],
            $validated['role_slug'],
            $validated['reason'],
            $validated['name'] ?? null,
        );

        $expiresAt = CustomerMembershipInvitation::query()
            ->where('customer_membership_id', $membership->getKey())
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->value('expires_at');

        return response()->json([
            'data' => [
                'membership_id' => $membership->getKey(),
                'status' => $membership->status,
                'invitation_expires_at' => $expiresAt,
            ],
        ], 201);
    }

    public function resendVendor(
        Request $request,
        VendorAccount $vendor,
        VendorMembership $membership,
        MembershipInvitationService $invitations,
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $invitations->resendVendor($actor, $vendor, $membership, $validated['reason']);

        $expiresAt = VendorMembershipInvitation::query()
            ->where('vendor_membership_id', $membership->getKey())
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->value('expires_at');

        return response()->json([
            'data' => [
                'membership_id' => $membership->getKey(),
                'status' => 'invited',
                'invitation_expires_at' => $expiresAt,
            ],
        ]);
    }

    public function resendCustomer(
        Request $request,
        CustomerOrganization $organization,
        CustomerMembership $membership,
        MembershipInvitationService $invitations,
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $invitations->resendCustomer($actor, $organization, $membership, $validated['reason']);

        $expiresAt = CustomerMembershipInvitation::query()
            ->where('customer_membership_id', $membership->getKey())
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->value('expires_at');

        return response()->json([
            'data' => [
                'membership_id' => $membership->getKey(),
                'status' => 'invited',
                'invitation_expires_at' => $expiresAt,
            ],
        ]);
    }

    public function prepare(Request $request, MembershipInvitationService $invitations): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
        ]);

        $preview = $invitations->prepare($validated['token'], $request->user());
        $request->session()->put('pending_membership_invitation_hash', hash('sha256', $validated['token']));

        if (! $preview['requires_account_setup'] && $request->user() === null) {
            $request->session()->put('url.intended', '/membership-invitations/accept');

            return response()->json([
                'next' => 'login',
                'redirect' => '/login',
            ]);
        }

        return response()->json([
            'next' => $preview['requires_account_setup'] ? 'account_setup' : 'accept',
        ]);
    }

    public function show(Request $request, MembershipInvitationService $invitations): Response|RedirectResponse
    {
        $tokenHash = $request->session()->get('pending_membership_invitation_hash');

        if (! is_string($tokenHash)) {
            return response()->view('identity.membership-invitation-accept', ['mode' => 'prepare'])
                ->header('Referrer-Policy', 'no-referrer')
                ->header('Cache-Control', 'no-store, max-age=0');
        }

        $preview = $invitations->prepareHash($tokenHash, $request->user());

        if (! $preview['requires_account_setup'] && $request->user() === null) {
            $request->session()->put('url.intended', '/membership-invitations/accept');

            return redirect()->guest('/login');
        }

        if (! $preview['requires_account_setup'] && ! $this->hasRecentPasswordConfirmation($request)) {
            $request->session()->put('url.intended', '/membership-invitations/accept');

            return redirect('/user/confirm-password');
        }

        return response()->view('identity.membership-invitation-accept', [
            'mode' => $preview['requires_account_setup'] ? 'account_setup' : 'authenticated',
            'preview' => $preview,
        ])->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    public function acceptNewAccount(Request $request, MembershipInvitationService $invitations): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'password' => ['required', 'string', 'max:1024', \Illuminate\Validation\Rules\Password::min(15), 'confirmed'],
        ]);

        $tokenHash = $request->session()->get('pending_membership_invitation_hash');

        if (! is_string($tokenHash)) {
            throw new GoneHttpException('This invitation is invalid or no longer available.');
        }

        $invitations->acceptNewAccount($tokenHash, $validated['name'], $validated['password']);
        $request->session()->forget('pending_membership_invitation_hash');

        return redirect('/login')->with('status', 'Your email is verified and your membership is active. Sign in to enroll MFA and continue.');
    }

    public function acceptAuthenticated(Request $request, MembershipInvitationService $invitations): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $tokenHash = $request->session()->get('pending_membership_invitation_hash');

        if (! is_string($tokenHash)) {
            throw new GoneHttpException('This invitation is invalid or no longer available.');
        }

        if (! $this->hasRecentPasswordConfirmation($request)) {
            $request->session()->put('url.intended', '/membership-invitations/accept');

            return redirect('/user/confirm-password');
        }

        $invitations->acceptAuthenticated($tokenHash, $actor);
        $request->session()->forget('pending_membership_invitation_hash');

        return redirect('/membership-invitations/accepted');
    }

    public function accepted(): View
    {
        return view('identity.membership-invitation-accepted');
    }

    private function hasRecentPasswordConfirmation(Request $request): bool
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');
        $timeout = max(1, (int) config('auth.password_timeout', 10800));
        $timestamp = is_numeric($confirmedAt) ? (int) $confirmedAt : null;

        return $timestamp !== null && $timestamp >= now()->timestamp - $timeout;
    }
}

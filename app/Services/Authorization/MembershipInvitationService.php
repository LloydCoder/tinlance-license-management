<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Mail\MembershipInvitationMail;
use App\Models\CustomerMembership;
use App\Models\CustomerMembershipInvitation;
use App\Models\CustomerMembershipLifecycleEvent;
use App\Models\CustomerOrganization;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Models\VendorMembershipInvitation;
use App\Models\VendorMembershipLifecycleEvent;
use App\Services\Identity\SessionRevocationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final readonly class MembershipInvitationService
{
    public function __construct(
        private MembershipAuthorization $authorization,
        private MembershipRoleAssignmentService $membershipRoles,
        private SessionRevocationService $sessionRevocation,
    ) {}

    public function inviteVendor(
        User $actor,
        VendorAccount $vendor,
        string $email,
        string $roleSlug,
        string $reason,
        ?string $name = null,
    ): VendorMembership {
        $email = $this->normalizeEmail($email);
        $reason = $this->validateReason($reason);
        $token = bin2hex(random_bytes(32));

        $payload = DB::transaction(function () use ($actor, $vendor, $email, $roleSlug, $reason, $name, $token): array {
            $activeActor = User::query()->whereKey($actor->getKey())->where('status', 'active')->lockForUpdate()->first();
            $activeVendor = VendorAccount::query()->whereKey($vendor->getKey())->where('status', 'active')->lockForUpdate()->first();
            $actorMembership = VendorMembership::query()
                ->where('vendor_account_id', $vendor->getKey())
                ->where('user_id', $actor->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (
                $activeActor === null
                || ! $activeVendor instanceof VendorAccount
                || $actorMembership === null
                || ! $this->authorization->vendorCan($actor, $vendor, 'admins.manage')
            ) {
                throw new AuthorizationException('The actor cannot invite vendor members.');
            }

            $invitee = $this->findOrCreateInvitee($email, $name);

            if ($invitee->getKey() === $actor->getKey()) {
                throw new AuthorizationException('An administrator cannot invite themselves.');
            }

            $existing = VendorMembership::query()
                ->where('vendor_account_id', $vendor->getKey())
                ->where('user_id', $invitee->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing instanceof VendorMembership) {
                throw new ConflictHttpException('A vendor membership already exists; resend an outstanding invitation or use the separate reinstatement workflow.');
            }

            $membership = VendorMembership::query()->create([
                'vendor_account_id' => $vendor->getKey(),
                'user_id' => $invitee->getKey(),
                'invited_by' => $actor->getKey(),
                'status' => 'invited',
                'joined_at' => null,
            ]);

            $role = Role::query()->where('scope', 'vendor')->where('slug', $roleSlug)->firstOrFail();
            $this->membershipRoles->assignVendorRole($actor, $vendor, $membership, $role, $reason);

            $invitation = VendorMembershipInvitation::query()->create([
                'vendor_membership_id' => $membership->getKey(),
                'invited_by_user_id' => $actor->getKey(),
                'invited_email' => $invitee->email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $this->invitationExpiry(),
                'delivery_status' => 'pending',
            ]);

            return [
                'type' => 'vendor',
                'membership' => $membership,
                'invitation' => $invitation,
                'email' => $invitee->email,
                'tenant_name' => $activeVendor->display_name,
                'role_name' => $role->name,
                'token' => $token,
            ];
        }, 3);

        $this->deliver($payload);

        return $payload['membership']->refresh();
    }

    public function inviteCustomer(
        User $actor,
        CustomerOrganization $organization,
        string $email,
        string $roleSlug,
        string $reason,
        ?string $name = null,
    ): CustomerMembership {
        $email = $this->normalizeEmail($email);
        $reason = $this->validateReason($reason);
        $token = bin2hex(random_bytes(32));

        $payload = DB::transaction(function () use ($actor, $organization, $email, $roleSlug, $reason, $name, $token): array {
            $activeActor = User::query()->whereKey($actor->getKey())->where('status', 'active')->lockForUpdate()->first();
            $activeOrganization = CustomerOrganization::query()
                ->whereKey($organization->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            $activeVendor = $activeOrganization instanceof CustomerOrganization
                ? VendorAccount::query()
                    ->whereKey($activeOrganization->vendor_account_id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first()
                : null;
            $actorMembership = CustomerMembership::query()
                ->where('customer_organization_id', $organization->getKey())
                ->where('user_id', $actor->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (
                $activeActor === null
                || ! $activeOrganization instanceof CustomerOrganization
                || ! $activeVendor instanceof VendorAccount
                || $actorMembership === null
                || ! $this->authorization->customerCan($actor, $organization, 'customer.members.manage')
            ) {
                throw new AuthorizationException('The actor cannot invite customer members.');
            }

            $invitee = $this->findOrCreateInvitee($email, $name);

            if ($invitee->getKey() === $actor->getKey()) {
                throw new AuthorizationException('An organization owner cannot invite themselves.');
            }

            $existing = CustomerMembership::query()
                ->where('customer_organization_id', $organization->getKey())
                ->where('user_id', $invitee->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing instanceof CustomerMembership) {
                throw new ConflictHttpException('A customer membership already exists; resend an outstanding invitation or use the separate reinstatement workflow.');
            }

            $membership = CustomerMembership::query()->create([
                'customer_organization_id' => $organization->getKey(),
                'user_id' => $invitee->getKey(),
                'invited_by' => $actor->getKey(),
                'status' => 'invited',
                'joined_at' => null,
            ]);

            $role = Role::query()->where('scope', 'customer')->where('slug', $roleSlug)->firstOrFail();
            $this->membershipRoles->assignCustomerRole($actor, $organization, $membership, $role, $reason);

            $invitation = CustomerMembershipInvitation::query()->create([
                'customer_membership_id' => $membership->getKey(),
                'invited_by_user_id' => $actor->getKey(),
                'invited_email' => $invitee->email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $this->invitationExpiry(),
                'delivery_status' => 'pending',
            ]);

            return [
                'type' => 'customer',
                'membership' => $membership,
                'invitation' => $invitation,
                'email' => $invitee->email,
                'tenant_name' => $activeOrganization->display_name,
                'role_name' => $role->name,
                'token' => $token,
            ];
        }, 3);

        $this->deliver($payload);

        return $payload['membership']->refresh();
    }

    public function resendVendor(User $actor, VendorAccount $vendor, VendorMembership $membership, string $reason): void
    {
        $reason = $this->validateReason($reason);
        $token = bin2hex(random_bytes(32));

        $payload = DB::transaction(function () use ($actor, $vendor, $membership, $reason, $token): array {
            $activeActor = User::query()->whereKey($actor->getKey())->where('status', 'active')->lockForUpdate()->first();
            $activeVendor = VendorAccount::query()->whereKey($vendor->getKey())->where('status', 'active')->lockForUpdate()->first();
            $actorMembership = VendorMembership::query()
                ->where('vendor_account_id', $vendor->getKey())
                ->where('user_id', $actor->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            $target = VendorMembership::query()
                ->whereKey($membership->getKey())
                ->where('vendor_account_id', $vendor->getKey())
                ->where('status', 'invited')
                ->lockForUpdate()
                ->first();
            $invitee = $target instanceof VendorMembership
                ? User::query()->whereKey($target->user_id)->where('status', 'active')->lockForUpdate()->first()
                : null;

            if (
                $activeActor === null
                || ! $activeVendor instanceof VendorAccount
                || $actorMembership === null
                || ! $target instanceof VendorMembership
                || ! $invitee instanceof User
                || ! $this->authorization->vendorCan($actor, $vendor, 'admins.manage')
            ) {
                throw new AuthorizationException('The vendor invitation cannot be resent by this actor.');
            }

            VendorMembershipInvitation::query()
                ->where('vendor_membership_id', $target->getKey())
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            $invitation = VendorMembershipInvitation::query()->create([
                'vendor_membership_id' => $target->getKey(),
                'invited_by_user_id' => $actor->getKey(),
                'invited_email' => $invitee->email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $this->invitationExpiry(),
                'delivery_status' => 'pending',
            ]);

            return [
                'type' => 'vendor',
                'membership' => $target,
                'invitation' => $invitation,
                'email' => $invitee->email,
                'tenant_name' => $activeVendor->display_name,
                'role_name' => $target->roles()->orderBy('slug')->value('name') ?? 'vendor member',
                'token' => $token,
            ];
        }, 3);

        $this->deliver($payload);
    }

    public function resendCustomer(
        User $actor,
        CustomerOrganization $organization,
        CustomerMembership $membership,
        string $reason,
    ): void {
        $reason = $this->validateReason($reason);
        $token = bin2hex(random_bytes(32));

        $payload = DB::transaction(function () use ($actor, $organization, $membership, $reason, $token): array {
            $activeActor = User::query()->whereKey($actor->getKey())->where('status', 'active')->lockForUpdate()->first();
            $activeOrganization = CustomerOrganization::query()
                ->whereKey($organization->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            $activeVendor = $activeOrganization instanceof CustomerOrganization
                ? VendorAccount::query()
                    ->whereKey($activeOrganization->vendor_account_id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first()
                : null;
            $actorMembership = CustomerMembership::query()
                ->where('customer_organization_id', $organization->getKey())
                ->where('user_id', $actor->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            $target = CustomerMembership::query()
                ->whereKey($membership->getKey())
                ->where('customer_organization_id', $organization->getKey())
                ->where('status', 'invited')
                ->lockForUpdate()
                ->first();
            $invitee = $target instanceof CustomerMembership
                ? User::query()->whereKey($target->user_id)->where('status', 'active')->lockForUpdate()->first()
                : null;

            if (
                $activeActor === null
                || ! $activeOrganization instanceof CustomerOrganization
                || ! $activeVendor instanceof VendorAccount
                || $actorMembership === null
                || ! $target instanceof CustomerMembership
                || ! $invitee instanceof User
                || ! $this->authorization->customerCan($actor, $organization, 'customer.members.manage')
            ) {
                throw new AuthorizationException('The customer invitation cannot be resent by this actor.');
            }

            CustomerMembershipInvitation::query()
                ->where('customer_membership_id', $target->getKey())
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            $invitation = CustomerMembershipInvitation::query()->create([
                'customer_membership_id' => $target->getKey(),
                'invited_by_user_id' => $actor->getKey(),
                'invited_email' => $invitee->email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $this->invitationExpiry(),
                'delivery_status' => 'pending',
            ]);

            return [
                'type' => 'customer',
                'membership' => $target,
                'invitation' => $invitation,
                'email' => $invitee->email,
                'tenant_name' => $activeOrganization->display_name,
                'role_name' => $target->roles()->orderBy('slug')->value('name') ?? 'customer member',
                'token' => $token,
            ];
        }, 3);

        $this->deliver($payload);
    }

    /**
     * @return array{membership_type: string, membership_id: string, email: string, tenant_name: string, role_name: string, requires_account_setup: bool, expires_at: string}
     */
    public function prepare(string $token, ?User $authenticatedUser = null): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            throw new GoneHttpException('This invitation is invalid or no longer available.');
        }

        $hash = hash('sha256', $token);
        $vendorInvitation = VendorMembershipInvitation::query()->where('token_hash', $hash)->first();

        if ($vendorInvitation instanceof VendorMembershipInvitation) {
            $membership = VendorMembership::query()->whereKey($vendorInvitation->vendor_membership_id)->where('status', 'invited')->first();
            $vendor = $membership instanceof VendorMembership
                ? VendorAccount::query()->whereKey($membership->vendor_account_id)->where('status', 'active')->first()
                : null;
            $user = $membership instanceof VendorMembership
                ? User::query()->whereKey($membership->user_id)->where('status', 'active')->first()
                : null;

            $this->assertPending($vendorInvitation, $membership, $user);

            if (! $vendor instanceof VendorAccount) {
                throw new GoneHttpException('This invitation is invalid or no longer available.');
            }

            $this->assertInvitee($user, $vendorInvitation->invited_email, $authenticatedUser);

            return [
                'membership_type' => 'vendor',
                'membership_id' => $membership->getKey(),
                'email' => $user->email,
                'tenant_name' => $vendor->display_name,
                'role_name' => $membership->roles()->orderBy('slug')->value('name') ?? 'vendor member',
                'requires_account_setup' => $user->email_verified_at === null,
                'expires_at' => $vendorInvitation->expires_at->toIso8601String(),
            ];
        }

        $customerInvitation = CustomerMembershipInvitation::query()->where('token_hash', $hash)->first();

        if ($customerInvitation instanceof CustomerMembershipInvitation) {
            $membership = CustomerMembership::query()->whereKey($customerInvitation->customer_membership_id)->where('status', 'invited')->first();
            $organization = $membership instanceof CustomerMembership
                ? CustomerOrganization::query()
                    ->whereKey($membership->customer_organization_id)
                    ->where('status', 'active')
                    ->with('vendorAccount')
                    ->first()
                : null;
            $user = $membership instanceof CustomerMembership
                ? User::query()->whereKey($membership->user_id)->where('status', 'active')->first()
                : null;

            $this->assertPending($customerInvitation, $membership, $user);

            if (! $organization instanceof CustomerOrganization || ! $organization->vendorAccount instanceof VendorAccount
                || $organization->vendorAccount->status !== 'active') {
                throw new GoneHttpException('This invitation is invalid or no longer available.');
            }

            $this->assertInvitee($user, $customerInvitation->invited_email, $authenticatedUser);

            return [
                'membership_type' => 'customer',
                'membership_id' => $membership->getKey(),
                'email' => $user->email,
                'tenant_name' => $organization->display_name,
                'role_name' => $membership->roles()->orderBy('slug')->value('name') ?? 'customer member',
                'requires_account_setup' => $user->email_verified_at === null,
                'expires_at' => $customerInvitation->expires_at->toIso8601String(),
            ];
        }

        throw new GoneHttpException('This invitation is invalid or no longer available.');
    }

    public function acceptNewAccount(string $tokenHash, string $name, string $password): void
    {
        $context = $this->lockInvitationContext($tokenHash);
        $user = $context['user'];

        if ($user->email_verified_at !== null) {
            throw new AuthorizationException('This account must sign in to accept its invitation.');
        }

        $user->forceFill([
            'name' => trim($name),
            'password' => $password,
        ])->save();
        $user->markEmailAsVerified();
        $this->sessionRevocation->revokeAll($user);

        $this->acceptLockedInvitation($context, $user);
    }

    public function acceptAuthenticated(string $tokenHash, User $authenticatedUser): void
    {
        $context = $this->lockInvitationContext($tokenHash);
        $user = $context['user'];

        if (
            $user->email_verified_at === null
            || $user->getKey() !== $authenticatedUser->getKey()
            || ! hash_equals($this->normalizeEmail($context['invitation']->invited_email), $this->normalizeEmail($user->email))
        ) {
            throw new AuthorizationException('This invitation belongs to a different account.');
        }

        $this->acceptLockedInvitation($context, $user);
    }

    /**
     * @return array{type: string, invitation: VendorMembershipInvitation|CustomerMembershipInvitation, membership: VendorMembership|CustomerMembership, user: User, tenant: VendorAccount|CustomerOrganization}
     */
    private function lockInvitationContext(string $tokenHash): array
    {
        $vendorRef = VendorMembershipInvitation::query()->where('token_hash', $tokenHash)->first();

        if ($vendorRef instanceof VendorMembershipInvitation) {
            $membershipRef = VendorMembership::query()->find($vendorRef->vendor_membership_id);

            if (! $membershipRef instanceof VendorMembership) {
                throw new GoneHttpException('This invitation is invalid or no longer available.');
            }

            $vendor = VendorAccount::query()
                ->whereKey($membershipRef->vendor_account_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            $membership = VendorMembership::query()
                ->whereKey($membershipRef->getKey())
                ->where('status', 'invited')
                ->lockForUpdate()
                ->first();
            $invitation = VendorMembershipInvitation::query()
                ->whereKey($vendorRef->getKey())
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();
            $user = $membership instanceof VendorMembership
                ? User::query()->whereKey($membership->user_id)->where('status', 'active')->lockForUpdate()->first()
                : null;

            if (
                ! $vendor instanceof VendorAccount
                || ! $membership instanceof VendorMembership
                || ! $invitation instanceof VendorMembershipInvitation
                || ! $user instanceof User
            ) {
                throw new GoneHttpException('This invitation is invalid or no longer available.');
            }

            $this->assertPending($invitation, $membership, $user);

            return ['type' => 'vendor', 'invitation' => $invitation, 'membership' => $membership, 'user' => $user, 'tenant' => $vendor];
        }

        $customerRef = CustomerMembershipInvitation::query()->where('token_hash', $tokenHash)->first();

        if (! $customerRef instanceof CustomerMembershipInvitation) {
            throw new GoneHttpException('This invitation is invalid or no longer available.');
        }

        $membershipRef = CustomerMembership::query()->find($customerRef->customer_membership_id);

        if (! $membershipRef instanceof CustomerMembership) {
            throw new GoneHttpException('This invitation is invalid or no longer available.');
        }

        $organization = CustomerOrganization::query()
            ->whereKey($membershipRef->customer_organization_id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();
        $vendor = $organization instanceof CustomerOrganization
            ? VendorAccount::query()
                ->whereKey($organization->vendor_account_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first()
            : null;
        $membership = CustomerMembership::query()
            ->whereKey($membershipRef->getKey())
            ->where('status', 'invited')
            ->lockForUpdate()
            ->first();
        $invitation = CustomerMembershipInvitation::query()
            ->whereKey($customerRef->getKey())
            ->where('token_hash', $tokenHash)
            ->lockForUpdate()
            ->first();
        $user = $membership instanceof CustomerMembership
            ? User::query()->whereKey($membership->user_id)->where('status', 'active')->lockForUpdate()->first()
            : null;

        if (
            ! $organization instanceof CustomerOrganization
            || ! $vendor instanceof VendorAccount
            || ! $membership instanceof CustomerMembership
            || ! $invitation instanceof CustomerMembershipInvitation
            || ! $user instanceof User
        ) {
            throw new GoneHttpException('This invitation is invalid or no longer available.');
        }

        $this->assertPending($invitation, $membership, $user);

        return ['type' => 'customer', 'invitation' => $invitation, 'membership' => $membership, 'user' => $user, 'tenant' => $organization];
    }

    /**
     * @param  array{type: string, invitation: VendorMembershipInvitation|CustomerMembershipInvitation, membership: VendorMembership|CustomerMembership, user: User, tenant: VendorAccount|CustomerOrganization}  $context
     */
    private function acceptLockedInvitation(array $context, User $user): void
    {
        $membership = $context['membership'];
        $invitation = $context['invitation'];

        $membership->forceFill([
            'status' => 'active',
            'joined_at' => now(),
        ])->save();

        $invitation->forceFill(['accepted_at' => now()])->save();

        if ($context['type'] === 'vendor' && $membership instanceof VendorMembership) {
            VendorMembershipInvitation::query()
                ->where('vendor_membership_id', $membership->getKey())
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->whereKeyNot($invitation->getKey())
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            VendorMembershipLifecycleEvent::query()->create([
                'vendor_membership_id' => $membership->getKey(),
                'actor_user_id' => $user->getKey(),
                'from_status' => 'invited',
                'to_status' => 'active',
                'reason' => 'Invitation accepted by the invited user.',
                'created_at' => now(),
            ]);

            return;
        }

        if ($membership instanceof CustomerMembership) {
            CustomerMembershipInvitation::query()
                ->where('customer_membership_id', $membership->getKey())
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->whereKeyNot($invitation->getKey())
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            CustomerMembershipLifecycleEvent::query()->create([
                'customer_membership_id' => $membership->getKey(),
                'actor_user_id' => $user->getKey(),
                'from_status' => 'invited',
                'to_status' => 'active',
                'reason' => 'Invitation accepted by the invited user.',
                'created_at' => now(),
            ]);

            return;
        }

        throw new GoneHttpException('This invitation is invalid or no longer available.');
    }

    private function findOrCreateInvitee(string $email, ?string $name): User
    {
        $user = User::query()->where('email', $email)->lockForUpdate()->first();

        if ($user instanceof User) {
            if ($user->status !== 'active') {
                throw new AuthorizationException('The invited account is not active.');
            }

            return $user;
        }

        return User::query()->create([
            'name' => $name !== null && trim($name) !== '' ? trim($name) : Str::before($email, '@'),
            'email' => $email,
            'password' => Str::random(64),
            'status' => 'active',
        ]);
    }

    private function assertPending(
        VendorMembershipInvitation|CustomerMembershipInvitation $invitation,
        VendorMembership|CustomerMembership|null $membership,
        ?User $user,
    ): void {
        if (
            $invitation->accepted_at !== null
            || $invitation->revoked_at !== null
            || $invitation->expires_at->isPast()
            || $membership === null
            || $membership->status !== 'invited'
            || $user === null
            || ! hash_equals($this->normalizeEmail($invitation->invited_email), $this->normalizeEmail($user->email))
        ) {
            throw new GoneHttpException('This invitation is invalid or no longer available.');
        }
    }

    private function assertInvitee(?User $user, string $invitedEmail, ?User $authenticatedUser): void
    {
        if (! $user instanceof User || ! hash_equals($this->normalizeEmail($invitedEmail), $this->normalizeEmail($user->email))) {
            throw new GoneHttpException('This invitation is invalid or no longer available.');
        }

        if ($authenticatedUser instanceof User && $authenticatedUser->getKey() !== $user->getKey()) {
            throw new AuthorizationException('This invitation belongs to a different account.');
        }
    }

    private function deliver(array $payload): void
    {
        $invitation = $payload['invitation'];
        $url = $this->acceptanceUrl($payload['token']);

        try {
            Mail::to($payload['email'])->send(new MembershipInvitationMail(
                $url,
                $payload['tenant_name'],
                $payload['role_name'],
                $payload['type'],
                $invitation->expires_at->toDayDateTimeString(),
            ));

            $invitation->forceFill([
                'sent_at' => now(),
                'delivery_status' => 'sent',
            ])->save();
        } catch (Throwable $exception) {
            $invitation->forceFill(['delivery_status' => 'failed'])->save();
            Log::warning('TLMS membership invitation email delivery failed.', [
                'invitation_id' => $invitation->getKey(),
                'membership_type' => $payload['type'],
                'exception' => $exception::class,
            ]);

            throw new RuntimeException('The invitation was created but its email could not be delivered. Resend it after correcting mail configuration.', 0, $exception);
        }
    }

    private function acceptanceUrl(string $token): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');
        $parts = parse_url($baseUrl);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null)) {
            throw new RuntimeException('APP_URL must be a trusted HTTPS origin before sending membership invitations.');
        }

        return $baseUrl.'/membership-invitations/accept#token='.rawurlencode($token);
    }

    private function invitationExpiry(): \Illuminate\Support\Carbon
    {
        return now()->addHours(max(1, min(168, (int) config('tlms.memberships.invitation_ttl_hours', 72))));
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function validateReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new AuthorizationException('A valid invitation reason is required.');
        }

        return $reason;
    }
}

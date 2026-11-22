<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorAccountProfileEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class VendorAccountProfileManagementService
{
    public function __construct(
        private MembershipAuthorization $membershipAuthorization,
        private PlatformSuperAdminAuthorization $platformAuthorization,
        private PlatformAccessAuditChainWriter $auditWriter,
        private PlatformAccessAuditChainHasher $auditHasher,
    ) {}

    public function manageableVendor(User $actor): ?VendorAccount
    {
        $vendor = VendorAccount::query()
            ->where('installation_key', 'primary')
            ->where('status', 'active')
            ->first();

        if (
            ! $vendor instanceof VendorAccount
            || $this->platformAuthorization->isBootstrapSuperAdmin($actor)
            || ! $this->membershipAuthorization->vendorCan($actor, $vendor, 'vendor.account.profile.manage')
        ) {
            return null;
        }

        return $vendor;
    }

    public function updateDisplayName(
        User $actor,
        string $displayName,
        string $reason,
        string $requestId,
    ): VendorAccount {
        $displayName = trim($displayName);
        $reason = trim($reason);

        if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 160 || $reason === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Vendor profile update metadata is invalid.');
        }

        return DB::transaction(function () use ($actor, $displayName, $reason, $requestId): VendorAccount {
            $vendor = VendorAccount::query()
                ->where('installation_key', 'primary')
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $vendor instanceof VendorAccount) {
                throw new AuthorizationException('The primary vendor account is unavailable.');
            }

            $this->authorizeManager($actor, $vendor);

            $beforeDisplayName = (string) $vendor->display_name;
            if ($beforeDisplayName === $displayName) {
                return $vendor;
            }

            $vendor->forceFill(['display_name' => $displayName])->save();
            $normalizedRequestId = $this->normalizeRequestId($requestId);
            $changeDigest = $this->auditHasher->hashProfileChangeSet([
                'before' => ['display_name' => $beforeDisplayName],
                'after' => ['display_name' => $displayName],
            ]);

            $profileEvent = VendorAccountProfileEvent::query()->create([
                'vendor_account_id' => $vendor->getKey(),
                'actor_user_id' => $actor->getKey(),
                'event_type' => 'profile_updated',
                'reason' => $reason,
                'changed_fields' => ['display_name'],
                'change_digest' => $changeDigest,
                'request_id' => $normalizedRequestId,
                'created_at' => now(),
            ]);

            $createdAt = (string) DB::table('vendor_account_profile_events')
                ->where('id', $profileEvent->getKey())
                ->value('created_at');

            $detailsHash = $this->auditHasher->hashVendorAccountProfileEvent(
                (string) $profileEvent->getKey(),
                (string) $profileEvent->vendor_account_id,
                (string) $profileEvent->actor_user_id,
                (string) $profileEvent->event_type,
                (string) $profileEvent->reason,
                $profileEvent->changed_fields,
                (string) $profileEvent->change_digest,
                (string) $profileEvent->request_id,
                $createdAt,
            );

            $this->auditWriter->append(
                (string) $actor->getKey(),
                'vendor_account.profile_updated',
                'vendor_account',
                (string) $vendor->getKey(),
                (string) $vendor->getKey(),
                'allowed',
                'vendor_account_profile_management',
                $normalizedRequestId,
                (string) $profileEvent->getKey(),
                $detailsHash,
            );

            return $vendor->refresh();
        }, 3);
    }

    private function authorizeManager(User $actor, VendorAccount $vendor): void
    {
        if (
            $this->platformAuthorization->isBootstrapSuperAdmin($actor)
            || ! $this->membershipAuthorization->vendorCan($actor, $vendor, 'vendor.account.profile.manage')
        ) {
            throw new AuthorizationException('The actor cannot manage this vendor profile.');
        }
    }

    private function normalizeRequestId(string $requestId): string
    {
        return preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $requestId) === 1
            ? $requestId
            : bin2hex(random_bytes(16));
    }
}

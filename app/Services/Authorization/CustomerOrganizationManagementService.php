<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\CustomerOrganization;
use App\Models\CustomerOrganizationProfileEvent;
use App\Models\User;
use App\Models\VendorAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CustomerOrganizationManagementService
{
    public function __construct(private MembershipAuthorization $authorization) {}

    public function primaryVendor(): VendorAccount
    {
        return VendorAccount::query()
            ->where('installation_key', 'primary')
            ->where('status', 'active')
            ->firstOrFail();
    }

    /** @return LengthAwarePaginator<int, CustomerOrganization> */
    public function paginate(User $actor): LengthAwarePaginator
    {
        $vendor = $this->primaryVendor();
        $this->authorizeManager($actor, $vendor);

        return CustomerOrganization::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->withCount('memberships')
            ->orderBy('display_name')
            ->orderBy('id')
            ->paginate(25);
    }

    /**
     * @param  array{legal_name: string, display_name: string, external_reference: ?string, billing_email: ?string}  $attributes
     */
    public function create(
        User $actor,
        array $attributes,
        string $reason,
        string $requestId,
    ): CustomerOrganization {
        return DB::transaction(function () use ($actor, $attributes, $reason, $requestId): CustomerOrganization {
            $vendor = VendorAccount::query()
                ->where('installation_key', 'primary')
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $vendor instanceof VendorAccount) {
                throw new ModelNotFoundException('The active primary vendor account was not found.');
            }

            $this->authorizeManager($actor, $vendor);
            $this->assertExternalReferenceAvailable($vendor, $attributes['external_reference'] ?? null);

            $organization = CustomerOrganization::query()->create([
                'vendor_account_id' => $vendor->getKey(),
                'external_reference' => $attributes['external_reference'] ?? null,
                'legal_name' => trim($attributes['legal_name']),
                'display_name' => trim($attributes['display_name']),
                'billing_email' => isset($attributes['billing_email']) && $attributes['billing_email'] !== ''
                    ? mb_strtolower(trim($attributes['billing_email']))
                    : null,
                'status' => 'active',
            ]);

            CustomerOrganizationProfileEvent::query()->create([
                'customer_organization_id' => $organization->getKey(),
                'actor_user_id' => $actor->getKey(),
                'event_type' => 'created',
                'reason' => trim($reason),
                'changed_fields' => ['legal_name', 'display_name', 'external_reference', 'billing_email'],
                'request_id' => $this->normalizeRequestId($requestId),
                'created_at' => now(),
            ]);

            return $organization;
        }, 3);
    }

    /**
     * @param array{legal_name: string, display_name: string, external_reference: ?string, billing_email: ?string} $attributes
     */
    public function update(
        User $actor,
        CustomerOrganization $organization,
        array $attributes,
        string $reason,
        string $requestId,
    ): CustomerOrganization {
        return DB::transaction(function () use ($actor, $organization, $attributes, $reason, $requestId): CustomerOrganization {
            $vendor = VendorAccount::query()
                ->where('installation_key', 'primary')
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $vendor instanceof VendorAccount) {
                throw new ModelNotFoundException('The active primary vendor account was not found.');
            }

            $this->authorizeManager($actor, $vendor);

            $current = CustomerOrganization::query()
                ->whereKey($organization->getKey())
                ->where('vendor_account_id', $vendor->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $current instanceof CustomerOrganization) {
                throw new ModelNotFoundException('The customer organization is not available for profile updates.');
            }

            $this->assertExternalReferenceAvailable(
                $vendor,
                $attributes['external_reference'] ?? null,
                (string) $current->getKey(),
            );

            $changes = [];
            foreach (['legal_name', 'display_name', 'external_reference', 'billing_email'] as $field) {
                $value = $attributes[$field] ?? null;
                if (in_array($field, ['legal_name', 'display_name'], true)) {
                    $value = trim((string) $value);
                } elseif ($field === 'billing_email' && is_string($value) && $value !== '') {
                    $value = mb_strtolower(trim($value));
                }

                if ($current->getAttribute($field) !== $value) {
                    $changes[$field] = $value;
                }
            }

            if ($changes === []) {
                return $current;
            }

            $current->fill($changes)->save();

            CustomerOrganizationProfileEvent::query()->create([
                'customer_organization_id' => $current->getKey(),
                'actor_user_id' => $actor->getKey(),
                'event_type' => 'profile_updated',
                'reason' => trim($reason),
                'changed_fields' => array_keys($changes),
                'request_id' => $this->normalizeRequestId($requestId),
                'created_at' => now(),
            ]);

            return $current->refresh();
        }, 3);
    }

    private function authorizeManager(User $actor, VendorAccount $vendor): void
    {
        $activeActor = User::query()
            ->whereKey($actor->getKey())
            ->where('status', 'active')
            ->exists();

        if (! $activeActor || ! $this->authorization->vendorCan($actor, $vendor, 'customers.manage')) {
            throw new AuthorizationException('The actor cannot manage customer organizations for this vendor.');
        }
    }

    private function assertExternalReferenceAvailable(
        VendorAccount $vendor,
        ?string $reference,
        ?string $exceptOrganizationId = null,
    ): void {
        if ($reference === null || trim($reference) === '') {
            return;
        }

        $query = CustomerOrganization::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('external_reference', trim($reference));

        if ($exceptOrganizationId !== null) {
            $query->where('id', '<>', $exceptOrganizationId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'external_reference' => ['This external reference is already in use for the primary vendor.'],
            ]);
        }
    }

    private function normalizeRequestId(string $requestId): string
    {
        return preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $requestId) === 1
            ? $requestId
            : bin2hex(random_bytes(16));
    }
}

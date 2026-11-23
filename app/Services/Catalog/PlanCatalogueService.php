<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Exceptions\PlanVersionConflictException;
use App\Models\Plan;
use App\Models\PlanEvent;
use App\Models\PlanVersion;
use App\Models\Product;
use App\Models\ProductAssignment;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Services\Authorization\MembershipAuthorization;
use App\Services\Authorization\PlatformAccessAuditChainHasher;
use App\Services\Authorization\PlatformAccessAuditChainWriter;
use App\Services\Authorization\PlatformSuperAdminAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class PlanCatalogueService
{
    public function __construct(
        private MembershipAuthorization $membershipAuthorization,
        private PlatformSuperAdminAuthorization $platformAuthorization,
        private PlatformAccessAuditChainWriter $auditWriter,
        private PlatformAccessAuditChainHasher $auditHasher,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $actor, Product $product, array $input, string $requestId): Plan
    {
        return DB::transaction(function () use ($actor, $product, $input, $requestId): Plan {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $vendor = VendorAccount::query()
                ->whereKey($locked->vendor_account_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $vendor instanceof VendorAccount) {
                throw new AuthorizationException('The product vendor is unavailable.');
            }

            $this->authorizeProductManager($actor, $locked, $vendor, 'plans.entitlements.manage');

            $code = strtolower(trim((string) $input['code']));
            $name = trim((string) $input['name']);
            $description = isset($input['description']) ? trim((string) $input['description']) : null;
            $reason = trim((string) $input['reason']);

            if (
                preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $code) !== 1
                || mb_strlen($code) > 80
                || mb_strlen($name) < 2
                || mb_strlen($name) > 160
                || ($description !== null && mb_strlen($description) > 2000)
                || mb_strlen($reason) < 5
                || mb_strlen($reason) > 500
            ) {
                throw new InvalidArgumentException('Plan metadata is invalid.');
            }

            if (Plan::query()->where('product_id', $locked->getKey())->where('code', $code)->exists()) {
                throw new InvalidArgumentException('A plan with this code already exists for the product.');
            }

            $plan = Plan::query()->create([
                'product_id' => $locked->getKey(),
                'vendor_account_id' => $vendor->getKey(),
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'status' => 'draft',
                'version' => 1,
                'created_by_user_id' => $actor->getKey(),
            ]);

            $this->event($plan, null, $actor, 'created', $reason, $requestId, ['code' => $plan->code]);

            return $plan->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createVersion(User $actor, Plan $plan, array $input, string $requestId): PlanVersion
    {
        return DB::transaction(function () use ($actor, $plan, $input, $requestId): PlanVersion {
            $locked = Plan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->version !== (int) $input['expected_version']) {
                throw new PlanVersionConflictException('The plan was changed by another request.');
            }

            $product = Product::query()->whereKey($locked->product_id)->lockForUpdate()->firstOrFail();
            $vendor = VendorAccount::query()
                ->whereKey($locked->vendor_account_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $vendor instanceof VendorAccount || (string) $product->vendor_account_id !== (string) $vendor->getKey()) {
                throw new AuthorizationException('The plan vendor is unavailable.');
            }

            $this->authorizeProductManager($actor, $product, $vendor, 'plans.entitlements.manage');

            $number = (int) $locked->versions()->max('version_number') + 1;
            $values = $this->normalizeVersionInput($input);
            $version = PlanVersion::query()->create([
                'plan_id' => $locked->getKey(),
                'product_id' => $locked->product_id,
                'vendor_account_id' => $locked->vendor_account_id,
                'version_number' => $number,
                'status' => 'draft',
                ...$values,
                'created_by_user_id' => $actor->getKey(),
            ]);

            $locked->forceFill(['version' => (int) $locked->version + 1])->save();
            $this->event($locked, $version, $actor, 'version_created', (string) $input['reason'], $requestId, [
                'version_number' => $number,
                'billing_model' => $values['billing_model'],
                'currency' => $values['currency'],
                'price_minor' => $values['price_minor'],
                'billing_interval' => $values['billing_interval'],
                'trial_days' => $values['trial_days'],
                'maintenance_days' => $values['maintenance_days'],
                'entitlements' => $values['entitlements'],
            ]);

            return $version->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateDraftVersion(
        User $actor,
        Plan $plan,
        PlanVersion $version,
        array $input,
        string $requestId,
    ): PlanVersion {
        return DB::transaction(function () use ($actor, $plan, $version, $input, $requestId): PlanVersion {
            $lockedPlan = Plan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $lockedPlan->version !== (int) $input['expected_version']) {
                throw new PlanVersionConflictException('The plan was changed by another request.');
            }

            $lockedVersion = PlanVersion::query()
                ->whereKey($version->getKey())
                ->where('plan_id', $lockedPlan->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedVersion->status !== 'draft') {
                throw new AuthorizationException('Published plan versions are immutable.');
            }

            $product = Product::query()->whereKey($lockedPlan->product_id)->lockForUpdate()->firstOrFail();
            $vendor = VendorAccount::query()
                ->whereKey($lockedPlan->vendor_account_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $vendor instanceof VendorAccount || (string) $product->vendor_account_id !== (string) $vendor->getKey()) {
                throw new AuthorizationException('The plan vendor is unavailable.');
            }

            $this->authorizeProductManager($actor, $product, $vendor, 'plans.entitlements.manage');
            $values = $this->normalizeVersionInput($input);
            $before = $this->versionSnapshot($lockedVersion);
            $after = [
                'billing_model' => $values['billing_model'],
                'currency' => $values['currency'],
                'price_minor' => $values['price_minor'],
                'billing_interval' => $values['billing_interval'],
                'trial_days' => $values['trial_days'],
                'maintenance_days' => $values['maintenance_days'],
                'entitlements' => $values['entitlements'],
            ];

            if ($before === $after) {
                return $lockedVersion;
            }

            $lockedVersion->forceFill($values)->save();
            $lockedPlan->forceFill(['version' => (int) $lockedPlan->version + 1])->save();

            $this->event(
                $lockedPlan,
                $lockedVersion,
                $actor,
                'version_updated',
                (string) $input['reason'],
                $requestId,
                ['before' => $before, 'after' => $after],
            );

            return $lockedVersion->refresh();
        }, 3);
    }

    public function publish(
        User $actor,
        Plan $plan,
        PlanVersion $version,
        int $expectedVersion,
        string $reason,
        string $requestId,
    ): PlanVersion {
        return DB::transaction(function () use ($actor, $plan, $version, $expectedVersion, $reason, $requestId): PlanVersion {
            $lockedPlan = Plan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $lockedPlan->version !== $expectedVersion) {
                throw new PlanVersionConflictException('The plan was changed by another request.');
            }

            $locked = PlanVersion::query()
                ->whereKey($version->getKey())
                ->where('plan_id', $lockedPlan->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'draft') {
                throw new InvalidArgumentException('Only draft plan versions can be published.');
            }

            $product = Product::query()->whereKey($lockedPlan->product_id)->lockForUpdate()->firstOrFail();
            $vendor = VendorAccount::query()
                ->whereKey($lockedPlan->vendor_account_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $vendor instanceof VendorAccount || (string) $product->vendor_account_id !== (string) $vendor->getKey()) {
                throw new AuthorizationException('The plan vendor is unavailable.');
            }

            $this->authorizeProductManager($actor, $product, $vendor, 'releases.publish');

            $snapshot = $this->versionSnapshot($locked);
            ksort($snapshot, SORT_STRING);
            $entitlements = $snapshot['entitlements'];
            if (is_array($entitlements)) {
                ksort($entitlements, SORT_STRING);
                $snapshot['entitlements'] = $entitlements;
            }

            $locked->forceFill([
                'status' => 'published',
                'published_at' => now(),
                'snapshot_sha256' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            ])->save();

            $lockedPlan->forceFill(['version' => (int) $lockedPlan->version + 1])->save();

            $this->event(
                $lockedPlan,
                $locked,
                $actor,
                'version_published',
                trim($reason),
                $requestId,
                ['snapshot_sha256' => $locked->snapshot_sha256],
            );

            return $locked->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *   billing_model: string,
     *   currency: string,
     *   price_minor: int,
     *   billing_interval: string|null,
     *   trial_days: int,
     *   maintenance_days: int,
     *   entitlements: array<string, bool|int|string>
     * }
     */
    private function normalizeVersionInput(array $input): array
    {
        $entitlements = [];
        foreach ($input['entitlements'] as $entry) {
            $key = (string) $entry['key'];
            if (array_key_exists($key, $entitlements)) {
                throw new InvalidArgumentException('Entitlement keys must be unique.');
            }

            $value = $entry['value'];
            $valid = match ($entry['type']) {
                'boolean' => is_bool($value),
                'integer' => is_int($value),
                'string' => is_string($value) && mb_strlen($value) <= 1000,
                default => false,
            };

            if (! $valid) {
                throw new InvalidArgumentException('Entitlement value does not match its declared type.');
            }

            $entitlements[$key] = $value;
        }

        ksort($entitlements, SORT_STRING);
        $billingModel = (string) $input['billing_model'];
        $billingInterval = isset($input['billing_interval']) && $input['billing_interval'] !== ''
            ? (string) $input['billing_interval']
            : null;
        $trialDays = (int) $input['trial_days'];
        $priceMinor = (int) $input['price_minor'];
        $currency = strtoupper((string) $input['currency']);

        if (
            $priceMinor < 0
            || $priceMinor > 9007199254740991
            || preg_match('/\\A[A-Z]{3}\\z/D', $currency) !== 1
            || ($billingModel === 'subscription' && ! in_array($billingInterval, ['month', 'year'], true))
            || ($billingModel !== 'subscription' && $billingInterval !== null)
            || ($billingModel === 'trial' && $trialDays < 1)
        ) {
            throw new InvalidArgumentException('Billing interval and trial duration do not match the billing model.');
        }

        return [
            'billing_model' => $billingModel,
            'currency' => $currency,
            'price_minor' => $priceMinor,
            'billing_interval' => $billingInterval,
            'trial_days' => $trialDays,
            'maintenance_days' => (int) $input['maintenance_days'],
            'entitlements' => $entitlements,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function versionSnapshot(PlanVersion $version): array
    {
        return [
            'plan_id' => (string) $version->plan_id,
            'version_number' => (int) $version->version_number,
            'billing_model' => (string) $version->billing_model,
            'currency' => (string) $version->currency,
            'price_minor' => (int) $version->price_minor,
            'billing_interval' => $version->billing_interval,
            'trial_days' => (int) $version->trial_days,
            'maintenance_days' => (int) $version->maintenance_days,
            'entitlements' => $version->entitlements,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(
        Plan $plan,
        ?PlanVersion $version,
        User $actor,
        string $type,
        string $reason,
        string $requestId,
        array $payload,
    ): void {
        $normalizedRequestId = preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $requestId) === 1
            ? $requestId
            : (string) Str::uuid();

        $event = PlanEvent::query()->create([
            'plan_id' => $plan->getKey(),
            'plan_version_id' => $version?->getKey(),
            'product_id' => $plan->product_id,
            'vendor_account_id' => $plan->vendor_account_id,
            'actor_user_id' => $actor->getKey(),
            'event_type' => $type,
            'reason' => trim($reason),
            'request_id' => $normalizedRequestId,
            'event_payload' => $payload,
            'created_at' => now(),
        ]);

        $createdAt = (string) DB::table('plan_events')->where('id', $event->getKey())->value('created_at');
        $detailsHash = $this->auditHasher->hashPlanEvent(
            (string) $event->getKey(),
            (string) $event->plan_id,
            $event->plan_version_id === null ? null : (string) $event->plan_version_id,
            (string) $event->product_id,
            (string) $event->vendor_account_id,
            (string) $event->actor_user_id,
            (string) $event->event_type,
            (string) $event->reason,
            (string) $event->request_id,
            $event->event_payload,
            $createdAt,
        );

        $isPlanCreation = $type === 'created';
        $action = match ($type) {
            'created' => 'plan.created',
            'version_created' => 'plan_version.created',
            'version_updated' => 'plan_version.updated',
            'version_published' => 'plan_version.published',
            default => throw new InvalidArgumentException('Plan event type is invalid.'),
        };

        $this->auditWriter->append(
            (string) $actor->getKey(),
            $action,
            $isPlanCreation ? 'plan' : 'plan_version',
            (string) ($version?->getKey() ?? $plan->getKey()),
            (string) $plan->vendor_account_id,
            'allowed',
            'plan_catalogue_management',
            $normalizedRequestId,
            (string) $event->getKey(),
            $detailsHash,
        );
    }

    private function authorizeProductManager(User $actor, Product $product, VendorAccount $vendor, string $permission): void
    {
        if ($this->platformAuthorization->isBootstrapSuperAdmin($actor)) {
            throw new AuthorizationException('The actor does not have the required plan-catalogue permission.');
        }

        $membership = VendorMembership::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if (
            ! $membership instanceof VendorMembership
            || ! $this->membershipAuthorization->vendorCan($actor, $vendor, $permission)
        ) {
            throw new AuthorizationException('The actor does not have the required plan-catalogue permission.');
        }

        $isVendorSuperAdmin = $actor->vendorMemberships()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('status', 'active')
            ->whereHas('roles', static fn ($query) => $query
                ->where('scope', 'vendor')
                ->where('slug', 'super_admin'))
            ->exists();

        if ($isVendorSuperAdmin) {
            return;
        }

        if (! ProductAssignment::query()
            ->where('product_id', $product->getKey())
            ->where('vendor_membership_id', $membership->getKey())
            ->whereNull('revoked_at')
            ->exists()) {
            throw new AuthorizationException('The actor is not assigned to this product.');
        }
    }
}

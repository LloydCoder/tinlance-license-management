<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Plan;
use App\Models\PlanEvent;
use App\Models\PlanVersion;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PlanCatalogueService
{
    public function create(User $actor, Product $product, array $input, string $requestId): Plan
    {
        return DB::transaction(function () use ($actor, $product, $input, $requestId): Plan {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->vendorAccount()->where('status','active')->doesntExist()) {
                throw new InvalidArgumentException('The product vendor is not active.');
            }
            $plan = Plan::query()->create([
                'product_id'=>$locked->getKey(), 'vendor_account_id'=>$locked->vendor_account_id,
                'code'=>strtolower(trim($input['code'])), 'name'=>trim($input['name']),
                'description'=>isset($input['description']) ? trim($input['description']) : null,
                'status'=>'draft','version'=>1,'created_by_user_id'=>$actor->getKey(),
            ]);
            $this->event($plan, null, $actor, 'created', $input['reason'], $requestId, ['code'=>$plan->code]);
            return $plan->refresh();
        }, 3);
    }

    public function createVersion(User $actor, Plan $plan, array $input, string $requestId): PlanVersion
    {
        return DB::transaction(function () use ($actor, $plan, $input, $requestId): PlanVersion {
            $locked = Plan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            $number = (int) $locked->versions()->max('version_number') + 1;
            $entitlements = [];
            foreach ($input['entitlements'] as $entry) {
                $key = $entry['key'];
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
            $version = PlanVersion::query()->create([
                'plan_id'=>$locked->getKey(),'product_id'=>$locked->product_id,'vendor_account_id'=>$locked->vendor_account_id,
                'version_number'=>$number,'status'=>'draft','billing_model'=>$input['billing_model'],
                'currency'=>strtoupper($input['currency']),'price_minor'=>$input['price_minor'],
                'billing_interval'=>$input['billing_interval'] ?: null,'trial_days'=>$input['trial_days'],
                'maintenance_days'=>$input['maintenance_days'],'entitlements'=>$entitlements,
                'created_by_user_id'=>$actor->getKey(),
            ]);
            $this->event($locked, $version, $actor, 'version_created', $input['reason'], $requestId, ['version_number'=>$number]);
            return $version->refresh();
        }, 3);
    }

    public function publish(User $actor, Plan $plan, PlanVersion $version, string $reason, string $requestId): PlanVersion
    {
        return DB::transaction(function () use ($actor, $plan, $version, $reason, $requestId): PlanVersion {
            $lockedPlan = Plan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            $locked = PlanVersion::query()->whereKey($version->getKey())->where('plan_id',$lockedPlan->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw new InvalidArgumentException('Only draft plan versions can be published.');
            }
            $snapshot = [
                'plan_id'=>$lockedPlan->getKey(),'version_number'=>$locked->version_number,
                'billing_model'=>$locked->billing_model,'currency'=>$locked->currency,
                'price_minor'=>$locked->price_minor,'billing_interval'=>$locked->billing_interval,
                'trial_days'=>$locked->trial_days,'maintenance_days'=>$locked->maintenance_days,
                'entitlements'=>$locked->entitlements,
            ];
            $locked->forceFill(['status'=>'published','published_at'=>now(),'snapshot_sha256'=>hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))])->save();
            $this->event($lockedPlan, $locked, $actor, 'version_published', $reason, $requestId, ['snapshot_sha256'=>$locked->snapshot_sha256]);
            return $locked->refresh();
        }, 3);
    }

    private function event(Plan $plan, ?PlanVersion $version, User $actor, string $type, string $reason, string $requestId, array $payload): void
    {
        PlanEvent::query()->create([
            'plan_id'=>$plan->getKey(),'plan_version_id'=>$version?->getKey(),'product_id'=>$plan->product_id,
            'vendor_account_id'=>$plan->vendor_account_id,'actor_user_id'=>$actor->getKey(),'event_type'=>$type,
            'reason'=>trim($reason),'request_id'=>preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D',$requestId)===1?$requestId:(string)Str::uuid(),
            'event_payload'=>$payload,'created_at'=>now(),
        ]);
    }
}

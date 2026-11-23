<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Exceptions\PlanVersionConflictException;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\PlanCatalogueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PlanCatalogueController
{
    public function index(Request $request, string $product): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $record = Product::query()->findOrFail($product);
        Gate::forUser($actor)->authorize('view', $record);

        $plans = Plan::query()
            ->where('product_id', $record->getKey())
            ->orderBy('code')
            ->paginate(25);

        return response()->json([
            'data' => $plans->getCollection()
                ->map(fn (Plan $plan): array => $this->serializePlan($plan))
                ->values(),
            'meta' => [
                'page' => $plans->currentPage(),
                'per_page' => $plans->perPage(),
                'total' => $plans->total(),
            ],
            'request_id' => $this->requestId($request),
        ]);
    }

    public function show(Request $request, string $plan): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $record = Plan::query()->findOrFail($plan);
        $product = Product::query()->findOrFail($record->product_id);
        Gate::forUser($actor)->authorize('view', $product);

        return response()->json([
            'data' => $this->serializePlan($record),
            'request_id' => $this->requestId($request),
        ]);
    }

    public function versions(Request $request, string $plan): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $record = Plan::query()->findOrFail($plan);
        $product = Product::query()->findOrFail($record->product_id);
        Gate::forUser($actor)->authorize('view', $product);

        $versions = PlanVersion::query()
            ->where('plan_id', $record->getKey())
            ->orderByDesc('version_number')
            ->paginate(25);

        return response()->json([
            'data' => $versions->getCollection()
                ->map(fn (PlanVersion $version): array => $this->serializeVersion($version))
                ->values(),
            'meta' => [
                'page' => $versions->currentPage(),
                'per_page' => $versions->perPage(),
                'total' => $versions->total(),
            ],
            'request_id' => $this->requestId($request),
        ]);
    }

    public function store(Request $request, string $product, PlanCatalogueService $catalogue): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $record = Product::query()->findOrFail($product);
        Gate::forUser($actor)->authorize('manageVersions', $record);

        $input = $request->validate([
            'code' => ['required', 'string', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', 'min:2', 'max:80'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $plan = $catalogue->create($actor, $record, $input, $this->requestId($request));
        } catch (InvalidArgumentException $exception) {
            return $this->problem($exception->getMessage(), $request);
        }

        return response()->json([
            'data' => $this->serializePlan($plan),
            'request_id' => $this->requestId($request),
        ], 201);
    }

    public function createVersion(Request $request, string $plan, PlanCatalogueService $catalogue): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $record = Plan::query()->findOrFail($plan);
        $product = Product::query()->findOrFail($record->product_id);
        Gate::forUser($actor)->authorize('manageVersions', $product);

        $input = $request->validate($this->versionRules() + [
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $version = $catalogue->createVersion($actor, $record, $input, $this->requestId($request));
        } catch (PlanVersionConflictException $exception) {
            return $this->conflict($exception->getMessage(), $request);
        } catch (InvalidArgumentException $exception) {
            return $this->problem($exception->getMessage(), $request);
        }

        return response()->json([
            'data' => $this->serializeVersion($version),
            'request_id' => $this->requestId($request),
        ], 201);
    }

    public function publish(Request $request, string $plan, string $version, PlanCatalogueService $catalogue): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $record = Plan::query()->findOrFail($plan);
        $product = Product::query()->findOrFail($record->product_id);
        Gate::forUser($actor)->authorize('publishVersions', $product);
        $versionRecord = PlanVersion::query()
            ->whereKey($version)
            ->where('plan_id', $record->getKey())
            ->firstOrFail();

        $input = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $published = $catalogue->publish(
                $actor,
                $record,
                $versionRecord,
                (int) $input['expected_version'],
                $input['reason'],
                $this->requestId($request),
            );
        } catch (PlanVersionConflictException $exception) {
            return $this->conflict($exception->getMessage(), $request);
        } catch (InvalidArgumentException $exception) {
            return $this->problem($exception->getMessage(), $request);
        }

        return response()->json([
            'data' => $this->serializeVersion($published),
            'request_id' => $this->requestId($request),
        ]);
    }

    public function updateVersion(Request $request, string $plan, string $version, PlanCatalogueService $catalogue): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $record = Plan::query()->findOrFail($plan);
        $product = Product::query()->findOrFail($record->product_id);
        Gate::forUser($actor)->authorize('manageVersions', $product);
        $versionRecord = PlanVersion::query()
            ->whereKey($version)
            ->where('plan_id', $record->getKey())
            ->firstOrFail();

        abort_if($versionRecord->status !== 'draft', 403, 'Published plan versions are immutable.');

        $input = $request->validate($this->versionRules() + [
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $updated = $catalogue->updateDraftVersion(
                $actor,
                $record,
                $versionRecord,
                $input,
                $this->requestId($request),
            );
        } catch (PlanVersionConflictException $exception) {
            return $this->conflict($exception->getMessage(), $request);
        } catch (InvalidArgumentException $exception) {
            return $this->problem($exception->getMessage(), $request);
        }

        return response()->json([
            'data' => $this->serializeVersion($updated),
            'request_id' => $this->requestId($request),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function versionRules(): array
    {
        return [
            'billing_model' => ['required', 'in:perpetual,subscription,annual,trial'],
            'currency' => ['required', 'string', 'regex:/\A[A-Za-z]{3}\z/D'],
            'price_minor' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'billing_interval' => ['nullable', 'in:month,year'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'maintenance_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'entitlements' => ['present', 'array', 'max:100'],
            'entitlements.*.key' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_.-]{0,63}\z/D'],
            'entitlements.*.type' => ['required', 'in:boolean,integer,string'],
            'entitlements.*.value' => ['present'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePlan(Plan $plan): array
    {
        return [
            'id' => (string) $plan->getKey(),
            'product_id' => (string) $plan->product_id,
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'status' => $plan->status,
            'version' => (int) $plan->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeVersion(PlanVersion $version): array
    {
        return [
            'id' => (string) $version->getKey(),
            'plan_id' => (string) $version->plan_id,
            'version_number' => (int) $version->version_number,
            'status' => $version->status,
            'billing_model' => $version->billing_model,
            'currency' => $version->currency,
            'price_minor' => (int) $version->price_minor,
            'billing_interval' => $version->billing_interval,
            'trial_days' => (int) $version->trial_days,
            'maintenance_days' => (int) $version->maintenance_days,
            'entitlements' => (object) $version->entitlements,
            'snapshot_sha256' => $version->snapshot_sha256,
            'published_at' => $version->published_at?->toISOString(),
        ];
    }

    private function problem(string $detail, Request $request): JsonResponse
    {
        return response()->json([
            'type' => 'https://tlms.tinlance.com/problems/plan-validation',
            'title' => 'Plan catalogue validation failed',
            'status' => 422,
            'detail' => $detail,
            'code' => 'PLAN_INVALID',
            'request_id' => $this->requestId($request),
        ], 422);
    }

    private function conflict(string $detail, Request $request): JsonResponse
    {
        return response()->json([
            'type' => 'https://tlms.tinlance.com/problems/plan-version-conflict',
            'title' => 'Plan version conflict',
            'status' => 409,
            'detail' => $detail,
            'code' => 'PLAN_VERSION_CONFLICT',
            'request_id' => $this->requestId($request),
        ], 409);
    }

    private function requestId(Request $request): string
    {
        $id = (string) $request->headers->get('X-Request-ID', '');
        if (preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $id) === 1) {
            return $id;
        }

        $requestId = $request->attributes->get('tlms_request_id');
        if (! is_string($requestId)) {
            $requestId = (string) Str::uuid();
            $request->attributes->set('tlms_request_id', $requestId);
        }

        return $requestId;
    }
}

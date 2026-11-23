<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Exceptions\ProductVersionConflictException;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use App\Services\Catalog\ProductVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ProductVersionController
{
    public function index(Request $request, string $product, ProductVersionService $versions): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $record = Product::query()->findOrFail($product);
        abort_unless(Gate::forUser($actor)->allows('view', $record), 404);

        $paginator = $versions->listFor($record, $request->integer('per_page', 25));

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (ProductVersion $version): array => $this->serialize($version))->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'request_id' => $this->requestId($request),
        ]);
    }

    public function store(Request $request, string $product, ProductVersionService $versions): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $record = Product::query()->findOrFail($product);
        Gate::forUser($actor)->authorize('manageVersions', $record);

        $validated = $request->validate([
            'version' => ['required', 'string', 'max:64'],
            'release_notes' => ['nullable', 'string', 'max:5000'],
            'artifact_sha256' => ['nullable', 'string', 'regex:/\A[a-fA-F0-9]{64}\z/D'],
            'artifact_size_bytes' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $version = $versions->create(
                $actor,
                $record,
                $validated['version'],
                $validated['release_notes'] ?? null,
                $validated['artifact_sha256'] ?? null,
                isset($validated['artifact_size_bytes']) ? (int) $validated['artifact_size_bytes'] : null,
                $validated['reason'],
                $this->requestId($request),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->validationProblem($exception->getMessage(), $request);
        }

        return response()->json([
            'data' => $this->serialize($version),
            'request_id' => $this->requestId($request),
        ], 201);
    }

    public function show(Request $request, string $product, string $productVersion): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $record = Product::query()->findOrFail($product);
        $version = ProductVersion::query()->whereKey($productVersion)->where('product_id', $record->getKey())->firstOrFail();
        abort_unless(Gate::forUser($actor)->allows('view', $version), 404);

        return response()->json([
            'data' => $this->serialize($version),
            'request_id' => $this->requestId($request),
        ]);
    }

    public function update(Request $request, string $product, string $productVersion, ProductVersionService $versions): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $record = Product::query()->findOrFail($product);
        $version = ProductVersion::query()->whereKey($productVersion)->where('product_id', $record->getKey())->firstOrFail();
        Gate::forUser($actor)->authorize('update', $version);

        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'release_notes' => ['present', 'nullable', 'string', 'max:5000'],
            'artifact_sha256' => ['present', 'nullable', 'string', 'regex:/\A[a-fA-F0-9]{64}\z/D'],
            'artifact_size_bytes' => ['present', 'nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $updated = $versions->update(
                $actor,
                $version,
                (int) $validated['expected_version'],
                [
                    'release_notes' => $validated['release_notes'],
                    'artifact_sha256' => $validated['artifact_sha256'],
                    'artifact_size_bytes' => $validated['artifact_size_bytes'] === null ? null : (int) $validated['artifact_size_bytes'],
                ],
                $validated['reason'],
                $this->requestId($request),
            );
        } catch (ProductVersionConflictException $exception) {
            return response()->json([
                'type' => 'https://tlms.tinlance.com/problems/product-version-conflict',
                'title' => 'Product version conflict',
                'status' => 409,
                'detail' => $exception->getMessage(),
                'code' => 'PRODUCT_VERSION_CONFLICT',
                'request_id' => $this->requestId($request),
            ], 409);
        } catch (InvalidArgumentException $exception) {
            return $this->validationProblem($exception->getMessage(), $request);
        }

        return response()->json([
            'data' => $this->serialize($updated),
            'request_id' => $this->requestId($request),
        ]);
    }

    public function publish(Request $request, string $product, string $productVersion, ProductVersionService $versions): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $record = Product::query()->findOrFail($product);
        $version = ProductVersion::query()->whereKey($productVersion)->where('product_id', $record->getKey())->firstOrFail();
        Gate::forUser($actor)->authorize('publish', $version);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $published = $versions->publish($actor, $version, $validated['reason'], $this->requestId($request));
        } catch (InvalidArgumentException $exception) {
            return $this->validationProblem($exception->getMessage(), $request);
        }

        return response()->json([
            'data' => $this->serialize($published),
            'request_id' => $this->requestId($request),
        ]);
    }

    private function serialize(ProductVersion $version): array
    {
        return [
            'id' => (string) $version->getKey(),
            'product_id' => (string) $version->product_id,
            'version' => $version->version,
            'status' => $version->status,
            'release_notes' => $version->release_notes,
            'artifact_sha256' => $version->artifact_sha256,
            'artifact_size_bytes' => $version->artifact_size_bytes,
            'version_number' => (int) $version->version_number,
            'published_at' => $version->published_at?->toISOString(),
            'created_at' => $version->created_at?->toISOString(),
            'updated_at' => $version->updated_at?->toISOString(),
        ];
    }

    private function validationProblem(string $detail, Request $request): JsonResponse
    {
        return response()->json([
            'type' => 'https://tlms.tinlance.com/problems/product-version-validation',
            'title' => 'Product version validation failed',
            'status' => 422,
            'detail' => $detail,
            'code' => 'PRODUCT_VERSION_INVALID',
            'request_id' => $this->requestId($request),
        ], 422);
    }

    private function requestId(Request $request): string
    {
        $requestId = (string) $request->headers->get('X-Request-ID', '');

        return preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $requestId) === 1
            ? $requestId
            : (string) Str::uuid();
    }
}

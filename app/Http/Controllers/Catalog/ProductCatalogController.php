<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Exceptions\ProductVersionConflictException;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ProductCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ProductCatalogController
{
    public function index(Request $request, ProductCatalogService $catalog): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $vendor = $catalog->vendorForRead($actor);
        abort_unless($vendor !== null, 403);
        Gate::forUser($actor)->authorize('viewAny', [Product::class, $vendor]);

        $perPage = $request->integer('per_page', 25);
        $products = $catalog->listFor($actor, $vendor, $perPage);

        return response()->json([
            'data' => $products->getCollection()->map(fn (Product $product): array => $this->serialize($product))->values(),
            'meta' => [
                'page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
            'request_id' => $this->requestId($request),
        ]);
    }

    public function store(Request $request, ProductCatalogService $catalog): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $vendor = $catalog->vendorForWrite($actor);
        abort_unless($vendor !== null, 403);
        Gate::forUser($actor)->authorize('create', [Product::class, $vendor]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'slug' => ['required', 'string', 'alpha_dash:ascii', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'vendor_account_id' => ['prohibited'],
            'public_id' => ['prohibited'],
            'status' => ['prohibited'],
            'version' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
            'updated_by_user_id' => ['prohibited'],
        ]);

        try {
            $product = $catalog->create(
                $actor,
                $vendor,
                $validated['name'],
                $validated['slug'],
                $validated['description'] ?? null,
                $validated['reason'],
                $this->requestId($request),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'type' => 'https://tlms.tinlance.com/problems/product-catalogue-validation',
                'title' => 'Product catalogue validation failed',
                'status' => 422,
                'detail' => $exception->getMessage(),
                'code' => 'PRODUCT_CATALOGUE_INVALID',
                'request_id' => $this->requestId($request),
            ], 422);
        }

        return response()->json([
            'data' => $this->serialize($product),
            'request_id' => $this->requestId($request),
        ], 201);
    }

    public function show(Request $request, string $product): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $record = Product::query()->findOrFail($product);
        abort_unless(Gate::forUser($actor)->allows('view', $record), 404);

        return response()->json([
            'data' => $this->serialize($record),
            'request_id' => $this->requestId($request),
        ]);
    }

    public function update(Request $request, string $product, ProductCatalogService $catalog): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $record = Product::query()->findOrFail($product);
        abort_unless(Gate::forUser($actor)->allows('update', $record), 404);

        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'slug' => ['required', 'string', 'alpha_dash:ascii', 'min:2', 'max:80'],
            'description' => ['present', 'nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'vendor_account_id' => ['prohibited'],
            'public_id' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
            'updated_by_user_id' => ['prohibited'],
            'status' => ['prohibited'],
        ]);

        try {
            $updated = $catalog->update(
                $actor,
                $record,
                (int) $validated['expected_version'],
                [
                    'name' => $validated['name'],
                    'slug' => $validated['slug'],
                    'description' => $validated['description'] ?? null,
                ],
                $validated['reason'],
                $this->requestId($request),
            );
        } catch (ProductVersionConflictException $exception) {
            return response()->json([
                'type' => 'https://tlms.tinlance.com/problems/product-version-conflict',
                'title' => 'Product version conflict',
                'status' => 409,
                'detail' => 'Reload the product before retrying this update.',
                'code' => 'PRODUCT_VERSION_CONFLICT',
                'request_id' => $this->requestId($request),
            ], 409);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'type' => 'https://tlms.tinlance.com/problems/product-catalogue-validation',
                'title' => 'Product catalogue validation failed',
                'status' => 422,
                'detail' => $exception->getMessage(),
                'code' => 'PRODUCT_CATALOGUE_INVALID',
                'request_id' => $this->requestId($request),
            ], 422);
        }

        return response()->json([
            'data' => $this->serialize($updated),
            'request_id' => $this->requestId($request),
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(Product $product): array
    {
        return [
            'id' => (string) $product->getKey(),
            'public_id' => (string) $product->public_id,
            'name' => (string) $product->name,
            'slug' => (string) $product->slug,
            'description' => $product->description,
            'status' => (string) $product->status,
            'version' => (int) $product->version,
            'created_at' => $product->created_at?->toISOString(),
            'updated_at' => $product->updated_at?->toISOString(),
        ];
    }

    private function requestId(Request $request): string
    {
        $requestId = (string) $request->headers->get('X-Request-ID', '');

        return preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $requestId) === 1
            ? $requestId
            : (string) Str::uuid();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ProductAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ProductAssignmentController
{
    public function store(Request $request, Product $product, ProductAssignmentService $assignments): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('manageAssignments', $product);

        $validated = $request->validate([
            'vendor_membership_id' => ['required', 'ulid'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            [$assignment, $alreadyAssigned] = $assignments->grant(
                $actor,
                $product,
                $validated['vendor_membership_id'],
                $validated['reason'],
                $this->requestId($request),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'type' => 'https://tlms.tinlance.com/problems/product-assignment-invalid',
                'title' => 'Product assignment validation failed',
                'status' => 422,
                'detail' => $exception->getMessage(),
                'code' => 'PRODUCT_ASSIGNMENT_INVALID',
                'request_id' => $this->requestId($request),
            ], 422);
        }

        return response()->json([
            'data' => [
                'id' => (string) $assignment->getKey(),
                'product_id' => (string) $assignment->product_id,
                'vendor_membership_id' => (string) $assignment->vendor_membership_id,
                'assigned_at' => $assignment->assigned_at->toISOString(),
            ],
            'meta' => ['already_assigned' => $alreadyAssigned],
            'request_id' => $this->requestId($request),
        ], $alreadyAssigned ? 200 : 201);
    }

    public function destroy(
        Request $request,
        Product $product,
        string $membership,
        ProductAssignmentService $assignments,
    ): Response {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('manageAssignments', $product);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $assignments->revoke(
                $actor,
                $product,
                $membership,
                $validated['reason'],
                $this->requestId($request),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'type' => 'https://tlms.tinlance.com/problems/product-assignment-invalid',
                'title' => 'Product assignment validation failed',
                'status' => 422,
                'detail' => $exception->getMessage(),
                'code' => 'PRODUCT_ASSIGNMENT_INVALID',
                'request_id' => $this->requestId($request),
            ], 422);
        }

        return response()->noContent();
    }

    private function requestId(Request $request): string
    {
        $requestId = (string) $request->headers->get('X-Request-ID', '');

        return preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $requestId) === 1
            ? $requestId
            : (string) Str::uuid();
    }
}

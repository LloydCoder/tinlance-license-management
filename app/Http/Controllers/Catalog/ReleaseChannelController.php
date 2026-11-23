<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Exceptions\ReleaseChannelConflictException;
use App\Models\Product;
use App\Models\ReleaseChannel;
use App\Models\User;
use App\Services\Catalog\ReleaseChannelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ReleaseChannelController
{
    public function index(Request $request, string $product, ReleaseChannelService $channels): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $record = Product::query()->findOrFail($product);
        abort_unless(Gate::forUser($actor)->allows('view', $record), 404);

        $paginator = $channels->listFor($record, $request->integer('per_page', 25));

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (ReleaseChannel $channel): array => $this->serialize($channel))->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'request_id' => $this->requestId($request),
        ]);
    }

    public function store(Request $request, string $product, ReleaseChannelService $channels): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $record = Product::query()->findOrFail($product);
        Gate::forUser($actor)->authorize('create', [ReleaseChannel::class, $record]);

        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:32', 'regex:/\A[a-z][a-z0-9-]{1,31}\z/D'],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $channel = $channels->create(
                $actor,
                $record,
                $validated['slug'],
                $validated['name'],
                $validated['reason'],
                $this->requestId($request),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->validationProblem($exception->getMessage(), $request);
        }

        return response()->json([
            'data' => $this->serialize($channel),
            'request_id' => $this->requestId($request),
        ], 201);
    }

    public function promote(
        Request $request,
        string $product,
        string $releaseChannel,
        ReleaseChannelService $channels,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $record = Product::query()->findOrFail($product);
        $channel = ReleaseChannel::query()->whereKey($releaseChannel)->where('product_id', $record->getKey())->firstOrFail();
        Gate::forUser($actor)->authorize('promote', $channel);

        $validated = $request->validate([
            'product_version_id' => ['required', 'ulid'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            [$updated, $alreadyPromoted] = $channels->promote(
                $actor,
                $channel,
                $validated['product_version_id'],
                (int) $validated['expected_version'],
                $validated['reason'],
                $this->requestId($request),
            );
        } catch (ReleaseChannelConflictException $exception) {
            return response()->json([
                'type' => 'https://tlms.tinlance.com/problems/release-channel-conflict',
                'title' => 'Release channel conflict',
                'status' => 409,
                'detail' => $exception->getMessage(),
                'code' => 'RELEASE_CHANNEL_CONFLICT',
                'request_id' => $this->requestId($request),
            ], 409);
        } catch (InvalidArgumentException $exception) {
            return $this->validationProblem($exception->getMessage(), $request);
        }

        return response()->json([
            'data' => $this->serialize($updated),
            'meta' => ['already_promoted' => $alreadyPromoted],
            'request_id' => $this->requestId($request),
        ]);
    }

    /** @return array{id: string, product_id: string, slug: string, name: string, status: string, current_product_version_id: string|null, version_number: int, created_at: string|null, updated_at: string|null} */
    private function serialize(ReleaseChannel $channel): array
    {
        return [
            'id' => (string) $channel->getKey(),
            'product_id' => (string) $channel->product_id,
            'slug' => $channel->slug,
            'name' => $channel->name,
            'status' => $channel->status,
            'current_product_version_id' => $channel->current_product_version_id === null ? null : (string) $channel->current_product_version_id,
            'version_number' => (int) $channel->version_number,
            'created_at' => $channel->created_at?->toISOString(),
            'updated_at' => $channel->updated_at?->toISOString(),
        ];
    }

    private function validationProblem(string $detail, Request $request): JsonResponse
    {
        return response()->json([
            'type' => 'https://tlms.tinlance.com/problems/release-channel-validation',
            'title' => 'Release channel validation failed',
            'status' => 422,
            'detail' => $detail,
            'code' => 'RELEASE_CHANNEL_INVALID',
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

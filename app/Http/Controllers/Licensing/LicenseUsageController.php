<?php

declare(strict_types=1);

namespace App\Http\Controllers\Licensing;

use App\Exceptions\LicenseUsageConflictException;
use App\Models\License;
use App\Models\User;
use App\Services\Licensing\LicenseUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class LicenseUsageController
{
    public function store(Request $request, string $license, LicenseUsageService $usage): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $input = $request->validate([
            'metric' => ['required', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9_]{0,63}\z/D'],
            'period' => ['required', 'string', 'in:monthly,annual,lifetime'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        /** @var License $licenseRecord */
        $licenseRecord = License::query()->findOrFail($license);
        $requestId = $this->requestId($request);

        try {
            $result = $usage->consume(
                $actor,
                $licenseRecord,
                (string) $input['metric'],
                (string) $input['period'],
                (int) $input['quantity'],
                (string) $input['reason'],
                $requestId,
            );
        } catch (LicenseUsageConflictException $exception) {
            return $this->problem($exception->getMessage(), $requestId, 409);
        } catch (InvalidArgumentException $exception) {
            return $this->problem($exception->getMessage(), $requestId, 422);
        }

        $counter = $result['counter'];

        return response()->json([
            'data' => [
                'id' => (string) $counter->getKey(),
                'metric' => (string) $counter->metric,
                'period' => (string) $counter->period,
                'period_start' => $result['period_start']->toISOString(),
                'period_end' => $result['period_end']?->toISOString(),
                'quantity' => (int) $counter->quantity,
                'limit' => $result['limit'],
                'version' => (int) $counter->version,
            ],
            'replayed' => $result['replayed'],
            'request_id' => $requestId,
        ], $result['replayed'] ? 200 : 201);
    }

    private function requestId(Request $request): string
    {
        $candidate = $request->header('X-Request-ID');

        return is_string($candidate)
            && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $candidate) === 1
                ? $candidate
                : (string) Str::uuid();
    }

    private function problem(string $detail, string $requestId, int $status): JsonResponse
    {
        return response()->json([
            'type' => 'about:blank',
            'title' => $status === 409 ? 'Conflict' : 'Unprocessable Content',
            'status' => $status,
            'detail' => $detail,
            'request_id' => $requestId,
        ], $status)->header('Content-Type', 'application/problem+json');
    }
}

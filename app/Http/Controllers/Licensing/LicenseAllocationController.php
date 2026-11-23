<?php

declare(strict_types=1);

namespace App\Http\Controllers\Licensing;

use App\Exceptions\LicenseAllocationConflictException;
use App\Models\License;
use App\Models\LicenseAllocation;
use App\Models\User;
use App\Services\Licensing\LicenseAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class LicenseAllocationController
{
    public function store(Request $request, string $license, LicenseAllocationService $allocations): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $input = $request->validate([
            'allocation_type' => ['required', 'string', 'in:site,seat,user,domain'],
            'subject_identifier' => ['required', 'string', 'min:1', 'max:253'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        /** @var License $licenseRecord */
        $licenseRecord = License::query()->findOrFail($license);
        $requestId = $this->requestId($request);

        try {
            $result = $allocations->allocate(
                $actor,
                $licenseRecord,
                (string) $input['allocation_type'],
                (string) $input['subject_identifier'],
                (string) $input['reason'],
                $requestId,
            );
        } catch (LicenseAllocationConflictException $exception) {
            return $this->problem($exception->getMessage(), $requestId, 409);
        } catch (InvalidArgumentException $exception) {
            return $this->problem($exception->getMessage(), $requestId, 422);
        }

        /** @var LicenseAllocation $allocation */
        $allocation = $result['allocation'];

        return response()->json([
            'data' => [
                'id' => (string) $allocation->getKey(),
                'allocation_type' => (string) $allocation->allocation_type,
                'status' => (string) $allocation->status,
                'version' => (int) $allocation->version,
            ],
            'replayed' => $result['replayed'],
            'request_id' => $requestId,
        ], $result['replayed'] ? 200 : 201);
    }

    public function destroy(
        Request $request,
        string $license,
        string $allocation,
        LicenseAllocationService $allocations,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $input = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        /** @var License $licenseRecord */
        $licenseRecord = License::query()->findOrFail($license);
        /** @var LicenseAllocation $allocationRecord */
        $allocationRecord = LicenseAllocation::query()->findOrFail($allocation);
        $requestId = $this->requestId($request);

        try {
            $result = $allocations->release(
                $actor,
                $licenseRecord,
                $allocationRecord,
                (string) $input['reason'],
                $requestId,
            );
        } catch (LicenseAllocationConflictException $exception) {
            return $this->problem($exception->getMessage(), $requestId, 409);
        } catch (InvalidArgumentException $exception) {
            return $this->problem($exception->getMessage(), $requestId, 422);
        }

        /** @var LicenseAllocation $released */
        $released = $result['allocation'];

        return response()->json([
            'data' => [
                'id' => (string) $released->getKey(),
                'allocation_type' => (string) $released->allocation_type,
                'status' => (string) $released->status,
                'version' => (int) $released->version,
            ],
            'replayed' => $result['replayed'],
            'request_id' => $requestId,
        ]);
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

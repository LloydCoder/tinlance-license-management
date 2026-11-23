<?php

declare(strict_types=1);

namespace App\Http\Controllers\Licensing;

use App\Exceptions\LicenseLifecycleConflictException;
use App\Models\License;
use App\Models\User;
use App\Services\Licensing\LicenseLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class LicenseLifecycleController
{
    public function transition(Request $request, string $license, LicenseLifecycleService $lifecycle): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $input = $request->validate([
            'to_status' => ['required', 'string', 'in:active,suspended,revoked'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        /** @var License $licenseRecord */
        $licenseRecord = License::query()->findOrFail($license);
        $requestId = $this->requestId($request);

        try {
            $result = $lifecycle->transition(
                $actor,
                $licenseRecord,
                (string) $input['to_status'],
                (string) $input['reason'],
                $requestId,
            );
        } catch (LicenseLifecycleConflictException $exception) {
            return $this->problem($exception->getMessage(), $requestId, 409);
        } catch (InvalidArgumentException $exception) {
            return $this->problem($exception->getMessage(), $requestId, 422);
        }

        /** @var License $updatedLicense */
        $updatedLicense = $result['license'];

        return response()->json([
            'data' => [
                'id' => (string) $updatedLicense->getKey(),
                'public_reference' => (string) $updatedLicense->public_reference,
                'status' => (string) $updatedLicense->status,
                'version' => (int) $updatedLicense->version,
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

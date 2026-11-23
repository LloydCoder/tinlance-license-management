<?php

declare(strict_types=1);

namespace App\Http\Controllers\Licensing;

use App\Exceptions\LicenseSuccessorConflictException;
use App\Models\License;
use App\Models\User;
use App\Services\Licensing\LicenseSuccessorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class LicenseSuccessorController
{
    public function store(Request $request, string $license, LicenseSuccessorService $successors): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $input = $request->validate([
            'relation_type' => ['required', 'string', 'in:transfer,replacement'],
            'destination_customer_organization_id' => [
                'nullable',
                'required_if:relation_type,transfer',
                'prohibited_if:relation_type,replacement',
                'string',
                'size:26',
            ],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || preg_match('/\A[A-Za-z0-9._~-]{32,128}\z/D', $idempotencyKey) !== 1) {
            return $this->problem('A valid Idempotency-Key header is required.', $this->requestId($request), 422);
        }

        /** @var License $licenseRecord */
        $licenseRecord = License::query()->findOrFail($license);
        $requestId = $this->requestId($request);

        try {
            $result = $successors->create(
                $actor,
                $licenseRecord,
                (string) $input['relation_type'],
                isset($input['destination_customer_organization_id'])
                    ? (string) $input['destination_customer_organization_id']
                    : null,
                (string) $input['reason'],
                $idempotencyKey,
                $requestId,
            );
        } catch (LicenseSuccessorConflictException $exception) {
            return $this->problem($exception->getMessage(), $requestId, 409);
        } catch (InvalidArgumentException $exception) {
            return $this->problem($exception->getMessage(), $requestId, 422);
        }

        $relation = $result['relation'];
        $successor = $result['successor'];

        return response()->json([
            'data' => [
                'id' => (string) $relation->getKey(),
                'relation_type' => (string) $relation->relation_type,
                'source_license_id' => (string) $relation->source_license_id,
                'successor_license_id' => (string) $successor->getKey(),
                'destination_customer_organization_id' => (string) $relation->destination_customer_organization_id,
                'source_status' => 'revoked',
                'successor_status' => (string) $successor->status,
                'issuance_request_id' => (string) $result['issuance_request']->getKey(),
                'secret_available' => $result['secret_available'],
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

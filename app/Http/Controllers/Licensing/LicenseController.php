<?php

declare(strict_types=1);

namespace App\Http\Controllers\Licensing;

use App\Exceptions\LicenseIssuanceConflictException;
use App\Exceptions\LicenseSecretUnavailableException;
use App\Models\CustomerOrganization;
use App\Models\LicenseIssuanceRequest;
use App\Models\PlanVersion;
use App\Models\Product;
use App\Models\User;
use App\Services\Licensing\LicenseIssuanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class LicenseController
{
    public function store(Request $request, string $product, LicenseIssuanceService $issuance): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $input = $request->validate([
            'customer_organization_id' => ['required', 'string', 'size:26'],
            'plan_version_id' => ['required', 'string', 'size:26'],
            'starts_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $productRecord = Product::query()->findOrFail($product);
        $customer = CustomerOrganization::query()
            ->whereKey($input['customer_organization_id'])
            ->where('vendor_account_id', $productRecord->vendor_account_id)
            ->firstOrFail();
        $planVersion = PlanVersion::query()
            ->whereKey($input['plan_version_id'])
            ->where('vendor_account_id', $productRecord->vendor_account_id)
            ->where('product_id', $productRecord->getKey())
            ->firstOrFail();

        $requestId = $this->requestId($request);
        try {
            $result = $issuance->issue(
                $actor,
                $productRecord,
                $customer,
                $planVersion,
                isset($input['starts_at']) ? (string) $input['starts_at'] : null,
                (string) $input['reason'],
                (string) $request->header('Idempotency-Key', ''),
                $requestId,
            );
        } catch (LicenseIssuanceConflictException $exception) {
            return $this->problem($exception->getMessage(), $requestId, 409);
        } catch (InvalidArgumentException $exception) {
            return $this->problem($exception->getMessage(), $requestId, 422);
        }

        /** @var \App\Models\License $license */
        $license = $result['license'];
        /** @var LicenseIssuanceRequest $issuanceRequest */
        $issuanceRequest = $result['issuance_request'];

        return response()->json([
            'data' => [
                'id' => (string) $license->getKey(),
                'public_reference' => (string) $license->public_reference,
                'status' => (string) $license->status,
                'customer_organization_id' => (string) $license->customer_organization_id,
                'product_id' => (string) $license->product_id,
                'plan_version_id' => (string) $license->plan_version_id,
                'starts_at' => $license->starts_at?->toISOString(),
                'expires_at' => $license->expires_at?->toISOString(),
                'grace_until' => $license->grace_until?->toISOString(),
                'version' => (int) $license->version,
            ],
            'issuance' => [
                'request_id' => (string) $issuanceRequest->getKey(),
                'secret_available' => $result['secret_available'],
                'secret_expires_at' => $issuanceRequest->expires_at?->toISOString(),
                'secret_delivery' => 'one_time',
            ],
            'replayed' => $result['replayed'],
            'request_id' => $requestId,
        ], $result['replayed'] ? 200 : 201);
    }

    public function revealSecret(
        Request $request,
        string $issuanceRequest,
        LicenseIssuanceService $issuance,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $record = LicenseIssuanceRequest::query()->findOrFail($issuanceRequest);

        $requestId = $this->requestId($request);
        try {
            $plaintext = $issuance->revealSecret($actor, $record, $requestId);
        } catch (LicenseSecretUnavailableException $exception) {
            return $this->problem($exception->getMessage(), $requestId, 410);
        } catch (LicenseIssuanceConflictException $exception) {
            return $this->problem($exception->getMessage(), $request, 409);
        }

        return response()->json([
            'data' => [
                'license_id' => (string) $record->license_id,
                'license_key' => $plaintext,
                'delivery' => 'one_time',
            ],
            'request_id' => $requestId,
        ])->withHeaders([
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
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
            'title' => match ($status) {
                409 => 'Conflict',
                410 => 'Gone',
                default => 'Unprocessable Content',
            },
            'status' => $status,
            'detail' => $detail,
            'request_id' => $requestId,
        ], $status)->header('Content-Type', 'application/problem+json');
    }
}

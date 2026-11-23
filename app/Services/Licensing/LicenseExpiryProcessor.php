<?php

declare(strict_types=1);

namespace App\Services\Licensing;

use App\Models\License;
use App\Models\LicenseAllocationEvent;
use App\Models\LicenseEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class LicenseExpiryProcessor
{
    private const SYSTEM_REASON = 'Licence validity and any configured grace period have elapsed.';

    public function expireDue(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('The expiry batch limit must be between 1 and 1000.');
        }

        $cutoff = now();
        $candidateIds = License::query()
            ->whereIn('status', ['issued', 'active', 'suspended'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $cutoff)
            ->where(static function ($query) use ($cutoff): void {
                $query->whereNull('grace_until')
                    ->orWhere('grace_until', '<=', $cutoff);
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $expired = 0;

        foreach ($candidateIds as $licenseId) {
            $didExpire = DB::transaction(function () use ($licenseId, $cutoff): bool {
                /** @var License|null $license */
                $license = License::query()->whereKey($licenseId)->lockForUpdate()->first();

                if (
                    ! $license instanceof License
                    || ! in_array((string) $license->status, ['issued', 'active', 'suspended'], true)
                    || $license->expires_at === null
                    || $license->expires_at->greaterThan($cutoff)
                    || ($license->grace_until !== null && $license->grace_until->greaterThan($cutoff))
                ) {
                    return false;
                }

                $version = (int) $license->version;
                $requestId = sprintf('sys:license-expiry:%s:%d', (string) $license->getKey(), $version);

                if (
                    LicenseEvent::query()
                        ->where('vendor_account_id', $license->vendor_account_id)
                        ->where('request_id', $requestId)
                        ->exists()
                    || LicenseAllocationEvent::query()
                        ->where('vendor_account_id', $license->vendor_account_id)
                        ->where('request_id', $requestId)
                        ->exists()
                ) {
                    throw new RuntimeException('The reserved system expiry request identifier is already in use.');
                }

                $fromStatus = (string) $license->status;
                $nextVersion = $version + 1;
                $payload = [
                    'aggregate_version' => $nextVersion,
                    'expires_at' => $license->expires_at->toISOString(),
                    'grace_until' => $license->grace_until?->toISOString(),
                    'license_id' => (string) $license->getKey(),
                    'status' => 'expired',
                ];
                ksort($payload, SORT_STRING);
                $payloadHash = hash('sha256', json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ));

                $license->fill([
                    'status' => 'expired',
                    'version' => $nextVersion,
                ])->save();

                LicenseEvent::query()->create([
                    'license_id' => $license->getKey(),
                    'vendor_account_id' => $license->vendor_account_id,
                    'product_id' => $license->product_id,
                    'actor_type' => 'system',
                    'actor_user_id' => null,
                    'event_type' => 'expired',
                    'from_status' => $fromStatus,
                    'to_status' => 'expired',
                    'reason' => self::SYSTEM_REASON,
                    'request_id' => $requestId,
                    'payload_sha256' => $payloadHash,
                    'event_payload' => $payload,
                    'created_at' => $cutoff,
                ]);

                return true;
            }, 3);

            if ($didExpire) {
                $expired++;
            }
        }

        return $expired;
    }
}

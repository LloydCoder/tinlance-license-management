<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\MfaStepUpEvent;
use App\Models\User;
use Illuminate\Http\Request;

final class MfaStepUpAuditService
{
    public function record(
        User $user,
        string $method,
        string $outcome,
        Request $request,
    ): MfaStepUpEvent {
        $candidateRequestId = $request->headers->get('X-Request-ID');
        $requestId = is_string($candidateRequestId)
            && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $candidateRequestId) === 1
                ? $candidateRequestId
                : bin2hex(random_bytes(16));

        $userAgent = $request->userAgent();

        return MfaStepUpEvent::query()->create([
            'user_id' => $user->getKey(),
            'method' => $method,
            'outcome' => $outcome,
            'ip_address' => $request->ip(),
            'user_agent' => is_string($userAgent) ? mb_substr($userAgent, 0, 512) : null,
            'request_id' => $requestId,
            'created_at' => now(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HealthCheckTest extends TestCase
{
    public function test_framework_health_endpoint_returns_success(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_root_endpoint_identifies_tlms_as_development(): void
    {
        $this->get('/')->assertOk()->assertExactJson(['service' => 'TLMS', 'status' => 'development']);
    }
}

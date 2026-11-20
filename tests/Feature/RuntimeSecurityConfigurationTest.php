<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class RuntimeSecurityConfigurationTest extends TestCase
{
    public function test_session_cookie_is_secure_encrypted_http_only_and_same_site(): void
    {
        $this->assertTrue(config('session.secure'));
        $this->assertTrue(config('session.encrypt'));
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_application_timezone_is_configurable(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }
}

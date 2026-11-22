<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BrandIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_shell_uses_the_approved_tlms_brand_assets_and_tokens(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('/brand/tlms-lockup-horizontal-dark.svg')
            ->assertSee('/brand/tlms-brand.css')
            ->assertSee('/favicon.svg')
            ->assertDontSee('#0b1220');
    }

    public function test_workspace_shell_uses_the_approved_tlms_brand_assets_and_tokens(): void
    {
        $workspace = file_get_contents(resource_path('views/workspace/dashboard.blade.php'));

        $this->assertIsString($workspace);
        $this->assertStringContainsString('brand/tlms-lockup-horizontal-dark.svg', $workspace);
        $this->assertStringContainsString('brand/tlms-brand.css', $workspace);
        $this->assertStringContainsString('/favicon.svg', $workspace);

        $this->assertFileExists(public_path('brand/tlms-lockup-horizontal-dark.svg'));
        $this->assertFileExists(public_path('brand/tlms-lockup-horizontal-light.svg'));
        $this->assertFileExists(public_path('brand/tlms-brand.css'));
        $this->assertFileExists(public_path('favicon.svg'));
    }

    public function test_brand_tokens_match_the_supplied_usage_sheet_palette(): void
    {
        $tokens = file_get_contents(public_path('brand/tlms-brand.css'));

        $this->assertIsString($tokens);
        $this->assertStringContainsString('--tlms-ink: #0A0C0B', $tokens);
        $this->assertStringContainsString('--tlms-lime: #C6FF4A', $tokens);
        $this->assertStringContainsString('--tlms-paper: #F4F4F0', $tokens);
        $this->assertStringContainsString('--tlms-edge: #3A403C', $tokens);
        $this->assertStringContainsString('--tlms-graphite: #4A504B', $tokens);
        $this->assertStringContainsString('--tlms-fog: #8A8F87', $tokens);
    }
}

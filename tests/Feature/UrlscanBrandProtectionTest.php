<?php

namespace Tests\Feature;

use App\Models\BrandScan;
use App\Models\BrandScanResult;
use App\Models\User;
use App\Services\UrlscanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UrlscanBrandProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $perm = Permission::firstOrCreate(['name' => 'cti.brand-protection.view', 'guard_name' => 'sanctum']);
        $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'sanctum']);
        $role->givePermissionTo($perm);

        $this->admin = User::factory()->create([
            'email' => 'sec-admin@example.com',
            'is_active' => true,
        ]);
        $this->admin->assignRole($role);

        config(['osint.urlscan.api_key' => 'test-urlscan-key']);
        config(['osint.urlscan.base_url' => 'https://urlscan.io/api/v1']);
    }

    private function createScanResult(string $domain, array $overrides = []): BrandScanResult
    {
        $scan = BrandScan::create([
            'domain' => 'herointiputra.com',
            'status' => 'done',
            'total_found' => 1,
        ]);

        return BrandScanResult::create(array_merge([
            'brand_scan_id' => $scan->id,
            'domain' => $domain,
            'fuzzer' => 'omission',
            'dns_a' => ['93.184.216.34'],
            'dns_mx' => [],
            'dns_ns' => [],
        ], $overrides));
    }

    private function bindDnsResult(bool $resolves): void
    {
        $this->app->bind(UrlscanService::class, function () use ($resolves) {
            return new class($resolves) extends UrlscanService
            {
                public function __construct(private bool $resolves)
                {
                    parent::__construct();
                }

                public function domainResolvesDns(string $domain): bool
                {
                    return $this->resolves;
                }
            };
        });
    }

    public function test_unresolvable_domain_fails_dns_preflight_without_calling_urlscan(): void
    {
        $this->bindDnsResult(false);
        Http::fake();
        Sanctum::actingAs($this->admin);

        $result = $this->createScanResult('unregistered-lookalike.invalid', [
            'dns_a' => [],
        ]);

        $response = $this->postJson("/api/v1/cti/brand-scan-results/{$result->id}/urlscan");

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', UrlscanService::UNRESOLVABLE_MESSAGE)
            ->assertJsonPath('data.urlscan_status', 'unresolvable')
            ->assertJsonPath('data.urlscan_error', UrlscanService::UNRESOLVABLE_MESSAGE)
            ->assertJsonPath('data.urlscan_cooldown_active', true);

        Http::assertNothingSent();

        $result->refresh();
        $this->assertSame('unresolvable', $result->urlscan_status);
        $this->assertSame(UrlscanService::UNRESOLVABLE_MESSAGE, $result->urlscan_error);
        $this->assertNotNull($result->urlscan_checked_at);
    }

    public function test_urlscan_dns_error_response_returns_422_and_persists_unresolvable(): void
    {
        $this->bindDnsResult(true);
        Http::fake([
            'https://urlscan.io/api/v1/scan/' => Http::response([
                'message' => 'DNS Error - Could not resolve domain',
                'description' => 'The domain could not be resolved by urlscan.',
                'status' => 400,
            ], 400),
        ]);
        Sanctum::actingAs($this->admin);

        $result = $this->createScanResult('ghost-domain.test');

        $response = $this->postJson("/api/v1/cti/brand-scan-results/{$result->id}/urlscan");

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', UrlscanService::UNRESOLVABLE_MESSAGE)
            ->assertJsonPath('data.urlscan_status', 'unresolvable');

        $result->refresh();
        $this->assertSame('unresolvable', $result->urlscan_status);
        $this->assertNotNull($result->urlscan_checked_at);
    }

    public function test_urlscan_rejected_domain_returns_422_and_persists_rejected(): void
    {
        $this->bindDnsResult(true);
        Http::fake([
            'https://urlscan.io/api/v1/scan/' => Http::response([
                'message' => 'Scan prevented',
                'description' => 'The submitted domain is blacklisted from scanning.',
                'status' => 400,
            ], 400),
        ]);
        Sanctum::actingAs($this->admin);

        $result = $this->createScanResult('blocked-domain.test');

        $response = $this->postJson("/api/v1/cti/brand-scan-results/{$result->id}/urlscan");

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The submitted domain is blacklisted from scanning.')
            ->assertJsonPath('data.urlscan_status', 'rejected');

        $result->refresh();
        $this->assertSame('rejected', $result->urlscan_status);
        $this->assertSame('The submitted domain is blacklisted from scanning.', $result->urlscan_error);
    }

    public function test_cooldown_prevents_rescanning_unresolvable_domain_within_24_hours(): void
    {
        $this->bindDnsResult(true);
        Http::fake();
        Sanctum::actingAs($this->admin);

        $result = $this->createScanResult('cooldown-domain.test', [
            'urlscan_status' => 'unresolvable',
            'urlscan_error' => UrlscanService::UNRESOLVABLE_MESSAGE,
            'urlscan_checked_at' => now()->subHours(2),
        ]);

        $response = $this->postJson("/api/v1/cti/brand-scan-results/{$result->id}/urlscan");

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.urlscan_status', 'unresolvable')
            ->assertJsonPath('data.urlscan_cooldown_active', true);

        Http::assertNothingSent();
    }

    public function test_urlscan_rate_limit_returns_429_with_retry_after(): void
    {
        $this->bindDnsResult(true);
        Http::fake([
            'https://urlscan.io/api/v1/scan/' => Http::response([
                'message' => 'Too Many Requests',
            ], 429, ['Retry-After' => '45']),
        ]);
        Sanctum::actingAs($this->admin);

        $result = $this->createScanResult('ratelimited-domain.test');

        $response = $this->postJson("/api/v1/cti/brand-scan-results/{$result->id}/urlscan");

        $response->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Scan limit reached. Please try again in 45 seconds.')
            ->assertJsonPath('data.retry_after', 45);
    }

    public function test_urlscan_invalid_api_key_returns_503_with_config_message(): void
    {
        config(['app.debug' => false]);
        $this->bindDnsResult(true);
        Http::fake([
            'https://urlscan.io/api/v1/scan/' => Http::response([
                'message' => 'Unauthorized: invalid API key',
            ], 401),
        ]);
        Sanctum::actingAs($this->admin);

        $result = $this->createScanResult('auth-fail-domain.test');

        $response = $this->postJson("/api/v1/cti/brand-scan-results/{$result->id}/urlscan");

        $response->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', UrlscanService::MISCONFIGURED_MESSAGE);
    }

    public function test_urlscan_missing_api_key_returns_503_without_http_request(): void
    {
        config(['app.debug' => false, 'osint.urlscan.api_key' => '']);
        $this->bindDnsResult(true);
        Http::fake();
        Sanctum::actingAs($this->admin);

        $result = $this->createScanResult('no-key-domain.test');

        $response = $this->postJson("/api/v1/cti/brand-scan-results/{$result->id}/urlscan");

        $response->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', UrlscanService::MISCONFIGURED_MESSAGE);

        Http::assertNothingSent();
    }

    public function test_urlscan_connection_timeout_returns_503_with_generic_message(): void
    {
        config(['app.debug' => false]);
        $this->bindDnsResult(true);
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });
        Sanctum::actingAs($this->admin);

        $result = $this->createScanResult('timeout-domain.test');

        $response = $this->postJson("/api/v1/cti/brand-scan-results/{$result->id}/urlscan");

        $response->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', UrlscanService::UNAVAILABLE_MESSAGE);
    }
}

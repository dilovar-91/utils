<?php

namespace Tests\Feature;

use App\Jobs\CheckSiteHealthJob;
use App\Models\Domain;
use App\Services\PublicDnsLookup;
use App\Services\SiteHealthService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SiteHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(PublicDnsLookup::class, function ($mock) {
            $mock->shouldReceive('domainExists')->andReturn(true)->byDefault();
        });
    }

    public function test_service_marks_http_200_as_up(): void
    {
        Http::fake([
            'https://ok.example' => Http::response('OK', 200),
        ]);

        $result = app(SiteHealthService::class)->check('https://ok.example');

        $this->assertTrue($result['up']);
        $this->assertSame(200, $result['status_code']);
        $this->assertNull($result['error']);
    }

    public function test_service_treats_403_and_404_as_up(): void
    {
        Http::fake([
            'https://forbidden.example' => Http::response('Forbidden', 403),
            'https://missing.example' => Http::response('Not Found', 404),
        ]);

        $forbidden = app(SiteHealthService::class)->check('https://forbidden.example');
        $missing = app(SiteHealthService::class)->check('https://missing.example');

        $this->assertTrue($forbidden['up']);
        $this->assertSame(403, $forbidden['status_code']);
        $this->assertNull($forbidden['error']);

        $this->assertTrue($missing['up']);
        $this->assertSame(404, $missing['status_code']);
        $this->assertNull($missing['error']);
    }

    public function test_service_marks_http_500_as_down(): void
    {
        Http::fake([
            'https://fail.example' => Http::response('Error', 500),
        ]);

        $result = app(SiteHealthService::class)->check('https://fail.example');

        $this->assertFalse($result['up']);
        $this->assertFalse($result['retryable']);
        $this->assertSame(500, $result['status_code']);
        $this->assertSame('HTTP 500', $result['error']);
    }

    public function test_service_marks_timeout_as_retryable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Resolving timed out after 5001 milliseconds (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://profildoors-design.ru');
        });

        $result = app(SiteHealthService::class)->check('https://timeout.example');

        $this->assertFalse($result['up']);
        $this->assertTrue($result['retryable']);
        $this->assertSame('cURL error 28: Resolving timed out after 5001 milliseconds', $result['error']);
    }

    public function test_service_treats_self_signed_certificate_as_up(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 60: SSL certificate problem: self-signed certificate');
        });

        $result = app(SiteHealthService::class)->check('https://selfsigned.example');

        $this->assertTrue($result['up']);
        $this->assertFalse($result['retryable']);
        $this->assertNull($result['error']);
    }

    public function test_nxdomain_is_down_without_http(): void
    {
        $this->mock(PublicDnsLookup::class, function ($mock) {
            $mock->shouldReceive('domainExists')->with('bekmobil.ru')->andReturn(false);
        });

        Http::fake();

        $result = app(SiteHealthService::class)->check('https://bekmobil.ru');

        $this->assertFalse($result['up']);
        $this->assertFalse($result['retryable']);
        $this->assertSame('DNS NXDOMAIN', $result['error']);
        Http::assertNothingSent();
    }

    public function test_curl_could_not_resolve_host_is_nxdomain(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 6: Could not resolve host: gone.example');
        });

        $result = app(SiteHealthService::class)->check('https://gone.example');

        $this->assertFalse($result['up']);
        $this->assertFalse($result['retryable']);
        $this->assertSame('DNS NXDOMAIN', $result['error']);
    }

    public function test_job_marks_nxdomain_down_immediately(): void
    {
        $this->mock(PublicDnsLookup::class, function ($mock) {
            $mock->shouldReceive('domainExists')->andReturn(false);
        });

        Http::fake();

        $domain = Domain::query()->create([
            'domain' => 'bekmobil.ru',
            'health_check_enabled' => true,
            'health_status' => 'up',
            'health_status_code' => 200,
        ]);

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'DNS NXDOMAIN') && ! str_contains($message, '200'))
            ->andReturn(true);
        $this->app->instance(TelegramService::class, $telegram);

        CheckSiteHealthJob::dispatchSync($domain->id);

        $domain->refresh();

        $this->assertSame('down', $domain->health_status);
        $this->assertNull($domain->health_status_code);
        $this->assertSame('DNS NXDOMAIN', $domain->last_health_error);
        $this->assertSame(0, $domain->health_curl_fail_count);
    }

    public function test_job_updates_domain_and_notifies_when_site_goes_down(): void
    {
        Http::fake([
            'https://down.example' => Http::response('Error', 503),
        ]);

        $domain = Domain::query()->create([
            'domain' => 'down.example',
            'health_check_enabled' => true,
            'health_status' => 'up',
        ]);

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->andReturn(true);

        $this->app->instance(TelegramService::class, $telegram);

        CheckSiteHealthJob::dispatchSync($domain->id);

        $domain->refresh();

        $this->assertSame('down', $domain->health_status);
        $this->assertSame(503, $domain->health_status_code);
        $this->assertSame('down', $domain->last_health_notified_status);
        $this->assertNotNull($domain->last_health_checked_at);
    }

    public function test_job_retries_curl_errors_three_times_before_down(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Resolving timed out after 5001 milliseconds');
        });

        $domain = Domain::query()->create([
            'domain' => 'flaky.example',
            'health_check_enabled' => true,
            'health_status' => 'up',
        ]);

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->andReturn(true);
        $this->app->instance(TelegramService::class, $telegram);

        CheckSiteHealthJob::dispatchSync($domain->id);
        $domain->refresh();
        $this->assertSame('up', $domain->health_status);
        $this->assertSame(1, $domain->health_curl_fail_count);
        $this->assertNull($domain->last_health_notified_status);

        CheckSiteHealthJob::dispatchSync($domain->id);
        $domain->refresh();
        $this->assertSame('up', $domain->health_status);
        $this->assertSame(2, $domain->health_curl_fail_count);

        CheckSiteHealthJob::dispatchSync($domain->id);
        $domain->refresh();
        $this->assertSame('down', $domain->health_status);
        $this->assertSame(3, $domain->health_curl_fail_count);
        $this->assertSame('down', $domain->last_health_notified_status);
        $this->assertStringContainsString('попытка 3/3', (string) $domain->last_health_error);
    }

    public function test_successful_check_resets_curl_fail_count(): void
    {
        Http::fake([
            'https://ok.example' => Http::response('OK', 200),
        ]);

        $domain = Domain::query()->create([
            'domain' => 'ok.example',
            'health_check_enabled' => true,
            'health_status' => 'up',
            'health_curl_fail_count' => 2,
        ]);

        CheckSiteHealthJob::dispatchSync($domain->id);

        $this->assertSame(0, $domain->refresh()->health_curl_fail_count);
        $this->assertSame('up', $domain->health_status);
    }

    public function test_command_checks_only_enabled_sites(): void
    {
        Http::fake([
            'https://watched.example' => Http::response('OK', 200),
            'https://ignored.example' => Http::response('Error', 500),
        ]);

        $watched = Domain::query()->create([
            'domain' => 'watched.example',
            'health_check_enabled' => true,
        ]);

        $ignored = Domain::query()->create([
            'domain' => 'ignored.example',
            'health_check_enabled' => false,
        ]);

        $this->artisan('app:check-sites-health')
            ->assertSuccessful();

        $this->assertSame('up', $watched->refresh()->health_status);
        $this->assertSame('unknown', $ignored->refresh()->health_status);
        $this->assertNull($ignored->last_health_checked_at);
    }

    public function test_telegram_timeout_does_not_fail_health_check(): void
    {
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => '123',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.telegram.org')) {
                throw new ConnectionException('cURL error 28: SSL connection timeout');
            }

            return Http::response('Error', 503);
        });

        $domain = Domain::query()->create([
            'domain' => 'down.example',
            'health_check_enabled' => true,
            'health_status' => 'up',
        ]);

        CheckSiteHealthJob::dispatchSync($domain->id);

        $domain->refresh();

        $this->assertSame('down', $domain->health_status);
        $this->assertNull($domain->last_health_notified_status);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\CheckSiteHealthJob;
use App\Models\Domain;
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

    public function test_service_marks_http_500_as_down(): void
    {
        Http::fake([
            'https://fail.example' => Http::response('Error', 500),
        ]);

        $result = app(SiteHealthService::class)->check('https://fail.example');

        $this->assertFalse($result['up']);
        $this->assertSame(500, $result['status_code']);
        $this->assertSame('HTTP 500', $result['error']);
    }

    public function test_service_marks_connection_error_as_down(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $result = app(SiteHealthService::class)->check('https://timeout.example');

        $this->assertFalse($result['up']);
        $this->assertNull($result['status_code']);
        $this->assertSame('Connection timed out', $result['error']);
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
}

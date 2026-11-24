<?php

namespace Tests\Feature;

use App\Jobs\CheckDomainWhoisJob;
use App\Jobs\CheckSiteHealthJob;
use App\Mail\AdminAlertMail;
use App\Models\Domain;
use App\Services\TelegramService;
use App\Services\WhoisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class AdminAlertMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_alert_is_copied_to_admin_email(): void
    {
        config(['mail.admin' => 'admin@example.com, second@example.com']);
        Mail::fake();

        Http::fake([
            'https://down.example' => Http::response('Error', 503),
        ]);

        $domain = Domain::query()->create([
            'domain' => 'down.example',
            'health_check_enabled' => true,
            'health_status' => 'up',
        ]);

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->andReturn(true);
        $this->app->instance(TelegramService::class, $telegram);

        CheckSiteHealthJob::dispatchSync($domain->id);

        Mail::assertSent(AdminAlertMail::class, function (AdminAlertMail $mail) {
            return $mail->hasTo('admin@example.com')
                && $mail->hasTo('second@example.com')
                && str_contains($mail->alertSubject, 'Сайт недоступен')
                && str_contains($mail->htmlBody, 'down.example');
        });
    }

    public function test_whois_alert_is_copied_to_admin_email(): void
    {
        config(['mail.admin' => 'admin@example.com']);
        Mail::fake();

        $domain = Domain::query()->create([
            'domain' => 'expire.example',
        ]);

        $this->mock(WhoisService::class, function ($mock) {
            $mock->shouldReceive('lookup')->once()->andReturn([
                'success' => true,
                'expires_at' => now()->addDays(5),
                'registrar' => 'Example Registrar',
                'raw_whois' => 'raw',
            ]);
        });

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->andReturn(true);
        $this->app->instance(TelegramService::class, $telegram);

        CheckDomainWhoisJob::dispatchSync($domain->id);

        Mail::assertSent(AdminAlertMail::class, function (AdminAlertMail $mail) {
            return $mail->hasTo('admin@example.com')
                && str_contains($mail->htmlBody, 'expire.example');
        });
    }

    public function test_alert_is_marked_sent_when_only_email_succeeds(): void
    {
        config(['mail.admin' => 'admin@example.com']);
        Mail::fake();

        Http::fake([
            'https://down.example' => Http::response('Error', 503),
        ]);

        $domain = Domain::query()->create([
            'domain' => 'down.example',
            'health_check_enabled' => true,
            'health_status' => 'up',
        ]);

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->andReturn(false);
        $this->app->instance(TelegramService::class, $telegram);

        CheckSiteHealthJob::dispatchSync($domain->id);

        Mail::assertSent(AdminAlertMail::class);

        $domain->refresh();
        $this->assertNull($domain->last_health_notified_status);
        $this->assertSame('down', $domain->last_health_mailed_status);
    }

    public function test_down_site_emails_again_when_previous_mail_was_not_sent(): void
    {
        config(['mail.admin' => 'dilovar09@gmail.com']);
        Mail::fake();

        Http::fake([
            'https://down.example' => Http::response('Error', 503),
        ]);

        $domain = Domain::query()->create([
            'domain' => 'down.example',
            'health_check_enabled' => true,
            'health_status' => 'down',
            'health_status_code' => 503,
            'last_health_notified_status' => 'down',
        ]);

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->never();
        $this->app->instance(TelegramService::class, $telegram);

        CheckSiteHealthJob::dispatchSync($domain->id);

        Mail::assertSent(AdminAlertMail::class, function (AdminAlertMail $mail) {
            return $mail->hasTo('dilovar09@gmail.com')
                && str_contains($mail->htmlBody, 'down.example');
        });
        $this->assertSame('down', $domain->refresh()->last_health_mailed_status);
    }
}

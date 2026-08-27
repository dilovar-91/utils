<?php

namespace App\Console\Commands;

use App\Jobs\CheckSiteHealthJob;
use App\Models\Domain;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:check-sites-health')]
#[Description('Проверить доступность отмеченных сайтов и отправить уведомления в Telegram')]
class CheckSitesHealth extends Command
{
    public function handle(): int
    {
        $this->info('['.now()->toDateTimeString().'] Начало проверки сайтов');

        $count = 0;

        Domain::query()
            ->healthCheckEnabled()
            ->select('id')
            ->chunkById(100, function ($domains) use (&$count) {
                foreach ($domains as $domain) {
                    CheckSiteHealthJob::dispatchSync($domain->id);
                    $count++;
                }
            });

        $this->info('['.now()->toDateTimeString()."] Проверено сайтов: {$count}");

        return self::SUCCESS;
    }
}

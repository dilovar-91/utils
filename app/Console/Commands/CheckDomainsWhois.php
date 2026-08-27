<?php

namespace App\Console\Commands;

use App\Jobs\CheckDomainWhoisJob;
use App\Models\Domain;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:check-domains-whois')]
#[Description('Проверить WHOIS доменов и отправить уведомления в Telegram о скорой просрочке')]
class CheckDomainsWhois extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('[' . now()->toDateTimeString() . '] Начало проверки доменов');

        $count = 0;

        Domain::query()
            ->select('id')
            ->chunkById(100, function ($domains) use (&$count) {
                foreach ($domains as $domain) {
                    CheckDomainWhoisJob::dispatchSync($domain->id);
                    $count++;
                }
            });

        $this->info('[' . now()->toDateTimeString() . "] Проверено доменов: {$count}");

        return self::SUCCESS;
    }
}

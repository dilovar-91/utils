<?php

namespace App\Console\Commands;

use App\Jobs\CheckDomainWhoisJob;
use App\Models\Domain;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:check-domains-whois')]
#[Description('Command description')]
class CheckDomainsWhois extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        Domain::query()
            ->select('id')
            ->chunkById(100, function ($domains) {
                foreach ($domains as $domain) {
                    CheckDomainWhoisJob::dispatch($domain->id);
                }
            });

        $this->info('WHOIS jobs queued.');

        return self::SUCCESS;
    }
}

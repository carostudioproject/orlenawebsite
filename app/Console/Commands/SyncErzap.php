<?php

namespace App\Console\Commands;

use App\Actions\Integrations\ErzapSync;
use Illuminate\Console\Command;

class SyncErzap extends Command
{
    protected $signature = 'erzap:sync {--limit=50}';

    protected $description = 'Send queued paid transactions and cancellations to Erzap (waits quietly until Erzap is configured)';

    public function handle(ErzapSync $sync): int
    {
        foreach ($sync->due((int) $this->option('limit')) as $row) {
            $this->line($row->type.' #'.$row->subject_id.': '.$sync->run($row));
        }

        return self::SUCCESS;
    }
}

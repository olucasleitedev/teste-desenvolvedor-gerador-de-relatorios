<?php

namespace App\Console\Commands;

use App\Models\ReportExport;
use App\Services\ReportExportProcessor;
use App\Services\ReportExportQueue;
use Illuminate\Console\Command;

class ConsumeReportExports extends Command
{
    protected $signature = 'reports:consume-exports';

    protected $description = 'Consume report export jobs from RabbitMQ';

    public function handle(ReportExportQueue $queue, ReportExportProcessor $processor): int
    {
        $this->info('Listening for report export jobs on RabbitMQ…');

        $queue->consume(function ($message) use ($processor, $queue): void {
            $exportId = $queue->decodeExportId($message);
            $export = ReportExport::query()->findOrFail($exportId);

            $this->line("Processing export {$export->id} ({$export->format})");
            $processor->process($export);
            $this->info("Export {$export->id} completed.");
        });

        return self::SUCCESS;
    }
}

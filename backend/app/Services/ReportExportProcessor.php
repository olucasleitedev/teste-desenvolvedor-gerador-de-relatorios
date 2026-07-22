<?php

namespace App\Services;

use App\Models\ReportExport;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ReportExportProcessor
{
    public function __construct(
        private readonly BillingReportService $reports,
        private readonly BillingCsvExporter $csvExporter,
    ) {
    }

    public function process(ReportExport $export): void
    {
        $export->update([
            'status' => 'processing',
            'started_at' => now(),
            'error_message' => null,
        ]);

        $path = "report-exports/{$export->id}.{$export->format}";

        try {
            $rowCount = match ($export->format) {
                'csv' => $this->csvExporter->exportToDisk($export->filters, $path),
                'pdf' => $this->reports->pdfToDisk($export->filters, $path),
                default => throw new \InvalidArgumentException("Unsupported export format: {$export->format}"),
            };

            $export->update([
                'status' => 'completed',
                'file_path' => $path,
                'row_count' => $rowCount,
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            if (Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }

            $export->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            throw $exception;
        }
    }
}

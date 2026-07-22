<?php

namespace App\Services;

use App\Models\Billing;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Symfony\Component\HttpFoundation\Response;

class BillingCsvExporter
{
    public function __construct(private readonly BillingReportService $reports)
    {
    }

    public function download(array $filters): Response
    {
        $filename = 'billing-report-'.now()->format('YmdHis').'.csv';
        $totals = $this->reports->totals($this->reports->query($filters));

        return ResponseFacade::streamDownload(function () use ($filters, $totals): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Billing report']);
            fputcsv($output, ['Period from', $filters['date_from'] ?? '']);
            fputcsv($output, ['Period to', $filters['date_to'] ?? '']);
            fputcsv($output, ['Date field', $filters['date_field'] ?? 'issue_date']);
            fputcsv($output, ['Customer ID', $filters['customer_id'] ?? '']);
            fputcsv($output, ['Status', $filters['status'] ?? '']);
            fputcsv($output, []);
            fputcsv($output, ['Totals']);
            fputcsv($output, ['Count', $totals['count']]);
            fputcsv($output, ['Original total', $totals['original_total']]);
            fputcsv($output, ['Interest total', $totals['interest_total']]);
            fputcsv($output, ['Updated total', $totals['updated_total']]);
            fputcsv($output, ['Received total', $totals['received_total']]);
            fputcsv($output, ['Pending total', $totals['pending_total']]);
            fputcsv($output, []);
            fputcsv($output, [
                'ID', 'Customer', 'Description', 'Status', 'Issue date', 'Due date',
                'Payment date', 'Original amount', 'Interest amount', 'Updated amount', 'Paid amount',
            ]);

            $this->reports->query($filters)->orderBy('id')->lazyById(500)->each(
                function (Billing $billing) use ($output): void {
                    fputcsv($output, [
                        $billing->id,
                        $billing->customer?->name,
                        $billing->description,
                        $billing->derivedStatus(),
                        $billing->issue_date->toDateString(),
                        $billing->due_date->toDateString(),
                        $billing->payment_date?->toDateString(),
                        number_format((float) $billing->original_amount, 2, '.', ''),
                        number_format($billing->currentInterestAmount(), 2, '.', ''),
                        number_format($billing->currentUpdatedAmount(), 2, '.', ''),
                        $billing->paid_amount === null
                            ? ''
                            : number_format((float) $billing->paid_amount, 2, '.', ''),
                    ]);
                }
            );
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

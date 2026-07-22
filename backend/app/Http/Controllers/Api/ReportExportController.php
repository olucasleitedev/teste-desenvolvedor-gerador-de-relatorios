<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportExportRequest;
use App\Http\Resources\ReportExportResource;
use App\Services\ReportExportQueue;
use App\Models\ReportExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ReportExportController extends Controller
{
    public function store(ReportExportRequest $request, ReportExportQueue $queue): JsonResponse
    {
        $export = ReportExport::create([
            'user_id' => $request->user()->id,
            'format' => $request->validated('format'),
            'filters' => $request->reportFilters(),
            'status' => 'pending',
        ]);

        $queue->publish($export);
        $export->refresh();

        return ReportExportResource::make($export)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function show(Request $request, ReportExport $export): JsonResponse
    {
        $this->ensureOwner($request, $export);

        return ReportExportResource::make($export)->response();
    }

    public function download(Request $request, ReportExport $export): Response
    {
        $this->ensureOwner($request, $export);

        if (! $export->isReady() || ! Storage::disk('local')->exists($export->file_path)) {
            abort(Response::HTTP_CONFLICT, 'Export is not ready for download.');
        }

        return Storage::disk('local')->download($export->file_path, $export->downloadFilename());
    }

    private function ensureOwner(Request $request, ReportExport $export): void
    {
        if ($export->user_id !== $request->user()->id) {
            abort(Response::HTTP_FORBIDDEN, 'You cannot access this export.');
        }
    }
}

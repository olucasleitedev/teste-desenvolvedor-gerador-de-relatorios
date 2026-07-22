<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'format',
        'filters',
        'status',
        'file_path',
        'row_count',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function downloadFilename(): string
    {
        $suffix = $this->completed_at?->format('YmdHis') ?? now()->format('YmdHis');

        return "billing-report-{$suffix}.{$this->format}";
    }

    public function isReady(): bool
    {
        return $this->status === 'completed' && $this->file_path !== null;
    }
}

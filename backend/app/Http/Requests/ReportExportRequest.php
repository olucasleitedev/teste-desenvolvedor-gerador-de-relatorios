<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'format' => ['required', Rule::in(['csv', 'pdf'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'status' => ['nullable', Rule::in(['pending', 'overdue', 'paid', 'cancelled'])],
            'date_field' => ['nullable', Rule::in(['issue_date', 'due_date', 'payment_date'])],
            'sort_by' => ['nullable', 'string'],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    public function reportFilters(): array
    {
        return collect($this->validated())
            ->except('format')
            ->filter(static fn ($value) => $value !== null && $value !== '')
            ->all();
    }
}

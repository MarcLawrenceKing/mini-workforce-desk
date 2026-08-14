<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TimeLogExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $viewer = $request->user();

        // The company comes only from the authenticated Laratrust user. A caller
        // cannot select or override it with a query-string company_id.
        abort_unless($viewer->hasRole('company_admin') && $viewer->company_id, 403);

        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $month = isset($validated['month'])
            ? CarbonImmutable::createFromFormat('!Y-m', $validated['month'])
            : null;

        $logs = AttendanceLog::query()
            ->visibleTo($viewer)
            // Keep this explicit company condition as defense in depth for this
            // data-export boundary, even though visibleTo() already scopes it.
            ->whereHas('employee', fn ($query) => $query->where('company_id', $viewer->company_id))
            ->when($month, fn ($query) => $query->whereBetween('date', [
                $month->startOfMonth(),
                $month->endOfMonth(),
            ]))
            ->with(['employee:id,company_id,employee_no,first_name,middle_name,last_name', 'employee.company:id,name', 'approver:id,name'])
            ->oldest('date')
            ->oldest('log_in_time')
            ->get();

        $companySlug = Str::slug($viewer->company?->name ?? 'company');
        $period = $month?->format('Y-m') ?? 'all';
        $filename = "{$companySlug}-attendance-{$period}.csv";

        return response()->streamDownload(function () use ($logs): void {
            $output = fopen('php://output', 'wb');

            // UTF-8 BOM lets Excel open names and notes without mojibake.
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Employee No.', 'Employee', 'Company', 'Date', 'Time In',
                'Time Out', 'Duration', 'Status', 'Approved By',
                'Approved At', 'Notes', 'Reject Reason',
            ], ',', '"', '');

            foreach ($logs as $log) {
                fputcsv($output, array_map($this->safeCell(...), [
                    $log->employee->employee_no,
                    $log->employee->full_name,
                    $log->employee->company?->name,
                    $log->date->format('Y-m-d'),
                    $log->log_in_time ? substr($log->log_in_time, 0, 5) : null,
                    $log->log_out_time ? substr($log->log_out_time, 0, 5) : null,
                    $log->duration,
                    $log->status,
                    $log->approver?->name,
                    $log->approved_at?->format('Y-m-d H:i:s'),
                    $log->notes,
                    $log->reject_reason,
                ]), ',', '"', '');
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Prevent spreadsheet applications from interpreting exported text as a formula. */
    private function safeCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@]/', $value) ? "'{$value}" : $value;
    }
}

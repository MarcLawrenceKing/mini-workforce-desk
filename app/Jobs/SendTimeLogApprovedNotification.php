<?php

namespace App\Jobs;

use App\Models\AttendanceLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Queued when a company_admin approves or rejects an attendance log.
 *
 * `implements ShouldQueue` is the whole trick: without it Laravel runs the job
 * inline, with it the job is serialised onto the Redis queue and the HTTP
 * request returns straight away.
 */
class SendTimeLogApprovedNotification implements ShouldQueue
{
    use Queueable;

    /** Retry a failing job twice more, 10s apart, before it lands in `queue:failed`. */
    public int $tries = 3;

    public int $backoff = 10;

    /**
     * The model is serialised as just its id and re-fetched by the worker, so
     * the notification reflects the row as it is when the job actually runs.
     *
     * @param  string  $decision  'approved' or 'rejected'
     */
    public function __construct(
        public AttendanceLog $attendanceLog,
        public string $decision,
    ) {}

    public function handle(): void
    {
        $log = $this->attendanceLog->loadMissing(['employee.user', 'approver']);
        $recipient = $log->employee?->user?->email;

        $summary = sprintf(
            'Attendance log #%d for %s on %s was %s%s.',
            $log->id,
            $log->employee?->full_name ?? 'an unknown employee',
            $log->date->format('Y-m-d'),
            $this->decision,
            $this->decision === 'approved'
                ? ' by ' . ($log->approver?->name ?? 'a company admin')
                : ($log->reject_reason ? ' — reason: ' . $log->reject_reason : ''),
        );

        // Stand-in for a real notification feature: one line per job in
        // storage/logs/notifications.log.
        Log::channel('notifications')->info($summary, [
            'attendance_log_id' => $log->id,
            'decision' => $this->decision,
            'recipient' => $recipient,
        ]);

        // MAIL_MAILER=log, so this "email" is dumped into storage/logs/laravel.log
        // instead of being sent. Swap the mailer and it becomes a real email.
        if ($recipient) {
            Mail::raw($summary, fn(Message $message) => $message
                ->to($recipient)
                ->subject('Your attendance log was ' . $this->decision));
        }
    }
}

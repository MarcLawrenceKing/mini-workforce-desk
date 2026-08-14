<?php

namespace App\Http\Controllers\Concerns;

use App\Jobs\SendTimeLogApprovedNotification;
use App\Models\AttendanceLog;

/**
 * Task 8's queued notification, shared by the Inertia controller and the Task 9
 * API so both fire it on exactly the same condition.
 */
trait NotifiesTimeLogDecision
{
    /**
     * Only on a real transition — re-saving an already-approved log with no
     * status change shouldn't re-notify the employee.
     */
    protected function notifyOnDecision(AttendanceLog $log, string $previousStatus): void
    {
        if (
            $log->status !== $previousStatus
            && in_array($log->status, ['approved', 'rejected'], true)
        ) {
            SendTimeLogApprovedNotification::dispatch($log, $log->status);
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class FlagMissingTimeLogs extends Command
{
    /** @var string */
    protected $signature = 'timelogs:flag-missing';

    /** @var string */
    protected $description = 'Mark employees who have no attendance log today';

    public function handle(): int
    {
        $date = today()->toDateString();
        $flagged = 0;

        Employee::query()
            ->whereDoesntHave(
                'attendanceLogs',
                fn (Builder $query) => $query->whereDate('date', $date),
            )
            ->select('id')
            ->chunkById(500, function ($employees) use ($date, &$flagged): void {
                $timestamp = now();

                $rows = $employees->map(fn (Employee $employee) => [
                    'employee_id' => $employee->id,
                    'date' => $date,
                    'status' => 'missing',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all();

                // The unique employee/date index also makes concurrent runs safe.
                $flagged += AttendanceLog::query()->insertOrIgnore($rows);
            });

        $this->info("Flagged {$flagged} employee(s) as missing for {$date}.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps the cached /dashboard KPI counts honest.
 *
 * Attached to Company, Employee and User via #[ObservedBy]. Any row added,
 * removed or restored drops every key tagged 'dashboard', so the next visit
 * recounts from the database.
 */
class DashboardCacheObserver
{
    public function created(Model $model): void
    {
        $this->flush();
    }

    public function deleted(Model $model): void
    {
        $this->flush();
    }

    public function restored(Model $model): void
    {
        $this->flush();
    }

    public function forceDeleted(Model $model): void
    {
        $this->flush();
    }

    private function flush(): void
    {
        Cache::tags(['dashboard'])->flush();
    }
}

<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RealtimeUsersKpi
{
    /**
     * Tell the local Socket.IO server the latest users count.
     *
     * A stopped realtime server must never make an otherwise valid user
     * creation fail, so publishing is intentionally best-effort.
     */
    public function publish(): void
    {
        if (! config('services.realtime.enabled')) {
            return;
        }

        try {
            Http::connectTimeout(1)
                ->timeout(2)
                ->withHeader('X-Realtime-Secret', (string) config('services.realtime.secret'))
                ->post(rtrim((string) config('services.realtime.url'), '/').'/publish/users-kpi', [
                    'users' => User::query()->count(),
                ])
                ->throw();
        } catch (Throwable $exception) {
            Log::warning('Could not publish the realtime users KPI.', [
                'message' => $exception->getMessage(),
            ]);
        }
    }
}

<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;

class ActivityLogger
{
    public function log(User $user, string $action, string $subject): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => $user->getKey(),
            'action' => $action,
            'subject' => $subject,
        ]);
    }
}

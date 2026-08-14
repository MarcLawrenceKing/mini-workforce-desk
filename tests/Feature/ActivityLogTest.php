<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_login_and_logout_are_logged(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'login',
            'subject' => 'web session',
        ]);

        $this->post('/logout')->assertRedirect('/login');

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'logout',
            'subject' => 'web session',
        ]);
    }

    public function test_api_login_and_logout_are_logged(): void
    {
        $user = User::factory()->create();

        $token = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'activity-test',
        ])->assertOk()->json('token');

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'login',
            'subject' => 'API token: activity-test',
        ]);

        $this->withToken($token)->postJson('/api/logout')->assertNoContent();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'logout',
            'subject' => 'API token: activity-test',
        ]);

        $this->assertSame(2, ActivityLog::whereBelongsTo($user)->count());
    }
}

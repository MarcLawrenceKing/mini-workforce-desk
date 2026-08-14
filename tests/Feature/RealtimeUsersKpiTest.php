<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RealtimeUsersKpi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RealtimeUsersKpiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_the_current_users_count_with_the_secret(): void
    {
        User::factory()->count(2)->create();

        config([
            'services.realtime.enabled' => true,
            'services.realtime.url' => 'http://127.0.0.1:3001',
            'services.realtime.secret' => 'test-secret',
        ]);

        Http::fake([
            'http://127.0.0.1:3001/*' => Http::response(['emitted' => true], 202),
        ]);

        app(RealtimeUsersKpi::class)->publish();

        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:3001/publish/users-kpi'
            && $request->hasHeader('X-Realtime-Secret', 'test-secret')
            && $request['users'] === 2);
    }

    public function test_it_does_nothing_when_realtime_is_disabled(): void
    {
        config(['services.realtime.enabled' => false]);
        Http::fake();

        app(RealtimeUsersKpi::class)->publish();

        Http::assertNothingSent();
    }
}

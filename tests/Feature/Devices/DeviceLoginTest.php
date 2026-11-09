<?php

namespace Tests\Feature\Devices;

use App\Models\User;
use App\Modules\Core\Services\ProfileContext;
use App\Modules\Devices\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceLoginTest extends TestCase
{
    use RefreshDatabase;

    private function tvStart(): array
    {
        return $this->postJson('/device-login/start', ['name' => 'Living room TV'])->assertOk()->json();
    }

    public function test_qr_flow_requires_explicit_approval_and_is_single_use(): void
    {
        config(['app.url' => 'https://movexa.lan:8088']);
        $tv = $this->tvStart();
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6}$/', $tv['code']);
        $this->assertSame('https://movexa.lan:8088/link?code='.$tv['code'], $tv['url']); // QR link carries the port

        // Before approval: no session is created.
        $this->postJson('/device-login/poll', ['poll_token' => $tv['poll_token']])->assertStatus(202);
        $this->assertGuest();

        // Phone (logged in) sees the device name + code, then approves explicitly.
        $user = User::factory()->create();
        $user->update(['max_age_rating' => 13]);
        $this->actingAs($user);
        $this->getJson('/device-login/lookup?code='.$tv['code'])->assertOk()->assertJson(['device_name' => 'Living room TV']);
        $this->postJson('/device-login/approve', ['code' => $tv['code']])->assertOk();

        // TV polls → signed in to the approving account (its age limit / permissions apply as usual).
        auth()->logout();
        $this->flushSession();
        $this->postJson('/device-login/poll', ['poll_token' => $tv['poll_token']])->assertOk()->assertJson(['redirect' => '/home']);
        $this->assertAuthenticatedAs($user);
        $this->get('/home')->assertOk();
        $this->assertSame(13, auth()->user()->max_age_rating);
        $this->assertSame('tv', Device::where('user_id', $user->id)->latest('id')->first()->kind);

        // Single use: the same token / code cannot be used again.
        auth()->logout();
        $this->flushSession();
        $this->postJson('/device-login/poll', ['poll_token' => $tv['poll_token']])->assertStatus(410);
        $this->actingAs($user)->postJson('/device-login/approve', ['code' => $tv['code']])->assertNotFound();
    }

    public function test_code_expires_after_five_minutes(): void
    {
        $tv = $this->tvStart();
        $this->travel(301)->seconds();
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->getJson('/device-login/lookup?code='.$tv['code'])->assertNotFound();
        $this->postJson('/device-login/approve', ['code' => $tv['code']])->assertNotFound();
        auth()->logout();
        $this->postJson('/device-login/poll', ['poll_token' => $tv['poll_token']])->assertStatus(410);
        $this->assertGuest();
    }

    public function test_view_as_cannot_approve_and_guessing_is_rate_limited(): void
    {
        $tv = $this->tvStart();
        $admin = User::factory()->create(['is_admin' => true]);
        $other = User::factory()->create();
        $this->actingAs($admin)->withSession(['view_as' => ['user' => $other->id]]);
        $this->postJson('/device-login/approve', ['code' => $tv['code']])->assertForbidden();
        $this->flushSession();
        $user = User::factory()->create();
        $this->actingAs($user);
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/device-login/lookup?code=AAAAAA');
        }
        $this->getJson('/device-login/lookup?code=AAAAAA')->assertStatus(429);
    }

    public function test_revoking_a_device_logs_it_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->get('/home')->assertOk();
        $device = Device::where('user_id', $user->id)->firstOrFail();
        $device->update(['revoked_at' => now()]);
        $this->get('/home')->assertRedirect('/login');
        $this->assertGuest();
    }
}

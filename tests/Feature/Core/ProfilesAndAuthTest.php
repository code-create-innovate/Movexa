<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\Profile;
use App\Modules\Core\Services\ProfileContext;
use App\Modules\Core\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfilesAndAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/home')->assertRedirect('/login');
        $this->get('/profiles')->assertRedirect('/login');
    }

    public function test_home_requires_a_selected_profile(): void
    {
        $user = User::factory()->create();
        $user->profiles()->create(['name' => 'A']);
        $this->actingAs($user)->get('/home')->assertRedirect('/profiles');
    }

    public function test_profile_pin_is_enforced(): void
    {
        $user = User::factory()->create();
        $p = $user->profiles()->make(['name' => 'Locked']);
        $p->setPin('4321');
        $p->save();

        $this->actingAs($user)->post("/profiles/{$p->id}/enter", ['pin' => '0000'])->assertSessionHasErrors('pin');
        $this->actingAs($user)->post("/profiles/{$p->id}/enter", ['pin' => '4321'])->assertRedirect('/home');
        $this->assertSame($p->id, session(ProfileContext::SESSION_KEY));
    }

    public function test_cannot_enter_another_users_profile(): void
    {
        $other = Profile::factory()->create();
        $this->actingAs(User::factory()->create())->post("/profiles/{$other->id}/enter")->assertNotFound();
    }

    public function test_leaving_kid_profile_requires_parental_pin(): void
    {
        $user = User::factory()->create(['parental_pin_hash' => Hash::make('9999')]);
        $kid = $user->profiles()->create(['name' => 'Kid', 'is_kid' => true, 'max_age_rating' => 7]);
        $adult = $user->profiles()->create(['name' => 'Dad']);
        $this->actingAs($user)->withSession([ProfileContext::SESSION_KEY => $kid->id]);

        $this->post("/profiles/{$adult->id}/enter")->assertSessionHasErrors('parent_pin');
        $this->post("/profiles/{$adult->id}/enter", ['parent_pin' => '1111'])->assertSessionHasErrors('parent_pin');
        $this->post("/profiles/{$adult->id}/enter", ['parent_pin' => '9999'])->assertRedirect('/home');
    }

    public function test_kid_profile_cannot_manage_profiles_or_open_admin(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $kid = $user->profiles()->create(['name' => 'Kid', 'is_kid' => true]);
        $this->actingAs($user)->withSession([ProfileContext::SESSION_KEY => $kid->id]);
        $this->post('/profiles', ['name' => 'New'])->assertForbidden();
        $this->patch("/profiles/{$kid->id}", ['name' => 'Kid', 'is_kid' => false])->assertForbidden();
        $this->get('/admin')->assertForbidden();
    }

    public function test_login_with_two_factor(): void
    {
        $secret = Totp::generateSecret();
        $user = User::factory()->create(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/two-factor-challenge', ['code' => Totp::code($secret)])->assertRedirect('/profiles');
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_only_open_for_first_user(): void
    {
        $this->post('/register', ['name' => 'Owner', 'email' => 'o@x.lan', 'password' => 'password123!', 'password_confirmation' => 'password123!'])->assertRedirect('/profiles');
        $owner = User::first();
        $this->assertTrue($owner->is_admin);
        $this->assertSame(1, $owner->profiles()->count());
        auth()->logout();
        $this->post('/register', ['name' => 'X', 'email' => 'x@x.lan', 'password' => 'password123!', 'password_confirmation' => 'password123!'])->assertForbidden();
    }
}

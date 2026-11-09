<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Services\Totp;
use App\Modules\Library\Models\Library;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

/** One account per person (no "who's watching"), permissions per account, set by an admin. */
class AccountsAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/home')->assertRedirect('/login');
    }

    public function test_login_with_username_or_email_goes_straight_home(): void
    {
        $u = User::factory()->create(['username' => 'sara', 'email' => 'sara@home.lan', 'password' => 'sara-pass-1']);
        $this->post('/login', ['email' => 'sara', 'password' => 'sara-pass-1'])->assertRedirect('/home');
        $this->assertAuthenticatedAs($u);
        auth()->logout();
        $this->post('/login', ['email' => 'sara@home.lan', 'password' => 'sara-pass-1'])->assertRedirect('/home');
        auth()->logout();
        $this->post('/login', ['email' => 'sara', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_no_profile_picker_any_more(): void
    {
        $this->actingAsProfile();
        $this->get('/profiles')->assertRedirect('/home');
        $this->get('/home')->assertOk();
    }

    public function test_disabled_account_cannot_sign_in_and_active_sessions_end(): void
    {
        $u = User::factory()->create(['username' => 'old', 'password' => 'old-pass-1']);
        $this->actingAs($u)->get('/home')->assertOk();
        $u->update(['disabled_at' => now()]);
        $this->get('/home')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['email' => 'old', 'password' => 'old-pass-1'])->assertSessionHasErrors('email');
    }

    public function test_watching_permissions_are_enforced_on_every_route(): void
    {
        $file = MediaFixtures::readyMovie($this->dataRoot.'/library', ['age_rating' => 0]);
        $this->actingAsProfile(null, ['permissions' => []]); // nothing extra allowed
        $this->get('/live')->assertForbidden();
        $this->postJson('/live/1/watch')->assertForbidden();
        $this->post("/party/{$file->id}")->assertForbidden();
        $this->get('/downloads')->assertForbidden();
        $this->postJson('/downloads', ['file_id' => $file->id, 'quality' => 480])->assertForbidden();
        $this->postJson("/watch/{$file->id}/subtitles")->assertForbidden(); // needs "upload"
        $this->get("/title/{$file->media_item_id}")->assertOk();          // watching the library is always allowed

        $this->actingAsProfile(null, ['permissions' => ['live', 'party', 'download']]);
        $this->get('/live')->assertOk();
        $this->get('/downloads')->assertOk();
    }

    public function test_management_permissions_are_granular(): void
    {
        $routes = [
            'stats' => ['get', '/admin'], 'users' => ['get', '/admin/users'], 'library' => ['get', '/admin/matches'],
            'quarantine' => ['get', '/admin/quarantine'], 'live_manage' => ['get', '/admin/live'], 'blocks' => ['get', '/blocks'],
            'upload' => ['postJson', '/admin/uploads'],
        ];
        foreach ($routes as $perm => [$m, $url]) {
            $this->actingAsProfile(null, ['permissions' => ['live']]);
            $this->assertSame(403, $this->{$m}($url)->status(), "$url without $perm");
            $this->actingAsProfile(null, ['permissions' => [$perm]]);
            $this->assertNotSame(403, $this->{$m}($url)->status(), "$url with $perm");
        }
    }

    public function test_admin_creates_accounts_and_sets_permissions_age_and_libraries(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $movies = Library::create(['name' => 'Movies', 'type' => 'movies', 'path' => $this->dataRoot.'/library']);
        $kids = Library::create(['name' => 'Kids', 'type' => 'movies', 'path' => $this->dataRoot.'/library/kids']);
        $this->actingAs($admin)->post('/admin/users', ['name' => 'يوسف', 'username' => 'yousef', 'password' => 'kid-pass-1',
            'permissions' => ['party'], 'max_age_rating' => 7, 'allowed_library_ids' => [$kids->id]])->assertRedirect();
        $kid = User::where('username', 'yousef')->firstOrFail();
        $this->assertSame(['party'], $kid->permissions);
        $this->assertSame(7, $kid->max_age_rating);
        $this->assertSame(1, $kid->profiles()->count()); // the internal viewing profile

        $inKids = MediaFixtures::readyMovie($this->dataRoot.'/library', ['title' => 'Cartoon', 'age_rating' => 0]);
        $inKids->item->update(['library_id' => $kids->id]);
        $inMovies = MediaFixtures::readyMovie($this->dataRoot.'/library', ['title' => 'Nature', 'age_rating' => 0]);
        $inMovies->item->update(['library_id' => $movies->id]);
        $this->actingAs($kid);
        $this->get("/title/{$inKids->media_item_id}")->assertOk();
        $this->get("/title/{$inMovies->media_item_id}")->assertNotFound(); // library not allowed
        $this->getJson('/search?q=Nature')->assertJsonCount(0, 'results');

        // Kid cannot change own permissions; admin can't lock themselves out.
        $this->patch("/admin/users/{$kid->id}", ['permissions' => User::PERMISSIONS])->assertForbidden();
        $this->actingAs($admin);
        $this->patch("/admin/users/{$admin->id}", ['disabled' => true])->assertStatus(422);
        $this->patch("/admin/users/{$admin->id}", ['is_admin' => false])->assertStatus(422);
        $this->patch("/admin/users/{$kid->id}", ['allowed_library_ids' => null, 'max_age_rating' => null, 'disabled' => true])->assertRedirect();
        $this->assertNotNull($kid->fresh()->disabled_at);

        // A non-admin "users" manager cannot grant admin.
        $manager = User::factory()->create(['permissions' => ['users']]);
        $this->actingAs($manager)->patch("/admin/users/{$kid->id}", ['is_admin' => true])->assertForbidden();
    }

    public function test_login_with_two_factor(): void
    {
        $secret = Totp::generateSecret();
        $user = User::factory()->create(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/two-factor-challenge', ['code' => Totp::code($secret)])->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_only_open_for_first_user(): void
    {
        $this->post('/register', ['name' => 'Owner', 'email' => 'o@x.lan', 'password' => 'password123!', 'password_confirmation' => 'password123!'])->assertRedirect('/home');
        $this->assertTrue(User::first()->is_admin);
        auth()->logout();
        $this->post('/register', ['name' => 'X', 'email' => 'x@x.lan', 'password' => 'password123!', 'password_confirmation' => 'password123!'])->assertForbidden();
    }
}

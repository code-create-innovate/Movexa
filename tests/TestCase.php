<?php

namespace Tests;

use App\Models\User;
use App\Modules\Core\Models\Profile;
use App\Modules\Core\Services\ProfileContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    protected string $dataRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->dataRoot = sys_get_temp_dir().'/movexa-test-'.getmypid().'-'.bin2hex(random_bytes(4));
        foreach (['inbox', 'library', 'quarantine', 'cache', 'images', 'uploads'] as $d) {
            File::ensureDirectoryExists($this->dataRoot.'/'.$d);
            config(["movexa.paths.{$d}" => $this->dataRoot.'/'.$d]);
        }
        config(['movexa.data_root' => $this->dataRoot]);
        if ($sock = getenv('MOVEXA_TEST_CLAMD') ?: null) {
            config(['movexa.security.clamd_socket' => $sock]);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dataRoot);
        parent::tearDown();
    }

    protected function clamdAvailable(): bool
    {
        return (new \App\Modules\Library\Services\Security\ClamdScanner)->available();
    }

    /** Signs in a (new) account and returns its viewing profile. */
    protected function actingAsProfile(?Profile $profile = null, array $userAttrs = []): Profile
    {
        $profile ??= User::factory()->create($userAttrs)->primaryProfile();
        $this->actingAs($profile->user);

        return $profile;
    }

    /** A separate account (e.g. a child with an age limit set by the admin). */
    protected function account(array $attrs = []): User
    {
        return User::factory()->create($attrs);
    }
}

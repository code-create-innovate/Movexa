<?php
// Seeds a throwaway E2E database: admin + profile, one movie with 2 subtitles and seek thumbnails.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Modules\Library\Models\Subtitle;
use App\Modules\Library\Services\ThumbnailGenerator;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\MediaFixtures;

Artisan::call('migrate:fresh', ['--force' => true]);
foreach (['inbox', 'library', 'quarantine', 'cache', 'images', 'uploads'] as $d) {
    @mkdir(config('movexa.paths.'.$d), 0755, true);
}
$user = User::create(['name' => 'Admin', 'email' => 'admin@e2e.lan', 'password' => 'password-e2e', 'is_admin' => true]);
$user->profiles()->create(['name' => 'Othman', 'avatar_color' => '#2563eb']);

$file = MediaFixtures::readyMovie(config('movexa.paths.library'), ['title' => 'Test Movie', 'title_ar' => 'فيلم تجريبي', 'year' => 2024, 'age_rating' => 0, 'overview_ar' => 'فيلم للاختبار.'], 'mp4');
// make it longer for seeking
MediaFixtures::make($file->path, 'mp4', 40);
$file->update(['duration' => 40.0, 'size' => filesize($file->path)]);
$dir = config('movexa.paths.library').'/.subtitles/'.$file->id;
@mkdir($dir, 0755, true);
file_put_contents("$dir/ar.vtt", "WEBVTT\n\n1\n00:00:00.000 --> 00:00:40.000\nمرحباً بالعالم\n");
file_put_contents("$dir/en.vtt", "WEBVTT\n\n1\n00:00:00.000 --> 00:00:40.000\nHello world\n");
Subtitle::create(['media_file_id' => $file->id, 'language' => 'ar', 'label' => 'العربية', 'path' => "$dir/ar.vtt", 'source' => 'external']);
Subtitle::create(['media_file_id' => $file->id, 'language' => 'en', 'label' => 'English', 'path' => "$dir/en.vtt", 'source' => 'external']);
app(ThumbnailGenerator::class)->generate($file->fresh());
echo json_encode(['item' => $file->media_item_id, 'file' => $file->id]), "\n";

<?php

namespace App\Modules\Media\Services;

use App\Modules\Library\Models\MediaFile;

/**
 * Chooses Direct Play → Remux → Transcode from what the browser reports it can decode
 * (HTMLMediaElement.canPlayType / MediaSource.isTypeSupported, sent by the player).
 */
class PlaybackDecider
{
    /** @param array{video?:list<string>,audio?:list<string>,containers?:list<string>,maxHeight?:int} $caps */
    public function decide(MediaFile $file, array $caps, int $audioIndex = 0, bool $forceTranscode = false): array
    {
        $videoOk = in_array($this->norm($file->video_codec), $caps['video'] ?? ['h264'], true);
        $audioStreams = $file->streams->where('type', 'audio')->values();
        $chosen = $audioStreams[$audioIndex] ?? $audioStreams->first();
        $audioOk = ! $chosen || in_array($this->norm($chosen->codec), $caps['audio'] ?? ['aac', 'mp3'], true);
        $containerOk = in_array($file->container, $caps['containers'] ?? ['mp4'], true);
        $tooBig = isset($caps['maxHeight']) && $file->height && $file->height > $caps['maxHeight'] + 8;

        if (! $forceTranscode && $videoOk && $audioOk && $containerOk && $audioIndex === 0 && ! $tooBig) {
            return ['method' => 'direct', 'reason' => 'compatible'];
        }
        if (! $forceTranscode && $videoOk && ! $tooBig) {
            return ['method' => 'remux', 'reason' => ! $containerOk ? 'container' : (! $audioOk ? 'audio_codec' : 'audio_track')];
        }

        return ['method' => 'transcode', 'reason' => $forceTranscode ? 'quality_selected' : (! $videoOk ? 'video_codec' : 'resolution')];
    }

    private function norm(?string $codec): string
    {
        return match ($codec) {
            'h265' => 'hevc', 'mpeg4' => 'mpeg4', 'eac3' => 'eac3', null => 'none', default => strtolower($codec),
        };
    }
}

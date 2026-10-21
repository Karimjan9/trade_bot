<?php

namespace App\Services\Signals;

class MediaPolicy
{
    public function isVideo(array $raw): bool
    {
        $mime = data_get($raw, 'media.document.mime_type', '');

        return data_get($raw, '_signal_forge_media.kind') === 'video'
            || str_starts_with((string) $mime, 'video/')
            || collect(data_get($raw, 'media.document.attributes', []))->contains(fn ($attribute) => ($attribute['_'] ?? null) === 'DocumentAttributeVideo');
    }

    public function parse(string $text, array $raw): array
    {
        if ($this->isVideo($raw) && trim($text) === '') {
            return ['type' => 'SKIPPED_VIDEO', 'confidence' => 1, 'reason_code' => 'VIDEO_CONTENT_DISABLED'];
        }
        $parsed = app(SignalParser::class)->parse($text);
        if ($this->isVideo($raw)) {
            $parsed['media_policy'] = 'VIDEO_SKIPPED_CAPTION_ONLY';
        }

        return $parsed;
    }
}

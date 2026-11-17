<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\UploaderHelper;

/**
 * Pictures uploaded for an event's displays (D-displays-overlays.md §3.2, §10): logos, sponsor images, backgrounds.
 *
 * - Stored at `competition-displays/<competitionId>/<uuid7>.<ext>` - exactly what DisplayDesignSanitizer keeps in a
 *   design of that event (never another event's file, never an external URL).
 * - Delivered through the image proxy: with the `display` preset (rs:fit:1920:1920, alpha kept) once lily's imgproxy
 *   knows it and DISPLAY_IMAGE_PRESET names it; until then (empty, the default) the original upload - `/original/`.
 */
readonly final class DisplayImages
{
    public const string PREFIX = 'competition-displays/';
    public const int MAX_FILE_BYTES = 5 * 1024 * 1024;

    /** The formats a display shows - SVG never (it could carry script), the extension by the file's real content */
    public const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    private const string PRESET_PATTERN = '/^[a-z0-9_]{1,40}\z/';

    public function __construct(
        private UploaderHelper $uploaderHelper,
        private string $nginxProxyBaseUrl,
        private string $displayImagePreset,
    ) {
    }

    /**
     * The URL a display loads the picture from. A preset name that is no plain name falls back to the original - a
     * typo in the environment never breaks the pictures of a running event.
     */
    public function url(string $path): string
    {
        $preset = trim($this->displayImagePreset);

        if ($preset === '' || preg_match(self::PRESET_PATTERN, $preset) !== 1) {
            return $this->uploaderHelper->getPublicPath($path);
        }

        return sprintf('%s/preset:%s/plain/%s', $this->nginxProxyBaseUrl, $preset, ltrim($path, '/'));
    }

    /**
     * A new storage key for an upload of the given (sniffed) MIME type, null when displays do not take that format.
     */
    public static function newPath(string $competitionId, string $mimeType): null|string
    {
        $extension = self::EXTENSIONS[$mimeType] ?? null;

        if ($extension === null) {
            return null;
        }

        return self::PREFIX . strtolower($competitionId) . '/' . Uuid::uuid7()->toString() . '.' . $extension;
    }

    /**
     * Any event's display upload - the only storage keys the display clean-up may ever delete.
     */
    public static function isDisplayUpload(string $path): bool
    {
        return preg_match('~^' . preg_quote(self::PREFIX, '~') . self::UUID . '/' . self::UUID . '\.(?:jpe?g|png|gif|webp)\z~', $path) === 1;
    }
}

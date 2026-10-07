<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Value\PageSectionOwner;
use SpeedPuzzling\Web\Value\PageSectionType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Server-side cleaning of organiser-written section payloads - the security boundary, run by the handlers on every
 * write (the editor's own rules are only convenience). Rich text goes through the strict `competition_page` sanitizer
 * (config/packages/html_sanitizer.php); every other value is plain text, escaped by Twig when shown. Links must be
 * http(s), pictures must be uploads of the section's own page (PageSectionOwner::uploadDirectory()).
 *
 * The page form (PageSectionRequestParser) explains to the organiser what would be dropped here; this class silently
 * drops it, for whatever reaches a handler another way.
 */
readonly final class PageSectionContentSanitizer
{
    /** Also the sanitizer's max_input_length (config/packages/html_sanitizer.php) */
    public const int MAX_HTML_LENGTH = 100000;
    public const int MAX_TEXT_LENGTH = 500;
    public const int MAX_LONG_TEXT_LENGTH = 5000;
    public const int MAX_URL_LENGTH = 2000;
    public const int MAX_ROWS = 50;
    // Gallery photos / sponsor logos of one section - uploads are hosted on the CDN, a page is no free image hosting
    public const int MAX_IMAGES = 40;

    public function __construct(
        #[Autowire(service: 'html_sanitizer.sanitizer.competition_page')]
        private HtmlSanitizerInterface $htmlSanitizer,
    ) {
    }

    /**
     * @param array<mixed> $content
     * @return array<string, mixed>
     */
    public function sanitize(PageSectionType $type, array $content, PageSectionOwner $owner): array
    {
        return match ($type) {
            PageSectionType::RichText => [
                'html' => $this->html($content['html'] ?? null),
            ],
            PageSectionType::Faq => [
                'items' => $this->rows($content['items'] ?? null, fn (array $item): null|array => $this->text($item['question'] ?? null) !== ''
                    ? [
                        'question' => $this->text($item['question'] ?? null),
                        'answer' => $this->text($item['answer'] ?? null, self::MAX_LONG_TEXT_LENGTH),
                    ]
                    : null),
            ],
            PageSectionType::Gallery => [
                'images' => $this->rows($content['images'] ?? null, fn (array $item): null|array => $this->uploadPath($item['path'] ?? null, $owner) !== null
                    ? [
                        'path' => $this->uploadPath($item['path'] ?? null, $owner),
                        'caption' => $this->text($item['caption'] ?? null),
                    ]
                    : null, self::MAX_IMAGES),
            ],
            PageSectionType::Venue => [
                'address' => $this->text($content['address'] ?? null),
                'mapUrl' => $this->url($content['mapUrl'] ?? null),
                'directions' => $this->text($content['directions'] ?? null, self::MAX_LONG_TEXT_LENGTH),
            ],
            PageSectionType::Sponsors => [
                'sponsors' => self::limitLogos($this->rows($content['sponsors'] ?? null, fn (array $item): null|array => $this->text($item['name'] ?? null) !== ''
                    ? [
                        'name' => $this->text($item['name'] ?? null),
                        'url' => $this->url($item['url'] ?? null),
                        'logoPath' => $this->uploadPath($item['logoPath'] ?? null, $owner),
                    ]
                    : null)),
            ],
            PageSectionType::Links => [
                'links' => $this->rows($content['links'] ?? null, fn (array $item): null|array => $this->url($item['url'] ?? null) !== null
                    ? [
                        'label' => $this->text($item['label'] ?? null),
                        'url' => $this->url($item['url'] ?? null),
                    ]
                    : null),
            ],
            PageSectionType::Contact => [
                'email' => $this->email($content['email'] ?? null),
                'phone' => $this->text($content['phone'] ?? null),
                'note' => $this->text($content['note'] ?? null, self::MAX_LONG_TEXT_LENGTH),
            ],
        };
    }

    /**
     * The stored pictures a (sanitised) payload points at - gallery photos and sponsor logos.
     *
     * @param array<mixed> $content
     * @return list<string>
     */
    public static function imagePaths(array $content): array
    {
        $paths = [];

        foreach (['images' => 'path', 'sponsors' => 'logoPath'] as $list => $key) {
            $rows = $content[$list] ?? null;

            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (is_array($row) && is_string($row[$key] ?? null) && $row[$key] !== '') {
                    $paths[] = $row[$key];
                }
            }
        }

        return array_values(array_unique($paths));
    }

    public function html(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        // The editor separates words with non-breaking spaces (Quill 2's getSemanticHTML()) - they would stop the text
        // from wrapping on a phone
        $value = str_replace(["\u{00A0}", '&nbsp;', '&#160;'], ' ', $value);

        $html = trim($this->htmlSanitizer->sanitize($value));

        // An empty editor is "<p></p>" - nothing to show
        if (trim(strip_tags($html, '<img>')) === '') {
            return '';
        }

        return $html;
    }

    /**
     * The first MAX_IMAGES logos are kept, sponsors after them are listed without one.
     *
     * @param list<array<string, mixed>> $sponsors
     * @return list<array<string, mixed>>
     */
    private static function limitLogos(array $sponsors): array
    {
        $logos = 0;

        foreach ($sponsors as $index => $sponsor) {
            if (($sponsor['logoPath'] ?? null) !== null && ++$logos > self::MAX_IMAGES) {
                $sponsors[$index]['logoPath'] = null;
            }
        }

        return $sponsors;
    }

    /**
     * @param callable(array<mixed>): (null|array<string, mixed>) $cleanRow
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $rows, callable $cleanRow, int $maxRows = self::MAX_ROWS): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $clean = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $cleanedRow = $cleanRow($row);

            if ($cleanedRow !== null) {
                $clean[] = $cleanedRow;
            }

            if (count($clean) === $maxRows) {
                break;
            }
        }

        return $clean;
    }

    /**
     * Plain text - Twig escapes it when the page shows it.
     */
    private function text(mixed $value, int $maxLength = self::MAX_TEXT_LENGTH): string
    {
        if (!is_string($value)) {
            return '';
        }

        // Control characters other than line breaks and tabs never belong in a text
        $value = (string) preg_replace('/[^\P{Cc}\n\t]/u', '', str_replace("\r\n", "\n", $value));

        return mb_substr(trim($value), 0, $maxLength);
    }

    public function url(mixed $value): null|string
    {
        if (!is_string($value)) {
            return null;
        }

        $url = trim($value);

        if ($url === '' || mb_strlen($url) > self::MAX_URL_LENGTH) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }

    public function email(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $email = trim($value);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }

    /**
     * A picture uploaded for this page (UploadPageSectionImageController) - never an external URL, never another page's
     * file.
     */
    public function uploadPath(mixed $value, PageSectionOwner $owner): null|string
    {
        if (!is_string($value)) {
            return null;
        }

        $path = trim($value);
        $directory = $owner->uploadDirectory();

        if (!str_starts_with($path, $directory)) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,200}$/', substr($path, strlen($directory))) === 1 && !str_contains($path, '..')
            ? $path
            : null;
    }
}

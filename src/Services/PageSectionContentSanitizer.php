<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Value\PageSectionType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Server-side sanitization of manager-authored page section payloads — the
 * actual security boundary (the WYSIWYG's client-side rules are convenience).
 */
readonly final class PageSectionContentSanitizer
{
    public function __construct(
        #[Autowire(service: 'html_sanitizer.sanitizer.competition_page')]
        private HtmlSanitizerInterface $htmlSanitizer,
    ) {
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    public function sanitize(PageSectionType $type, array $content): array
    {
        return match ($type) {
            PageSectionType::RichText => [
                'html' => $this->html($content['html'] ?? null),
            ],
            PageSectionType::Faq => [
                'items' => array_values(array_filter(array_map(
                    fn (mixed $item): null|array => is_array($item) && trim($this->text($item['question'] ?? null)) !== ''
                        ? [
                            'question' => $this->text($item['question'] ?? null),
                            'answer' => $this->text($item['answer'] ?? null),
                        ]
                        : null,
                    is_array($content['items'] ?? null) ? $content['items'] : [],
                ))),
            ],
            PageSectionType::Gallery => [
                'images' => array_values(array_filter(array_map(
                    fn (mixed $item): null|array => is_array($item) && $this->uploadPath($item['path'] ?? null) !== null
                        ? [
                            'path' => $this->uploadPath($item['path'] ?? null),
                            'caption' => $this->text($item['caption'] ?? null),
                        ]
                        : null,
                    is_array($content['images'] ?? null) ? $content['images'] : [],
                ))),
            ],
            PageSectionType::Venue => [
                'address' => $this->text($content['address'] ?? null),
                'mapUrl' => $this->url($content['mapUrl'] ?? null),
                'directions' => $this->text($content['directions'] ?? null),
            ],
            PageSectionType::Sponsors => [
                'sponsors' => array_values(array_filter(array_map(
                    fn (mixed $item): null|array => is_array($item) && trim($this->text($item['name'] ?? null)) !== ''
                        ? [
                            'name' => $this->text($item['name'] ?? null),
                            'url' => $this->url($item['url'] ?? null),
                            'logoPath' => $this->uploadPath($item['logoPath'] ?? null),
                        ]
                        : null,
                    is_array($content['sponsors'] ?? null) ? $content['sponsors'] : [],
                ))),
            ],
            PageSectionType::Links => [
                'links' => array_values(array_filter(array_map(
                    fn (mixed $item): null|array => is_array($item) && $this->url($item['url'] ?? null) !== null
                        ? [
                            'label' => $this->text($item['label'] ?? null),
                            'url' => $this->url($item['url'] ?? null),
                        ]
                        : null,
                    is_array($content['links'] ?? null) ? $content['links'] : [],
                ))),
            ],
            PageSectionType::Contact => [
                'email' => $this->text($content['email'] ?? null),
                'phone' => $this->text($content['phone'] ?? null),
                'note' => $this->text($content['note'] ?? null),
            ],
        };
    }

    private function html(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return $this->htmlSanitizer->sanitize($value);
    }

    private function text(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return trim(strip_tags($value));
    }

    private function url(mixed $value): null|string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $url = trim($value);

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }

    /**
     * Uploaded images must be our own storage paths, never external URLs.
     */
    private function uploadPath(mixed $value): null|string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $path = trim($value);

        if (str_contains($path, '://') || str_starts_with($path, '//') || str_contains($path, '..')) {
            return null;
        }

        return str_starts_with($path, 'competition-pages/') ? $path : null;
    }
}

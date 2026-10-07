<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Entity\CompetitionPageSection;
use SpeedPuzzling\Web\Results\PageSectionSubmission;
use SpeedPuzzling\Web\Value\PageSectionOwner;
use SpeedPuzzling\Web\Value\PageSectionType;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads a section form (page_section_form.html.twig) and tells the organiser what would not be published: the form is
 * shown again with the reasons instead of silently dropping a link or a photo. The handler sanitises the payload again
 * (PageSectionContentSanitizer) - this is the explanation, not the security boundary.
 */
readonly final class PageSectionRequestParser
{
    public function __construct(
        private PageSectionContentSanitizer $sanitizer,
    ) {
    }

    public function parse(PageSectionType $type, Request $request, PageSectionOwner $owner): PageSectionSubmission
    {
        $errors = new PageSectionErrors();
        $title = trim($request->request->getString('title'));

        if (mb_strlen($title) > CompetitionPageSection::TITLE_MAX_LENGTH) {
            $errors->add('page_sections.error.title_too_long', ['%max%' => CompetitionPageSection::TITLE_MAX_LENGTH]);
        }

        $content = match ($type) {
            PageSectionType::RichText => $this->richText($request, $errors),
            PageSectionType::Faq => $this->faq($request, $errors),
            PageSectionType::Gallery => $this->gallery($request, $errors, $owner),
            PageSectionType::Venue => $this->venue($request, $errors),
            PageSectionType::Sponsors => $this->sponsors($request, $errors, $owner),
            PageSectionType::Links => $this->links($request, $errors),
            PageSectionType::Contact => $this->contact($request, $errors),
        };

        return new PageSectionSubmission($title, $content, $errors->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function richText(Request $request, PageSectionErrors $errors): array
    {
        $html = $request->request->getString('html');

        if (strlen($html) > PageSectionContentSanitizer::MAX_HTML_LENGTH) {
            $errors->add('page_sections.error.too_long', ['%max%' => PageSectionContentSanitizer::MAX_HTML_LENGTH]);

            // The sanitizer keeps the first MAX_HTML_LENGTH characters - the organiser shortens the rest
            return ['html' => $this->sanitizer->html($html)];
        }

        // Sanitised already here: a refused form puts the text back into the editor, never the raw submission
        $html = $this->sanitizer->html($html);

        if ($html === '') {
            $errors->add('page_sections.error.empty');
        }

        return ['html' => $html];
    }

    /**
     * @return array<string, mixed>
     */
    private function faq(Request $request, PageSectionErrors $errors): array
    {
        $items = $this->rows($request, 'items', ['question', 'answer'], $errors);

        foreach ($items as $item) {
            $this->limit($item['question'], PageSectionContentSanitizer::MAX_TEXT_LENGTH, $errors);
            $this->limit($item['answer'], PageSectionContentSanitizer::MAX_LONG_TEXT_LENGTH, $errors);

            if (trim($item['question']) === '') {
                $errors->add('page_sections.error.question_missing');
            }
        }

        if ($items === []) {
            $errors->add('page_sections.error.empty');
        }

        return ['items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    private function gallery(Request $request, PageSectionErrors $errors, PageSectionOwner $owner): array
    {
        $images = [];

        foreach ($this->rows($request, 'images', ['path', 'caption'], $errors) as $image) {
            $this->limit($image['caption'], PageSectionContentSanitizer::MAX_TEXT_LENGTH, $errors);
            // Only an upload of this page is kept - a row without one has nothing to show
            $image['path'] = $this->sanitizer->uploadPath($image['path'], $owner) ?? '';

            if ($image['path'] === '') {
                $errors->add('page_sections.error.photo_missing');
            }

            $images[] = $image;
        }

        if ($images === []) {
            $errors->add('page_sections.error.empty');
        }

        return ['images' => $images];
    }

    /**
     * @return array<string, mixed>
     */
    private function venue(Request $request, PageSectionErrors $errors): array
    {
        $venue = [
            'address' => $request->request->getString('address'),
            'mapUrl' => $request->request->getString('mapUrl'),
            'directions' => $request->request->getString('directions'),
        ];

        $this->limit($venue['address'], PageSectionContentSanitizer::MAX_TEXT_LENGTH, $errors);
        $this->limit($venue['directions'], PageSectionContentSanitizer::MAX_LONG_TEXT_LENGTH, $errors);
        $this->checkUrl($venue['mapUrl'], $errors);

        if (trim($venue['address']) === '' && trim($venue['mapUrl']) === '' && trim($venue['directions']) === '') {
            $errors->add('page_sections.error.empty');
        }

        return $venue;
    }

    /**
     * @return array<string, mixed>
     */
    private function sponsors(Request $request, PageSectionErrors $errors, PageSectionOwner $owner): array
    {
        $sponsors = [];

        foreach ($this->rows($request, 'sponsors', ['name', 'url', 'logoPath'], $errors) as $sponsor) {
            $this->limit($sponsor['name'], PageSectionContentSanitizer::MAX_TEXT_LENGTH, $errors);
            $this->checkUrl($sponsor['url'], $errors);
            $sponsor['logoPath'] = $this->sanitizer->uploadPath($sponsor['logoPath'], $owner) ?? '';

            if (trim($sponsor['name']) === '') {
                $errors->add('page_sections.error.sponsor_name_missing');
            }

            $sponsors[] = $sponsor;
        }

        if ($sponsors === []) {
            $errors->add('page_sections.error.empty');
        }

        return ['sponsors' => $sponsors];
    }

    /**
     * @return array<string, mixed>
     */
    private function links(Request $request, PageSectionErrors $errors): array
    {
        $links = $this->rows($request, 'links', ['label', 'url'], $errors);

        foreach ($links as $link) {
            $this->limit($link['label'], PageSectionContentSanitizer::MAX_TEXT_LENGTH, $errors);

            if (trim($link['url']) === '') {
                $errors->add('page_sections.error.url_missing');
            } else {
                $this->checkUrl($link['url'], $errors);
            }
        }

        if ($links === []) {
            $errors->add('page_sections.error.empty');
        }

        return ['links' => $links];
    }

    /**
     * @return array<string, mixed>
     */
    private function contact(Request $request, PageSectionErrors $errors): array
    {
        $contact = [
            'email' => $request->request->getString('email'),
            'phone' => $request->request->getString('phone'),
            'note' => $request->request->getString('note'),
        ];

        $this->limit($contact['phone'], PageSectionContentSanitizer::MAX_TEXT_LENGTH, $errors);
        $this->limit($contact['note'], PageSectionContentSanitizer::MAX_LONG_TEXT_LENGTH, $errors);

        if (trim($contact['email']) !== '' && $this->sanitizer->email($contact['email']) === '') {
            $errors->add('page_sections.error.invalid_email');
        }

        if (trim($contact['email']) === '' && trim($contact['phone']) === '' && trim($contact['note']) === '') {
            $errors->add('page_sections.error.empty');
        }

        return $contact;
    }

    /**
     * The submitted rows with every field a string, rows left completely blank dropped (the form starts with one).
     *
     * @param list<string> $fields
     * @return list<array<string, string>>
     */
    private function rows(Request $request, string $parameter, array $fields, PageSectionErrors $errors): array
    {
        $raw = $request->request->all()[$parameter] ?? [];

        if (!is_array($raw)) {
            return [];
        }

        $rows = [];

        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }

            $parsed = [];

            foreach ($fields as $field) {
                $value = $row[$field] ?? '';
                $parsed[$field] = is_string($value) ? $value : '';
            }

            if (trim(implode('', $parsed)) === '') {
                continue;
            }

            $rows[] = $parsed;
        }

        if (count($rows) > PageSectionContentSanitizer::MAX_ROWS) {
            $errors->add('page_sections.error.too_many_rows', ['%max%' => PageSectionContentSanitizer::MAX_ROWS]);
        }

        return $rows;
    }

    private function limit(string $value, int $maxLength, PageSectionErrors $errors): void
    {
        if (mb_strlen(trim($value)) > $maxLength) {
            $errors->add('page_sections.error.too_long', ['%max%' => $maxLength]);
        }
    }

    private function checkUrl(string $url, PageSectionErrors $errors): void
    {
        if (trim($url) !== '' && $this->sanitizer->url($url) === null) {
            $errors->add('page_sections.error.invalid_url');
        }
    }
}

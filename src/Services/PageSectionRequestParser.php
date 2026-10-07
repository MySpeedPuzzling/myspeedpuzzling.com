<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Value\PageSectionType;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the type-specific content payload from the section form submission.
 * Values are sanitized later in the message handlers.
 */
readonly final class PageSectionRequestParser
{
    /**
     * @return array<string, mixed>
     */
    public function parseContent(PageSectionType $type, Request $request): array
    {
        return match ($type) {
            PageSectionType::RichText => [
                'html' => $request->request->getString('html'),
            ],
            PageSectionType::Faq => [
                'items' => $this->rows($request, 'items', ['question', 'answer']),
            ],
            PageSectionType::Gallery => [
                'images' => $this->rows($request, 'images', ['path', 'caption']),
            ],
            PageSectionType::Venue => [
                'address' => $request->request->getString('address'),
                'mapUrl' => $request->request->getString('mapUrl'),
                'directions' => $request->request->getString('directions'),
            ],
            PageSectionType::Sponsors => [
                'sponsors' => $this->rows($request, 'sponsors', ['name', 'url', 'logoPath']),
            ],
            PageSectionType::Links => [
                'links' => $this->rows($request, 'links', ['label', 'url']),
            ],
            PageSectionType::Contact => [
                'email' => $request->request->getString('email'),
                'phone' => $request->request->getString('phone'),
                'note' => $request->request->getString('note'),
            ],
        };
    }

    /**
     * @param array<string> $fields
     * @return array<array<string, string>>
     */
    private function rows(Request $request, string $parameter, array $fields): array
    {
        /** @var array<mixed> $raw */
        $raw = $request->request->all($parameter);

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

            $rows[] = $parsed;
        }

        return $rows;
    }
}

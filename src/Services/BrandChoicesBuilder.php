<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;

readonly final class BrandChoicesBuilder
{
    public function __construct(
        private GetManufacturers $getManufacturers,
        private ImageThumbnailTwigExtension $imageThumbnail,
    ) {
    }

    /**
     * Every brand, approved or not - a brand missing here gets typed again and becomes a
     * duplicate (docs/features/brand-duplicates.md). `text` is the option's HTML; `name` is the
     * plain brand name the picker compares typed text against.
     *
     * @return array<array{value: string, text: string, name: string, eanPrefix: string}>
     */
    public function build(): array
    {
        $brandChoices = [];

        foreach ($this->getManufacturers->allIncludingUnapproved() as $manufacturer) {
            $img = '';
            if ($manufacturer->manufacturerLogo !== null) {
                $img = <<<HTML
<img alt="Logo" class="img-fluid rounded-2"
    style="max-width: 40px; max-height: 40px;"
    src="{$this->imageThumbnail->thumbnailUrl($manufacturer->manufacturerLogo, 'puzzle_small')}"
/>
HTML;
            }

            // Rendered as HTML, and the name is whatever a player typed
            $name = htmlspecialchars($manufacturer->manufacturerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $html = <<<HTML
<div class="py-1 d-flex low-line-height align-items-center">
    <div class="icon me-2">{$img}</div>
    <div class="pe-1">{$name} ({$manufacturer->puzzlesCount})</div>
</div>
HTML;

            $brandChoices[] = [
                'value' => $manufacturer->manufacturerId,
                'text' => $html,
                'name' => $manufacturer->manufacturerName,
                'eanPrefix' => $manufacturer->manufacturerEanPrefix ?? '',
            ];
        }

        return $brandChoices;
    }
}

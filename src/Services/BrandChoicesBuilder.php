<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use SpeedPuzzling\Web\Query\GetManufacturers;

readonly final class BrandChoicesBuilder
{
    public function __construct(
        private GetManufacturers $getManufacturers,
        private CacheManager $cacheManager,
    ) {
    }

    /**
     * @return array<array{value: string, text: string, eanPrefix: string}>
     */
    public function build(string $playerId, null|string $extraManufacturerId = null): array
    {
        $brandChoices = [];

        foreach ($this->getManufacturers->onlyApprovedOrAddedByPlayer($playerId, $extraManufacturerId) as $manufacturer) {
            $img = '';
            if ($manufacturer->manufacturerLogo !== null) {
                $img = <<<HTML
<img alt="Logo" class="img-fluid rounded-2"
    style="max-width: 40px; max-height: 40px;"
    src="{$this->cacheManager->getBrowserPath($manufacturer->manufacturerLogo, 'puzzle_small')}"
/>
HTML;
            }

            $html = <<<HTML
<div class="py-1 d-flex low-line-height align-items-center">
    <div class="icon me-2">{$img}</div>
    <div class="pe-1">{$manufacturer->manufacturerName} ({$manufacturer->puzzlesCount})</div>
</div>
HTML;

            $brandChoices[] = [
                'value' => $manufacturer->manufacturerId,
                'text' => $html,
                'eanPrefix' => $manufacturer->manufacturerEanPrefix ?? '',
            ];
        }

        return $brandChoices;
    }
}

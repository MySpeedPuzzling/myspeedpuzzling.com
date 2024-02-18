<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\GenerateInstagramLink;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;

final class LinksTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private GenerateInstagramLink $generateInstagramLink,
    ) {
    }

    /**
     * @return array<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('instagram', [$this, 'generateInstagramLink']),
        ];
    }

    public function generateInstagramLink(string $input): Markup
    {
        $link = $this->generateInstagramLink->fromUserInput($input);

        return new Markup("<a target='_blank' href='{$link->link}'>{$link->text}</a>", 'UTF-8');
    }
}

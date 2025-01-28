<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\SearchHighlighter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SearchHighlightTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private SearchHighlighter $highlighter,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('highlight', $this->highlighter->highlight(...), ['is_safe' => ['html']]),
        ];
    }
}

<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use Twig\Extension\AbstractExtension;
use Twig\NodeVisitor\NodeVisitorInterface;

final class ChromeWhitespaceTwigExtension extends AbstractExtension
{
    /**
     * @return array<NodeVisitorInterface>
     */
    public function getNodeVisitors(): array
    {
        return [new ChromeWhitespace()];
    }
}

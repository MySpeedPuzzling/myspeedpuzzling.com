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

    /**
     * Twig recompiles a cached template when an extension changed after it was compiled, but it looks only at the
     * extension's own file: the template list and the collapse live in the visitor's, so its change counts too -
     * otherwise var/cache/dev and var/cache/test keep serving the old output until a cache:clear.
     */
    public function getLastModified(): int
    {
        $visitor = (new \ReflectionClass(ChromeWhitespace::class))->getFileName();

        return max(parent::getLastModified(), is_string($visitor) && is_file($visitor) ? (int) filemtime($visitor) : 0);
    }
}

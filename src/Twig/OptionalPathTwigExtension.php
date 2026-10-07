<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `optional_path(name, parameters)` - the path of a route, or null while that route does not exist (yet) or does not
 * take these parameters. For a link to a page another part of the code adds, so a template can show the link once the
 * page is there (official_results/_referees_link.html.twig).
 */
final class OptionalPathTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('optional_path', $this->optionalPath(...)),
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function optionalPath(string $name, array $parameters = []): null|string
    {
        try {
            return $this->urlGenerator->generate($name, $parameters);
        } catch (RoutingException) {
            return null;
        }
    }
}

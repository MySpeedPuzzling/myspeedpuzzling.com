<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * add_entry_url(): where every "Add" of the navigation leads (docs/features/navigation README D7, ADD-03, decision 45) -
 * the phone tab bar's Add (NAV-01), the desktop "+ Add" (NAV-04) and the Menu's Add row (MENU-01):
 * - a guest: the Add explainer `add_intro` (the add page itself asks to sign in);
 * - on a puzzle page: the add page with that puzzle chosen;
 * - anywhere else: the add page.
 * Stateless and without a statement: the signed-in user is already on the token, the puzzle id in the route.
 */
final class AddEntryTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private Security $security,
        readonly private RequestStack $requestStack,
        readonly private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('add_entry_url', $this->addEntryUrl(...)),
        ];
    }

    public function addEntryUrl(): string
    {
        if ($this->security->getUser() === null) {
            return $this->urlGenerator->generate('add_intro');
        }

        $request = $this->requestStack->getMainRequest();
        $route = $request?->attributes->get('_canonical_route') ?? $request?->attributes->get('_route');
        $puzzleId = $request?->attributes->get('puzzleId');

        if ($route === 'puzzle_detail' && is_string($puzzleId) && $puzzleId !== '') {
            return $this->urlGenerator->generate('puzzle_add', ['puzzleId' => $puzzleId]);
        }

        return $this->urlGenerator->generate('puzzle_add');
    }
}

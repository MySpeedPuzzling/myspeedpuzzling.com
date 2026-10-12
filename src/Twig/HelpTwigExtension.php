<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Navigation\HelpPageContextFactory;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The Help sheet's openers (docs/features/navigation README §4.5, implementation plan §7):
 * - help_path(): `help` with ?url= of the page it is opened on (relative, so ReturnUrl accepts it), bare on `help`; on
 *   a page whose URL holds a private token, its token-free path instead (HelpPageContextFactory, HELP review Q2);
 * - help_opener_attrs(): what every opener carries besides its href - the sheet opens in the dynamic modal on the web
 *   (a plain visit inside the native app, layer_frame()), with its own scrim (HELP-05), never followed by crawlers -
 *   and data-help-opener (assets/help_openers.js): "page" = the ?url= follows the page as it is at the tap (review F4),
 *   "private" = it keeps the token-free path; on the help page itself an opener leads to the page's own box (review F5).
 */
final class HelpTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private RequestStack $requestStack,
        readonly private UrlGeneratorInterface $urlGenerator,
        readonly private PlatformTwigExtension $platformTwigExtension,
        readonly private HelpPageContextFactory $helpPageContextFactory,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('help_path', $this->helpPath(...)),
            new TwigFunction('help_opener_attrs', $this->helpOpenerAttributes(...), ['is_safe' => ['html']]),
        ];
    }

    public function helpPath(): string
    {
        $request = $this->requestStack->getMainRequest();
        $route = $request?->attributes->get('_canonical_route') ?? $request?->attributes->get('_route');

        if ($request === null || $route === 'help') {
            return $this->urlGenerator->generate('help');
        }

        $privateLinkPath = $this->helpPageContextFactory->privateLinkPathOfRequest($request);

        return $this->urlGenerator->generate('help', ['url' => $privateLinkPath ?? $request->getRequestUri()]);
    }

    public function helpOpenerAttributes(): string
    {
        $request = $this->requestStack->getMainRequest();
        $private = $request !== null && $this->helpPageContextFactory->privateLinkPathOfRequest($request) !== null;

        // layer_frame(): the sheet in modal-frame on the web, a plain visit to the `help` page inside the app
        return 'data-turbo-frame="' . $this->platformTwigExtension->layerFrame('modal-frame') . '" data-modal-scrim="own" rel="nofollow"'
            . ' data-help-opener="' . ($private ? 'private' : 'page') . '"';
    }
}

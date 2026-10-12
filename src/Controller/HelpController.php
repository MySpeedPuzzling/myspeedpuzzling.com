<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Navigation\HelpPageContextFactory;
use SpeedPuzzling\Web\Navigation\HelpSheetBuilder;
use SpeedPuzzling\Web\Services\PlatformDetector;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Help & feedback (docs/features/navigation README §4.5, implementation plan §7, HELP-01a): one public URL, two bodies.
 * A `Turbo-Frame: modal-frame` request (the ? / Feedback openers on the web) gets only the sheet's frame - never shared-
 * cached; anything else - no JS, a new tab, the native app - the full page, which stays shared-cacheable for guests.
 * ?url= names the page the sheet was opened on and is untrusted (HelpPageContextFactory).
 */
final class HelpController extends AbstractController
{
    public const string FRAME = 'modal-frame';

    public function __construct(
        readonly private HelpPageContextFactory $helpPageContextFactory,
        readonly private HelpSheetBuilder $helpSheetBuilder,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PlatformDetector $platformDetector,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/napoveda',
            'en' => '/en/help',
            'es' => '/es/ayuda',
            'ja' => '/ja/ヘルプ',
            'fr' => '/fr/aide',
            'de' => '/de/hilfe',
        ],
        name: 'help',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $url = $request->query->all()['url'] ?? null;
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        $parameters = [
            'context' => $this->helpPageContextFactory->fromUrlParameter(is_string($url) ? $url : null, $request),
            'rows' => $this->helpSheetBuilder->rows($viewer),
        ];

        // Inside the app every layer is a screen of its own: always the full page (README §4.14 (a))
        $isFrame = $request->headers->get('Turbo-Frame') === self::FRAME && !$this->platformDetector->isNativeApp();

        if ($isFrame) {
            $response = $this->render('help/_frame.html.twig', $parameters);
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');
        } else {
            // No explicit cache-control: AnonymousCacheHeadersSubscriber keeps the guest page shared-cacheable
            $response = $this->render('help/page.html.twig', $parameters);
        }

        // Same URL, two bodies: a cache must never hand the frame to a full-page visit or the other way round
        $response->setVary('Turbo-Frame', false);

        return $response;
    }
}

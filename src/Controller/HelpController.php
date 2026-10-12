<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Navigation\HelpPageContextFactory;
use SpeedPuzzling\Web\Navigation\HelpResponseBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Help & feedback (docs/features/navigation README §4.5, implementation plan §7, HELP-01a): one public URL, two bodies.
 * A `Turbo-Frame: modal-frame` request (the ? / Feedback openers on the web) gets only the sheet's frame - never shared-
 * cached; anything else - no JS, a new tab, the native app - the full page, which stays shared-cacheable for guests.
 * ?url= names the page the sheet was opened on and is untrusted (HelpPageContextFactory). Both bodies come from
 * HelpResponseBuilder, shared with the feedback box's endpoint (FeedbackController, HELP-01b).
 */
final class HelpController extends AbstractController
{
    public function __construct(
        readonly private HelpPageContextFactory $helpPageContextFactory,
        readonly private HelpResponseBuilder $helpResponseBuilder,
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

        return $this->helpResponseBuilder->response(
            $request,
            $this->helpPageContextFactory->fromUrlParameter(is_string($url) ? $url : null, $request),
        );
    }
}

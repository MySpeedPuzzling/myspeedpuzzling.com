<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The compare page (docs/features/player-comparison.md). Everything it shows is the Comparison live component's - the
 * URL is its state (kind, filters, `with` for a shared comparison, `swap` from the entry points), read by the
 * component's URL-mapped props. Personal and endless in its URL variants: never indexed, never shared-cached.
 */
final class ComparisonController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/{_locale}/compare',
        name: 'comparison',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(): Response
    {
        if ($this->retrieveLoggedUserProfile->getProfile() === null) {
            return $this->redirectToRoute('my_profile');
        }

        $response = $this->render('comparison.html.twig');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

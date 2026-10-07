<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Referees;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\CompetitionRefereesPage;
use SpeedPuzzling\Web\Services\NameTagQrCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The QR of the referees' link (live-results.md "Referees") as an SVG image - shown on the referees page for a
 * referee's phone camera and downloadable for a printed sheet. The content never changes for a competition and
 * language, so the browser keeps it a day.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CompetitionRefereesQrCodeController extends AbstractController
{
    public function __construct(
        private readonly NameTagQrCode $qrCode,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route(
        path: '/{_locale}/manage-event-referees/{competitionId}/qr-code.svg',
        name: 'competition_referees_qr_code',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $link = CompetitionRefereesPage::refereesLink($this->urlGenerator, strtolower($competitionId), $request->getLocale());
        $svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $this->qrCode->svg($link);

        $response = new Response($svg, Response::HTTP_OK, ['Content-Type' => 'image/svg+xml']);
        $response->setPrivate();
        $response->setMaxAge(86400);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}

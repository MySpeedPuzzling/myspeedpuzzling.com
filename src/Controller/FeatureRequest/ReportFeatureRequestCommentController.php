<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\FeatureRequest;

use SpeedPuzzling\Web\Message\ReportFeatureRequestComment;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReportFeatureRequestCommentController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/navrhy-funkci/nahlasit-komentar/{commentId}',
            'en' => '/en/feature-requests/report-comment/{commentId}',
            'es' => '/es/feature-requests/report-comment/{commentId}',
            'ja' => '/ja/feature-requests/report-comment/{commentId}',
            'fr' => '/fr/feature-requests/report-comment/{commentId}',
            'de' => '/de/feature-requests/report-comment/{commentId}',
        ],
        name: 'feature_request_report_comment',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(string $commentId, Request $request): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $this->messageBus->dispatch(new ReportFeatureRequestComment(
            reporterId: $loggedPlayer->playerId,
            commentId: $commentId,
        ));

        $this->addFlash('success', $this->translator->trans('feature_requests.report_submitted'));

        $referer = $request->headers->get('referer');
        if ($referer !== null) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('feature_requests');
    }
}

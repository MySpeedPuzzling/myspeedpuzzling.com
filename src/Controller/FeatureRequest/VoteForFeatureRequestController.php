<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\FeatureRequest;

use SpeedPuzzling\Web\Exceptions\AlreadyVotedForFeatureRequest;
use SpeedPuzzling\Web\Exceptions\VoteLimitReached;
use SpeedPuzzling\Web\Message\VoteForFeatureRequest;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class VoteForFeatureRequestController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/navrhy-funkci/{featureRequestId}/hlasovat',
            'en' => '/en/feature-requests/{featureRequestId}/vote',
            'es' => '/es/feature-requests/{featureRequestId}/vote',
            'ja' => '/ja/feature-requests/{featureRequestId}/vote',
            'fr' => '/fr/feature-requests/{featureRequestId}/vote',
            'de' => '/de/feature-requests/{featureRequestId}/vote',
        ],
        name: 'feature_request_vote',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(string $featureRequestId, Request $request): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $fallback = $this->generateUrl('feature_request_detail', ['featureRequestId' => $featureRequestId]);
        $redirectUrl = $request->headers->get('referer', $fallback);

        if ($loggedPlayer->activeMembership === false) {
            $this->addFlash('warning', $this->translator->trans('feature_requests.membership_required'));
            return $this->redirect($redirectUrl);
        }

        try {
            $this->messageBus->dispatch(new VoteForFeatureRequest(
                voterId: $loggedPlayer->playerId,
                featureRequestId: $featureRequestId,
            ));

            $this->addFlash('success', $this->translator->trans('feature_requests.vote_submitted'));
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();

            if ($previous instanceof AlreadyVotedForFeatureRequest) {
                $this->addFlash('warning', $this->translator->trans('feature_requests.already_voted'));
            } elseif ($previous instanceof VoteLimitReached) {
                $this->addFlash('warning', $this->translator->trans('feature_requests.vote_limit_reached'));
            } else {
                throw $e;
            }
        }

        return $this->redirect($redirectUrl);
    }
}

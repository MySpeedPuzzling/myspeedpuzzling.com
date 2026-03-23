<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\FeatureRequest;

use SpeedPuzzling\Web\Exceptions\FeatureRequestCanNotBeEdited;
use SpeedPuzzling\Web\Message\DeleteFeatureRequest;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DeleteFeatureRequestController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/navrhy-funkci/{featureRequestId}/smazat',
            'en' => '/en/feature-requests/{featureRequestId}/delete',
            'es' => '/es/feature-requests/{featureRequestId}/delete',
            'ja' => '/ja/feature-requests/{featureRequestId}/delete',
            'fr' => '/fr/feature-requests/{featureRequestId}/delete',
            'de' => '/de/feature-requests/{featureRequestId}/delete',
        ],
        name: 'feature_request_delete',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(string $featureRequestId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        try {
            $this->messageBus->dispatch(new DeleteFeatureRequest(
                featureRequestId: $featureRequestId,
                playerId: $loggedPlayer->playerId,
            ));

            $this->addFlash('success', $this->translator->trans('feature_requests.deleted_successfully'));
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof FeatureRequestCanNotBeEdited) {
                $this->addFlash('warning', $this->translator->trans('feature_requests.cannot_edit'));
            } else {
                throw $e;
            }
        }

        return $this->redirectToRoute('feature_requests');
    }
}

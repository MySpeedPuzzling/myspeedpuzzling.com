<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\FeatureRequest;

use SpeedPuzzling\Web\Exceptions\FeatureRequestCanNotBeEdited;
use SpeedPuzzling\Web\FormData\FeatureRequestFormData;
use SpeedPuzzling\Web\FormType\FeatureRequestFormType;
use SpeedPuzzling\Web\Message\EditFeatureRequest;
use SpeedPuzzling\Web\Query\GetFeatureRequestDetail;
use SpeedPuzzling\Web\Query\HasFeatureRequestExternalVotes;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EditFeatureRequestController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetFeatureRequestDetail $getFeatureRequestDetail,
        readonly private HasFeatureRequestExternalVotes $hasFeatureRequestExternalVotes,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/navrhy-funkci/{featureRequestId}/upravit',
            'en' => '/en/feature-requests/{featureRequestId}/edit',
            'es' => '/es/feature-requests/{featureRequestId}/edit',
            'ja' => '/ja/feature-requests/{featureRequestId}/edit',
            'fr' => '/fr/feature-requests/{featureRequestId}/edit',
            'de' => '/de/feature-requests/{featureRequestId}/edit',
        ],
        name: 'feature_request_edit',
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(string $featureRequestId, Request $request): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $featureRequest = $this->getFeatureRequestDetail->byId($featureRequestId);

        if ($featureRequest->authorId !== $loggedPlayer->playerId) {
            throw $this->createNotFoundException();
        }

        if (($this->hasFeatureRequestExternalVotes)($featureRequestId)) {
            $this->addFlash('warning', $this->translator->trans('feature_requests.cannot_edit'));
            return $this->redirectToRoute('feature_request_detail', ['featureRequestId' => $featureRequestId]);
        }

        $formData = new FeatureRequestFormData();
        $formData->title = $featureRequest->title;
        $formData->description = $featureRequest->description;

        $form = $this->createForm(FeatureRequestFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->messageBus->dispatch(new EditFeatureRequest(
                    featureRequestId: $featureRequestId,
                    playerId: $loggedPlayer->playerId,
                    title: $formData->title,
                    description: $formData->description,
                ));

                $this->addFlash('success', $this->translator->trans('feature_requests.edited_successfully'));

                return $this->redirectToRoute('feature_request_detail', ['featureRequestId' => $featureRequestId]);
            } catch (HandlerFailedException $e) {
                $previous = $e->getPrevious();
                if ($previous instanceof FeatureRequestCanNotBeEdited) {
                    $this->addFlash('warning', $this->translator->trans('feature_requests.cannot_edit'));
                    return $this->redirectToRoute('feature_request_detail', ['featureRequestId' => $featureRequestId]);
                }

                throw $e;
            }
        }

        return $this->render('feature_request/edit.html.twig', [
            'form' => $form->createView(),
            'feature_request' => $featureRequest,
        ]);
    }
}

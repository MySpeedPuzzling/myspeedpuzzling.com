<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\FeatureRequest;

use SpeedPuzzling\Web\Exceptions\FeatureRequestLimitReached;
use SpeedPuzzling\Web\FormData\FeatureRequestFormData;
use SpeedPuzzling\Web\FormType\FeatureRequestFormType;
use SpeedPuzzling\Web\Message\CreateFeatureRequest;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CreateFeatureRequestController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/novy-navrh',
            'en' => '/en/feature-requests/new',
            'es' => '/es/feature-requests/new',
            'ja' => '/ja/feature-requests/new',
            'fr' => '/fr/feature-requests/new',
            'de' => '/de/feature-requests/new',
        ],
        name: 'feature_request_create',
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        if ($loggedPlayer->activeMembership === false) {
            $this->addFlash('warning', $this->translator->trans('feature_requests.membership_required'));
            return $this->redirectToRoute('feature_requests');
        }

        $formData = new FeatureRequestFormData();
        $form = $this->createForm(FeatureRequestFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $envelope = $this->messageBus->dispatch(new CreateFeatureRequest(
                    authorId: $loggedPlayer->playerId,
                    title: $formData->title,
                    description: $formData->description,
                ));

                $handledStamp = $envelope->last(HandledStamp::class);
                $featureRequestId = $handledStamp?->getResult();

                $this->addFlash('success', $this->translator->trans('feature_requests.created_successfully'));

                if (is_string($featureRequestId)) {
                    return $this->redirectToRoute('feature_request_detail', ['featureRequestId' => $featureRequestId]);
                }

                return $this->redirectToRoute('feature_requests');
            } catch (HandlerFailedException $e) {
                $previous = $e->getPrevious();
                if ($previous instanceof FeatureRequestLimitReached) {
                    $this->addFlash('warning', $this->translator->trans('feature_requests.limit_reached'));
                    return $this->redirectToRoute('feature_requests');
                }

                throw $e;
            }
        }

        return $this->render('feature_request/create.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}

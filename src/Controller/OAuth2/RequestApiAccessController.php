<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OAuth2;

use Auth0\Symfony\Models\User;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\RequestApiAccessFormData;
use SpeedPuzzling\Web\FormType\RequestApiAccessFormType;
use SpeedPuzzling\Web\Message\RequestOAuth2ClientAccess;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class RequestApiAccessController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/pozadat-o-pristup-k-api',
            'en' => '/en/request-api-access',
            'es' => '/es/solicitar-acceso-api',
            'ja' => '/ja/APIアクセス申請',
            'fr' => '/fr/demander-acces-api',
            'de' => '/de/api-zugang-beantragen',
        ],
        name: 'request_api_access',
    )]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        if ($player->fairUsePolicyAccepted === false) {
            $this->addFlash('danger', $this->translator->trans('flashes.fair_use_policy_required'));

            return $this->redirectToRoute('fair_use_policy');
        }

        $formData = new RequestApiAccessFormData();
        $form = $this->createForm(RequestApiAccessFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            assert($formData->clientName !== null);
            assert($formData->clientDescription !== null);
            assert($formData->purpose !== null);

            $this->messageBus->dispatch(
                new RequestOAuth2ClientAccess(
                    requestId: Uuid::uuid7()->toString(),
                    playerId: $player->playerId,
                    clientName: $formData->clientName,
                    clientDescription: $formData->clientDescription,
                    purpose: $formData->purpose,
                    applicationType: $formData->applicationType,
                    requestedScopes: $formData->scopes,
                    redirectUris: $formData->getRedirectUrisAsArray(),
                ),
            );

            $this->addFlash('success', $this->translator->trans('flashes.oauth2_request_submitted'));

            return $this->redirectToRoute('edit_profile');
        }

        return $this->render('oauth2/request-api-access.html.twig', [
            'form' => $form,
        ]);
    }
}

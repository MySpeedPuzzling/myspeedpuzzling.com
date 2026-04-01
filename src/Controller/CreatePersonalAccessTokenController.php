<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\CreatePersonalAccessToken;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class CreatePersonalAccessTokenController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/vytvorit-pristupovy-token',
            'en' => '/en/create-personal-access-token',
            'es' => '/es/crear-token-de-acceso-personal',
            'ja' => '/ja/パーソナルアクセストークン作成',
            'fr' => '/fr/creer-jeton-acces-personnel',
            'de' => '/de/persoenlichen-zugangstoken-erstellen',
        ],
        name: 'create_personal_access_token',
        methods: ['POST'],
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

        $name = trim((string) $request->request->get('token_name', ''));

        if ($name === '') {
            $this->addFlash('danger', $this->translator->trans('flashes.pat_name_required'));

            return $this->redirectToRoute('edit_profile');
        }

        $tokenId = Uuid::uuid7()->toString();

        $envelope = $this->messageBus->dispatch(
            new CreatePersonalAccessToken(
                tokenId: $tokenId,
                playerId: $player->playerId,
                name: $name,
            ),
        );

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        $plainToken = $handledStamp->getResult();

        $this->addFlash('pat_created', $plainToken);

        return $this->redirectToRoute('edit_profile');
    }
}

<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Message\ApproveCompetition;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ApproveCompetitionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/admin/competitions/{competitionId}/approve',
        name: 'admin_approve_competition',
        methods: ['POST'],
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(string $competitionId): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        assert($profile !== null);

        $this->messageBus->dispatch(new ApproveCompetition(
            competitionId: $competitionId,
            approvedByPlayerId: $profile->playerId,
        ));

        $this->addFlash('success', $this->translator->trans('competition.flash.approved'));

        return $this->redirectToRoute('admin_competition_approvals');
    }
}

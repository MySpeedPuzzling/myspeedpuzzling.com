<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Message\RejectCompetition;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RejectCompetitionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/admin/competitions/{competitionId}/reject',
        name: 'admin_reject_competition',
        methods: ['POST'],
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        assert($profile !== null);

        $reason = trim((string) $request->request->get('reason', ''));

        if ($reason === '') {
            $this->addFlash('danger', $this->translator->trans('competition.flash.rejection_reason_required'));

            return $this->redirectToRoute('admin_competition_approvals');
        }

        $this->messageBus->dispatch(new RejectCompetition(
            competitionId: $competitionId,
            rejectedByPlayerId: $profile->playerId,
            reason: $reason,
        ));

        $this->addFlash('success', $this->translator->trans('competition.flash.rejected'));

        return $this->redirectToRoute('admin_competition_approvals');
    }
}

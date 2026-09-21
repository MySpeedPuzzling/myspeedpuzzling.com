<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetFreeTrialStatistics;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\FreeTrialSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class FreeTrialStatisticsController extends AbstractController
{
    public function __construct(
        private readonly GetFreeTrialStatistics $getFreeTrialStatistics,
        private readonly FreeTrialSettings $freeTrialSettings,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(path: '/admin/free-trial', name: 'admin_free_trial', methods: ['GET'])]
    public function __invoke(): Response
    {
        $now = $this->clock->now();

        return $this->render('admin/free_trial.html.twig', [
            'enabled' => $this->freeTrialSettings->isEnabled(),
            'modals' => $this->getFreeTrialStatistics->modalImpressions($now),
            'funnel' => $this->getFreeTrialStatistics->funnel($now),
        ]);
    }
}

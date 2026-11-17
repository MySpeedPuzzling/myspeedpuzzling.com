<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Query\GetEventClaimBlocks;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Players who can no longer claim spots of imported event results - removed by other people twice
 * (docs/features/event-results-import/README.md "Gates") - with the removals and "Allow claiming again".
 */
final class EventClaimBlocksController extends AbstractController
{
    public function __construct(
        readonly private GetEventClaimBlocks $getEventClaimBlocks,
    ) {
    }

    #[Route(path: '/admin/event-claim-blocks', name: 'admin_event_claim_blocks', methods: ['GET'])]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(): Response
    {
        return $this->render('admin/event_claim_blocks.html.twig', [
            'blocks' => $this->getEventClaimBlocks->all(),
            'removals_shown' => GetEventClaimBlocks::REMOVALS_SHOWN,
        ]);
    }
}

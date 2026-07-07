<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\ReorderPageSections;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ReorderPageSectionsController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/page-sections-reorder',
        name: 'reorder_page_sections',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);

        $competitionId = is_string($payload['competitionId'] ?? null) ? $payload['competitionId'] : null;
        $seriesId = is_string($payload['seriesId'] ?? null) ? $payload['seriesId'] : null;

        if (($competitionId === null) === ($seriesId === null)) {
            return new JsonResponse(['error' => 'Provide exactly one of competitionId or seriesId'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($competitionId !== null) {
            $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);
        } else {
            $this->denyAccessUnlessGranted(CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT, $seriesId);
        }

        $layout = [];

        foreach (is_array($payload['layout'] ?? null) ? $payload['layout'] : [] as $entry) {
            if (!is_array($entry) || !is_string($entry['section'] ?? null)) {
                continue;
            }

            $layout[] = [
                'section' => $entry['section'],
                'visible' => ($entry['visible'] ?? true) === true,
            ];
        }

        $this->messageBus->dispatch(new ReorderPageSections(
            competitionId: $competitionId,
            seriesId: $seriesId,
            layout: $layout,
        ));

        return new JsonResponse(['status' => 'ok']);
    }
}

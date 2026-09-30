<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\FirstTry;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Services\FirstTry\FirstTryFormCheck;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\FirstTryResolution;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The first-try notice of the add/edit time form, fetched while the player fills the form in
 * (first_try_check_controller.js) - the very partial a refused submit shows, so both say the same.
 * An empty answer means there is nothing to say.
 */
final class FirstTryCheckController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private FirstTryFormCheck $firstTryFormCheck,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
    ) {
    }

    #[Route(
        path: '/{_locale}/first-try-check',
        name: 'first_try_check',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($viewer === null) {
            return $this->notice(null);
        }

        $groupPlayers = array_values(array_filter(
            $request->query->all('group_players'),
            static fn(mixed $value): bool => is_string($value) && trim($value) !== '',
        ));
        $date = DateTimeImmutable::createFromFormat('!d.m.Y', $request->query->getString('date'));
        $solvedAt = $date !== false ? $date : null;
        $resolution = FirstTryResolution::tryFrom($request->query->getString('resolution')) ?? FirstTryResolution::None;
        $timeId = $request->query->getString('time');
        $puzzleId = $request->query->getString('puzzle');

        if ($timeId !== '') {
            if (Uuid::isValid($timeId) === false) {
                return $this->notice(null);
            }

            $time = $this->getPlayerSolvedPuzzles->byTimeId($timeId);

            if ($time->isEditableBy($viewer->playerId) === false) {
                return $this->notice(null);
            }

            $assessment = $this->firstTryFormCheck->forEditedResult($viewer->playerId, $time, $groupPlayers, $solvedAt);
        } elseif (Uuid::isValid($puzzleId)) {
            $assessment = $this->firstTryFormCheck->forNewResult($viewer->playerId, $puzzleId, $groupPlayers, $solvedAt);
        } else {
            return $this->notice(null);
        }

        return $this->notice($this->renderView('first_try/_notice.html.twig', [
            'assessment' => $assessment,
            'resolution' => $resolution->value,
        ]));
    }

    private function notice(null|string $html): Response
    {
        $response = new Response(trim($html ?? ''));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

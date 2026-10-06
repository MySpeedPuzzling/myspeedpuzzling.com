<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\FirstTry;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Services\FirstTry\FirstTryFormCheck;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Value\FirstTryResolution;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The first-try and "same time already saved" notice of the add/edit time form, fetched while the player fills
 * the form in (first_try_check_controller.js) - the very partial a refused submit shows, so both say the same.
 * An empty answer means there is nothing to say.
 *
 * On an edit (`time`) the `puzzle` counts only when the viewer tracked the result - only they may move it.
 * `first_attempt=0` = the tag is not ticked (no parameter = ticked, what the script sent before it checked
 * duplicates too); `seconds` = the time entered, `duplicate_confirmed=1` = "It's another solve" was chosen.
 */
final class FirstTryCheckController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private FirstTryFormCheck $firstTryFormCheck,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private SecretPuzzleAccess $secretPuzzleAccess,
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
        $firstAttempt = $request->query->getString('first_attempt', '1') !== '0';
        $seconds = $request->query->getInt('seconds');
        $secondsToSolve = $seconds > 0 ? $seconds : null;
        $duplicateConfirmed = $request->query->getString('duplicate_confirmed') === '1';
        $timeId = $request->query->getString('time');
        $puzzleId = $request->query->getString('puzzle');

        // A puzzle a competition keeps secret from this player is no puzzle to check against (SecretPuzzleAccess)
        if (Uuid::isValid($puzzleId) && $this->secretPuzzleAccess->isHiddenFromViewer($puzzleId)) {
            return $this->notice(null);
        }

        if ($timeId !== '') {
            if (Uuid::isValid($timeId) === false) {
                return $this->notice(null);
            }

            $time = $this->getPlayerSolvedPuzzles->byTimeId($timeId);

            if ($time->isEditableBy($viewer->playerId) === false) {
                return $this->notice(null);
            }

            // The tracker may have picked another puzzle for the result (docs/features/duplicate-results.md, Layer 4)
            $pickedPuzzleId = $time->playerId === $viewer->playerId && Uuid::isValid($puzzleId) ? $puzzleId : null;

            $check = $this->firstTryFormCheck->forEditedResult($viewer->playerId, $time, $groupPlayers, $solvedAt, $firstAttempt, $secondsToSolve, $pickedPuzzleId);
        } elseif (Uuid::isValid($puzzleId)) {
            $check = $this->firstTryFormCheck->forNewResult($viewer->playerId, $puzzleId, $groupPlayers, $solvedAt, $firstAttempt, $secondsToSolve);
        } else {
            return $this->notice(null);
        }

        return $this->notice($this->renderView('first_try/_notice.html.twig', [
            'assessment' => $check->firstTry,
            'resolution' => $resolution->value,
            'duplicates' => $check->duplicates,
            'duplicate_confirmed' => $duplicateConfirmed,
        ]));
    }

    private function notice(null|string $html): Response
    {
        $response = new Response(trim($html ?? ''));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

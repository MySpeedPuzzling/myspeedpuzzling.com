<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\MistypedYearNormalizer;
use SpeedPuzzling\Web\Services\UnfinishedResults\UnfinishedResultHint;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The add/edit-time form asks while it is filled in whether its competition + puzzle + solo/pair/team resolve to a
 * round with a time limit (docs/features/competitions-management/unfinished-results-plan.md, "The hint endpoint") -
 * only then it offers "I didn't finish within the time limit". Exactly the save's rule (UnfinishedResultHint).
 *
 * `competition` = the picker's value (`<uuid>`, `edition:<uuid>`, `series:<uuid>`), `puzzle` = an existing puzzle's
 * uuid, `puzzlers` = how many people puzzled (the player included: 1 solo, 2 pair, more a team), `date` = the form's
 * day (d.m.Y, a series pick's edition is found by it). Anything that does not resolve - garbage included - answers
 * `{"round": null}`, never an error. The answer names a round and its limit (public on the event page), never anything
 * about the puzzle; it is the viewer's (a puzzle kept secret answers null), so it is never cached.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class SolvingTimeRoundHintController extends AbstractController
{
    public function __construct(
        readonly private UnfinishedResultHint $unfinishedResultHint,
        readonly private MistypedYearNormalizer $mistypedYearNormalizer,
    ) {
    }

    #[Route(
        path: '/{_locale}/solving-time-round-hint',
        name: 'solving_time_round_hint',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $puzzlers = $request->query->getString('puzzlers');

        $round = $this->unfinishedResultHint->forPick(
            $request->query->getString('competition'),
            $request->query->getString('puzzle'),
            ctype_digit($puzzlers) && strlen($puzzlers) < 4 ? max(1, (int) $puzzlers) : 1,
            $this->solveDay($request->query->getString('date')),
        );

        $response = new JsonResponse(['round' => $round?->toArray()]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * The day the save would use: the form's date (repaired like the handler repairs it), else today (null)
     */
    private function solveDay(string $date): null|DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!d.m.Y', trim($date));

        if ($parsed === false) {
            return null;
        }

        return $this->mistypedYearNormalizer->normalizeFinishedAt($parsed);
    }
}

<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;
use SpeedPuzzling\Web\Query\GetRoundStopwatchState;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\RoundStopwatchLiveUpdates;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The round stopwatch's actions - start, stop, reset, schedule a start, cancel it (docs/features/competitions-management/
 * README.md "Round Stopwatch"): POST forms of the stopwatch control page and the projector page, organisers only
 * (COMPETITION_EDIT).
 *
 * - Every form carries the stateless CSRF token CSRF_TOKEN_ID (config/packages/csrf.php): the pages stay open for hours
 *   on a venue laptop, and the projector page is public.
 * - The open pages are told after the dispatch returned - after the commit (RoundStopwatchLiveUpdates), never from a
 *   handler.
 * - A refusal (RoundStopwatchRefused) changes nothing and is said: a flash on the control page.
 * - Asked with `Accept: application/json` (the online control room) the answer is `{"stopwatch": RoundStopwatchState}`,
 *   or `{"error": "<code>"}` - `invalid_csrf` (403) or a RoundStopwatchRefusal value (409) - `private, no-store`.
 */
abstract class AbstractRoundStopwatchController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'round_stopwatch';

    public function __construct(
        readonly private CompetitionRoundRepository $competitionRoundRepository,
        readonly private MessageBusInterface $messageBus,
        readonly private RoundStopwatchLiveUpdates $liveUpdates,
        readonly private GetRoundStopwatchState $getRoundStopwatchState,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private ClockInterface $clock,
    ) {
    }

    /**
     * @param \Closure(CompetitionRound): object $message the message to dispatch - may throw RoundStopwatchRefused
     *                                                    for what the request asks (a start that is no moment)
     */
    protected function act(Request $request, string $roundId, \Closure $message, string $successRoute): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $round->competition->id->toString());

        $roundId = $round->id->toString();
        $wantsJson = str_contains((string) $request->headers->get('Accept'), 'application/json');

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            if ($wantsJson) {
                return self::privateJson(['error' => 'invalid_csrf'], Response::HTTP_FORBIDDEN);
            }

            $this->addFlash('warning', $this->translator->trans('competition.stopwatch.flash.expired'));

            return $this->redirectToRoute('manage_round_stopwatch', ['roundId' => $roundId], Response::HTTP_SEE_OTHER);
        }

        try {
            $this->messageBus->dispatch($message($round));
        } catch (RoundStopwatchRefused $refused) {
            if ($wantsJson) {
                return self::privateJson(['error' => $refused->reason->value], Response::HTTP_CONFLICT);
            }

            $this->addFlash('warning', $this->translator->trans($refused->reason->translationKey()));

            return $this->redirectToRoute('manage_round_stopwatch', ['roundId' => $roundId], Response::HTTP_SEE_OTHER);
        }

        // After the commit - a rolled back write never reaches a page
        $state = $this->liveUpdates->stopwatchChanged($roundId);

        if ($wantsJson) {
            return self::privateJson(['stopwatch' => $state ?? $this->getRoundStopwatchState->forRound($roundId)]);
        }

        return $this->redirectToRoute($successRoute, ['roundId' => $roundId], Response::HTTP_SEE_OTHER);
    }

    protected function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    protected function actingPlayerId(): string
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        // COMPETITION_EDIT is granted to players only
        assert($profile !== null);

        return $profile->playerId;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function privateJson(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

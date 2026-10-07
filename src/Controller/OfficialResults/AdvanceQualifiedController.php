<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\AdvancementPlanChanged;
use SpeedPuzzling\Web\Exceptions\InvalidAdvancement;
use SpeedPuzzling\Web\Message\AdvanceQualified;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Results\AdvancementPlan;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Value\AdvanceDistribution;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Advance the qualified (AdvanceQualified): `{"sourceRoundIds": [...], "targetRoundIds": [...], "distribution":
 * "single"|"balanced"|"by_source", "targetBySource": {"<source>": "<target>"}, "dryRun": true}` → the plan with its
 * `planHash`; the same body with `"dryRun": false, "planHash": "..."` applies exactly that plan - 409 `plan_changed`
 * when anything changed meanwhile, 422 `invalid_advancement` with a `reason` for an impossible request.
 */
final class AdvanceQualifiedController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly OfficialResultsApi $api,
        private readonly OfficialResultsLiveUpdates $liveUpdates,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/official-results/competitions/{competitionId}/advance',
        name: 'official_results_advance',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId): JsonResponse
    {
        $competition = $this->competitionRepository->get($competitionId);
        $competitionId = $competition->id->toString();
        $authorised = $this->api->authorise($request, $competitionId, write: true);

        if ($authorised instanceof JsonResponse) {
            return $authorised;
        }

        $body = OfficialResultsApi::body($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $sourceRoundIds = self::ids($body['sourceRoundIds'] ?? null);
        $targetRoundIds = self::ids($body['targetRoundIds'] ?? null);
        $distribution = is_string($body['distribution'] ?? null) ? AdvanceDistribution::tryFrom($body['distribution']) : null;
        $targetBySource = $body['targetBySource'] ?? [];
        $planHash = $body['planHash'] ?? null;

        if ($sourceRoundIds === null || $targetRoundIds === null || $distribution === null || !is_array($targetBySource) || !($planHash === null || is_string($planHash))) {
            return OfficialResultsApi::error('invalid_advancement_request', JsonResponse::HTTP_BAD_REQUEST);
        }

        $map = [];
        foreach ($targetBySource as $sourceId => $targetId) {
            if (!is_string($targetId)) {
                return OfficialResultsApi::error('invalid_advancement_request', JsonResponse::HTTP_BAD_REQUEST);
            }

            $map[(string) $sourceId] = $targetId;
        }

        try {
            $envelope = $this->messageBus->dispatch(new AdvanceQualified(
                competitionId: $competitionId,
                sourceRoundIds: $sourceRoundIds,
                targetRoundIds: $targetRoundIds,
                distribution: $distribution,
                targetBySource: $map,
                dryRun: ($body['dryRun'] ?? true) !== false,
                planHash: $planHash,
            ));
        } catch (InvalidAdvancement $invalid) {
            return OfficialResultsApi::error('invalid_advancement', JsonResponse::HTTP_UNPROCESSABLE_ENTITY, [
                'reason' => $invalid->reason,
                'message' => $this->translator->trans('official_results.advance.error.' . $invalid->reason),
            ]);
        } catch (AdvancementPlanChanged) {
            return OfficialResultsApi::error('plan_changed', JsonResponse::HTTP_CONFLICT, [
                'message' => $this->translator->trans('official_results.advance.error.plan_changed'),
            ]);
        }

        $plan = $envelope->last(HandledStamp::class)?->getResult();
        assert($plan instanceof AdvancementPlan);

        if ($plan->applied) {
            $createdByRound = [];
            foreach ($plan->assignments as $assignment) {
                if ($assignment->createdEntryRef !== null) {
                    $createdByRound[$assignment->targetRoundId][] = $assignment->createdEntryRef;
                }
            }

            foreach ($createdByRound as $roundId => $refs) {
                $this->liveUpdates->entriesChanged($roundId, $refs);
            }
        }

        return OfficialResultsApi::json([
            ...$plan->jsonSerialize(),
            'skipped' => array_map(fn (array $skip): array => [
                ...$skip,
                'message' => $this->translator->trans('official_results.advance.skip.' . $skip['reason']),
            ], $plan->skipped),
        ]);
    }

    /**
     * @return null|list<string>
     */
    private static function ids(mixed $value): null|array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            return null;
        }

        foreach ($value as $id) {
            if (!is_string($id)) {
                return null;
            }
        }

        /** @var list<string> $value */
        return $value;
    }
}

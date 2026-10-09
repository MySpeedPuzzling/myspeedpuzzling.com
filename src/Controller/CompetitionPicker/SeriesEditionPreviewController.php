<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionPicker;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetSeriesEditionChoices;
use SpeedPuzzling\Web\Results\SeriesEditionChoice;
use SpeedPuzzling\Web\Services\CompetitionChoicesBuilder;
use SpeedPuzzling\Web\Services\MistypedYearNormalizer;
use SpeedPuzzling\Web\Services\SeriesEditions\SeriesEditionResolver;
use SpeedPuzzling\Web\Value\PuzzlingType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The line under the add-time picker once a series (or an edition) is chosen (docs/features/events-page/
 * high-frequency-series.md "The preview line and S2") - an HTML fragment series_edition_preview_controller.js puts
 * under the picker. Changes nothing.
 *
 * `series=<uuid>`: what a save would match - the one rule (SeriesEditionResolver::preview()) with the form's inputs:
 * `puzzle` (an existing puzzle's uuid - only the rule reads it, never echoed: a puzzle a round still keeps secret
 * matches nothing, so the answer looks exactly like one without a puzzle), `date` (d.m.Y, empty = today, mistyped
 * years repaired like the save does), `people` (the co-puzzlers added: 0 solo, 1 pair, 2+ team - what the save
 * stores, P14). Matched: the line + "change" opening the short list. Not identified (S2): the short list openly,
 * nothing preselected; a series without publicly visible editions (or one that is not public): the neutral line only.
 *
 * `edition=<uuid>`: an explicitly picked edition - its line and "Let MySpeedPuzzling match it". Nothing for an edition
 * that is not publicly visible.
 *
 * `part=list` (+ `series`, `q`): only the short list, narrowed to editions whose name or revealed round puzzle names
 * hold every typed word.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class SeriesEditionPreviewController extends AbstractController
{
    public function __construct(
        readonly private SeriesEditionResolver $seriesEditionResolver,
        readonly private GetSeriesEditionChoices $getSeriesEditionChoices,
        readonly private CompetitionChoicesBuilder $competitionChoicesBuilder,
        readonly private MistypedYearNormalizer $mistypedYearNormalizer,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(
        path: '/{_locale}/competition-picker/series-preview',
        name: 'competition_picker_series_preview',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $seriesId = $request->query->getString('series');
        $editionId = $request->query->getString('edition');
        $day = $this->solveDay($request->query->getString('date'));
        $category = PuzzlingType::fromPuzzlersCount(max(0, $request->query->getInt('people')) + 1);

        if (Uuid::isValid($editionId)) {
            return $this->explicitEdition(strtolower($editionId), $day);
        }

        if (Uuid::isValid($seriesId) === false) {
            return $this->fragment(null);
        }

        $seriesId = strtolower($seriesId);

        if ($request->query->getString('part') === 'list') {
            $query = trim($request->query->getString('q'));
            $editions = $this->getSeriesEditionChoices->closest($seriesId, $day, $query !== '' ? $query : null);

            return $this->fragment($this->renderView('competition_picker/_edition_list.html.twig', [
                'editions' => $this->listItems($editions),
                'searched' => $query !== '',
            ]));
        }

        $puzzleId = $request->query->getString('puzzle');
        $resolution = $this->seriesEditionResolver->preview($seriesId, Uuid::isValid($puzzleId) ? $puzzleId : null, $day, $category);
        $editions = $this->getSeriesEditionChoices->closest($seriesId, $day, alwaysIncludeId: $resolution->competitionId);
        $matched = $resolution->isIdentified() ? self::find($editions, $resolution->competitionId) : null;

        return $this->fragment($this->renderView('competition_picker/_series_preview.html.twig', [
            'series_id' => $seriesId,
            'matched' => $matched,
            'category' => $category,
            'editions' => $this->listItems($editions),
        ]));
    }

    private function explicitEdition(string $editionId, DateTimeImmutable $day): Response
    {
        $seriesId = $this->getSeriesEditionChoices->seriesOfSelectableEdition($editionId);

        if ($seriesId === null) {
            return $this->fragment(null);
        }

        $editions = $this->getSeriesEditionChoices->closest($seriesId, $day, alwaysIncludeId: $editionId);
        $picked = self::find($editions, $editionId);

        if ($picked === null) {
            return $this->fragment(null);
        }

        return $this->fragment($this->renderView('competition_picker/_series_preview.html.twig', [
            'series_id' => $seriesId,
            'picked' => $picked,
            'editions' => $this->listItems($editions),
        ]));
    }

    /**
     * The day the save would use: the form's date (repaired like the handler repairs it), else today
     */
    private function solveDay(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!d.m.Y', trim($date));

        if ($parsed === false) {
            return $this->clock->now();
        }

        return $this->mistypedYearNormalizer->normalizeFinishedAt($parsed) ?? $this->clock->now();
    }

    /**
     * Each edition with its TomSelect option and optgroup - a pick adds them to the picker
     *
     * @param list<SeriesEditionChoice> $editions
     * @return list<array{edition: SeriesEditionChoice, option: string, optgroup: string}>
     */
    private function listItems(array $editions): array
    {
        return array_map(fn (SeriesEditionChoice $edition): array => [
            'edition' => $edition,
            'option' => json_encode($this->competitionChoicesBuilder->editionOption($edition), JSON_THROW_ON_ERROR),
            'optgroup' => json_encode($this->competitionChoicesBuilder->editionOptgroup($edition), JSON_THROW_ON_ERROR),
        ], $editions);
    }

    /**
     * @param list<SeriesEditionChoice> $editions
     */
    private static function find(array $editions, null|string $editionId): null|SeriesEditionChoice
    {
        foreach ($editions as $edition) {
            if ($edition->id === $editionId) {
                return $edition;
            }
        }

        return null;
    }

    private function fragment(null|string $html): Response
    {
        $response = new Response(trim($html ?? ''));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

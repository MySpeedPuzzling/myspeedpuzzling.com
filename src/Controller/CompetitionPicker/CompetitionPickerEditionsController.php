<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionPicker;

use SpeedPuzzling\Web\Query\GetSeriesEditionChoices;
use SpeedPuzzling\Web\Services\CompetitionChoicesBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * S1 of the add-time "Competition / event" picker (docs/features/events-page/high-frequency-series.md "S1 - typing
 * finds editions"): from two typed characters TomSelect asks here for the editions whose name, series name or series
 * shortcut hold every typed word - at most 20 publicly visible ones, nearest to today first. Editions are never baked
 * into the form; competition_picker_controller.js adds these as options under their series and removes them again.
 *
 * Answers `{"options": [{value, text, keywords, optgroup}], "optgroups": [{value, label, logo?}]}` - the cards the
 * picker renders as they are (escaped by CompetitionChoicesBuilder). Changes nothing.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CompetitionPickerEditionsController extends AbstractController
{
    public function __construct(
        readonly private GetSeriesEditionChoices $getSeriesEditionChoices,
        readonly private CompetitionChoicesBuilder $competitionChoicesBuilder,
    ) {
    }

    #[Route(
        path: '/{_locale}/competition-picker/editions',
        name: 'competition_picker_editions',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $query = trim($request->query->getString('q'));

        $payload = mb_strlen($query) < GetSeriesEditionChoices::MIN_SEARCH_LENGTH
            ? ['options' => [], 'optgroups' => []]
            : $this->competitionChoicesBuilder->editionsPayload($this->getSeriesEditionChoices->search($query));

        $response = new JsonResponse($payload);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\MyData;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetExportableCollections;
use SpeedPuzzling\Web\Query\GetMyDataColumns;
use SpeedPuzzling\Web\Query\GetMyDataPuzzles;
use SpeedPuzzling\Web\Query\GetMyDataResults;
use SpeedPuzzling\Web\Results\MyData\MyDataOwnFields;
use SpeedPuzzling\Web\Results\MyData\MyDataPuzzleRow;
use SpeedPuzzling\Web\Results\MyData\MyDataResultRow;
use SpeedPuzzling\Web\Services\MyData\OwnFieldsInMyData;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\MyDataCriteria;
use SpeedPuzzling\Web\Value\MyDataTab;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "My data" (docs/features/my-data.md): the signed-in player's own results, the puzzles of their collections and
 * their wish list as a table - read-only. Nobody else's data is ever here: there is no player id in the address,
 * every read takes the signed-in player's id. The address is the state (MyDataCriteria).
 */
final class MyDataController extends AbstractController
{
    public const string LOCALES = 'en|cs|de|es|fr|ja';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetMyDataResults $getMyDataResults,
        readonly private GetMyDataPuzzles $getMyDataPuzzles,
        readonly private GetMyDataColumns $getMyDataColumns,
        readonly private GetExportableCollections $getExportableCollections,
        readonly private OwnFieldsInMyData $ownFieldsInMyData,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(
        path: '/{_locale}/my-data',
        name: 'my_data',
        requirements: ['_locale' => self::LOCALES],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $criteria = MyDataCriteria::fromUserInput($request->query->all());
        $tab = $criteria->tab;

        if ($tab === MyDataTab::Results) {
            $facts = $this->getMyDataResults->facts($player->playerId);
            $page = $this->getMyDataResults->page($player->playerId, $criteria);
            $ownFields = $this->ownFieldsInMyData->onResultsOfSignedInViewer(
                array_map(static fn (MyDataResultRow $row): string => $row->timeId, $page->rows),
            );
            $thisYear = (int) $this->clock->now()->format('Y');

            $parameters = [
                'all_rows' => $facts['total'],
                // The years the filter takes (MyDataCriteria) - never one it would silently drop
                'years' => $facts['oldestYear'] !== null
                    ? range($thisYear, max(MyDataCriteria::MIN_YEAR, min($thisYear, $facts['oldestYear'])))
                    : [],
            ];
        } else {
            $facts = $this->getMyDataPuzzles->facts($player->playerId);
            $page = $this->getMyDataPuzzles->page($player->playerId, $criteria);
            $ownFields = $tab === MyDataTab::Puzzles
                ? $this->ownFieldsInMyData->onCollectionItemsOfSignedInViewer(
                    array_map(static fn (MyDataPuzzleRow $row): string => $row->itemId, $page->rows),
                )
                : new MyDataOwnFields();

            $parameters = [
                'all_rows' => $tab === MyDataTab::Puzzles ? $facts['collectionItems'] : $facts['wishList'],
                'collections' => $tab === MyDataTab::Puzzles ? $this->getExportableCollections->byPlayerId($player->playerId) : [],
            ];
        }

        $columns = $this->getMyDataColumns->ofPlayer($player->playerId);
        $shown = [];

        foreach ([...$tab->columns(), ...array_keys($ownFields->columns)] as $column) {
            $shown[$column] = $columns->shows($tab, $column);
        }

        $response = $this->render('my_data/index.html.twig', [
            ...$parameters,
            'player' => $player,
            // The page the rows are really of (a page behind the last row shows the first one)
            'criteria' => $criteria->onPage($page->page),
            'page' => $page,
            'own_fields' => $ownFields,
            // column id => whether it is shown: the built-in columns of the tab, then the player's own fields
            'columns' => $shown,
        ]);

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}

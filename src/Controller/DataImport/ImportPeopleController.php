<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Query\GetImportPeople;
use SpeedPuzzling\Web\Results\ImportPersonCard;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "People in your file" (docs/features/data-import/people.md): every distinct person the import's results name,
 * with the number of rows and two of them, who each is - a player for a code of the file, a guest for a name unless
 * the importing player says who it is - and, for a name, the players of the importing player's own people that
 * could be that person. Nothing is decided by opening the page.
 *
 * Another player's import is a 404. A fixed number of statements whatever the number of people.
 */
final class ImportPeopleController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportPeople $getImportPeople,
        readonly private GetImportAddScreen $getImportAddScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/people',
        name: 'data_import_people',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $importId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        return $this->render('data_import/people.html.twig', $this->page($importId, $player->playerId));
    }

    /**
     * What the page shows - also for the answer that could not be taken (DecideImportPersonController, a 422).
     *
     * @return array<string, mixed>
     */
    public function page(string $importId, string $playerId, null|string $refusedPersonId = null, null|string $refusal = null): array
    {
        $import = $this->getDataImports->byIdOfPlayer($importId, $playerId);
        $people = $this->getImportPeople->ofImport($importId, $playerId);
        $exampleIds = array_merge(...array_map(static fn (ImportPersonCard $person): array => $person->exampleRowIds, $people), ...[[]]);

        return [
            'import' => $import,
            'people' => $people,
            'examples' => $this->getImportAddScreen->rowsByIds($importId, $playerId, array_values(array_unique($exampleIds))),
            'rows_added' => $this->getImportAddScreen->summary($importId, $playerId)->rowsAdded,
            'refused_person_id' => $refusedPersonId,
            'refusal' => $refusal,
        ];
    }
}

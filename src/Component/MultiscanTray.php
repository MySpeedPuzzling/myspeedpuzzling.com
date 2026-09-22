<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Exceptions\CannotLendToSelf;
use SpeedPuzzling\Web\Exceptions\CollectionAlreadyExists;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Exceptions\EanAlreadyAssigned;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Exceptions\MultiscanBatchRejected;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzlesToCollection;
use SpeedPuzzling\Web\Message\AddPuzzlesToWishList;
use SpeedPuzzling\Web\Message\BorrowPuzzlesFromPlayer;
use SpeedPuzzling\Web\Message\CreateCollection;
use SpeedPuzzling\Web\Message\LendPuzzlesToPlayer;
use SpeedPuzzling\Web\Message\LinkEanToPuzzle;
use SpeedPuzzling\Web\Message\ReturnLentPuzzles;
use SpeedPuzzling\Web\Query\FindPuzzlesByExactEan;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetLendBorrowCounterparties;
use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Query\GetMultiscanCandidates;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Results\CollectionOverview;
use SpeedPuzzling\Web\Results\LendBorrowCounterparty;
use SpeedPuzzling\Web\Results\ManufacturerOverview;
use SpeedPuzzling\Web\Results\MultiscanEligibilityReport;
use SpeedPuzzling\Web\Results\MultiscanRow;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Results\UserPuzzleStatuses;
use SpeedPuzzling\Web\Services\LendBorrowParticipantParser;
use SpeedPuzzling\Web\Services\MultiscanEligibility;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use SpeedPuzzling\Web\Value\Ean;
use SpeedPuzzling\Web\Value\MultiscanAction;
use SpeedPuzzling\Web\Value\PiecesRange;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The multiscan tray: rows scanned from boxes, one batch action, the "not
 * found" resolve sheet. The camera lives outside this component
 * (templates/multiscan/_scanner.html.twig) and talks to it through the
 * `multiscan` Stimulus bridge. docs/features/multiscan/README.md
 */
#[AsLiveComponent]
final class MultiscanTray
{
    use DefaultActionTrait;

    public const int MAX_ROWS = 50;
    private const int SEARCH_LIMIT = 8;

    /**
     * @var list<array{ean: string, puzzleId: null|string, state: string, candidateIds: list<string>}>
     */
    #[LiveProp]
    public array $rows = [];

    #[LiveProp(writable: true)]
    public string $action = 'add_to_library';

    #[LiveProp(writable: true)]
    public string $collectionId = Collection::SYSTEM_ID;

    #[LiveProp(writable: true)]
    public string $person = '';

    #[LiveProp(writable: true)]
    public string $notes = '';

    /**
     * What just happened to the last scan, for the bridge (feedback + toast); cleared on the next action.
     *
     * @var null|array{type: string, ean: string, name: null|string}
     */
    #[LiveProp]
    public null|array $notice = null;

    /** Bumped with every new notice so the bridge reacts to each one exactly once (re-renders keep the notice) */
    #[LiveProp]
    public int $noticeSeq = 0;

    /**
     * @var null|array{action: string, puzzleIds: list<string>, person: null|string, names: array<string, string>}
     */
    #[LiveProp]
    public null|array $recap = null;

    #[LiveProp]
    public null|string $error = null;

    /** @var array<string, string> */
    #[LiveProp]
    public array $errorParams = [];

    // --- resolve sheet ("not found") ---

    #[LiveProp]
    public null|string $resolvingEan = null;

    #[LiveProp(writable: true)]
    public string $resolveQuery = '';

    #[LiveProp]
    public null|string $resolveBrandId = null;

    #[LiveProp]
    public null|string $resolveBrandName = null;

    #[LiveProp]
    public bool $quickAddOpen = false;

    #[LiveProp(writable: true)]
    public string $newName = '';

    #[LiveProp(writable: true)]
    public string $newPiecesCount = '';

    #[LiveProp(writable: true)]
    public string $newBrand = '';

    #[LiveProp(writable: true)]
    public string $newBrandName = '';

    #[LiveProp]
    public null|string $resolveError = null;

    /** @var null|list<MultiscanRow> */
    private null|array $hydratedRows = null;

    /** @var null|array<string, PuzzleOverview> */
    private null|array $recapPuzzles = null;

    /** @var null|list<CollectionOverview> */
    private null|array $collections = null;

    /** @var null|list<ManufacturerOverview> */
    private null|array $manufacturers = null;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetMultiscanCandidates $getMultiscanCandidates,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private GetPlayerCollections $getPlayerCollections,
        readonly private GetManufacturers $getManufacturers,
        readonly private GetLendBorrowCounterparties $getLendBorrowCounterparties,
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private SearchPuzzle $searchPuzzle,
        readonly private FindPuzzlesByExactEan $findPuzzlesByExactEan,
        readonly private MultiscanEligibility $eligibility,
        readonly private LendBorrowParticipantParser $participantParser,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    public function mount(null|string $presetAction = null, null|string $presetCollectionId = null): void
    {
        $action = $presetAction !== null ? MultiscanAction::tryFrom($presetAction) : null;

        if ($action !== null) {
            $this->action = $action->value;
        }

        if ($presetCollectionId !== null && $this->collectionBelongsToPlayer($presetCollectionId)) {
            $this->collectionId = $presetCollectionId;
        }
    }

    // ------------------------------------------------------------------ scanning

    #[LiveAction]
    public function scan(#[LiveArg] string $ean): void
    {
        $this->clearTransient();
        $this->requireMember();

        $code = Ean::tryFrom($ean);

        if ($code === null) {
            $this->notify('invalid', $ean, null);
            return;
        }

        if ($this->findRow($code) !== null) {
            $this->notify('duplicate', $code->digits, $this->rowName($code));
            return;
        }

        if (count($this->rows) >= self::MAX_ROWS) {
            $this->notify('full', $code->digits, null);
            return;
        }

        $candidates = $this->getMultiscanCandidates->forEan($code);

        if ($candidates === []) {
            $this->rows[] = ['ean' => $code->digits, 'puzzleId' => null, 'state' => 'unknown', 'candidateIds' => []];
            $this->notify('unknown', $code->digits, null);
            $this->openResolveSheet($code);
            return;
        }

        $picked = count($candidates) === 1 ? $candidates[0] : $this->autoPick($candidates);

        if ($picked === null) {
            $this->rows[] = [
                'ean' => $code->digits,
                'puzzleId' => null,
                'state' => 'ambiguous',
                'candidateIds' => array_map(static fn (PuzzleOverview $c): string => $c->puzzleId, $candidates),
            ];
            $this->notify('ambiguous', $code->digits, null);
            return;
        }

        $this->addResolvedRow($code, $picked->puzzleId, $picked->puzzleName);
    }

    #[LiveAction]
    public function choose(#[LiveArg] string $ean, #[LiveArg] string $puzzleId): void
    {
        $this->clearTransient();
        $this->requireMember();

        $index = $this->rowIndex($ean);

        if ($index === null || !in_array($puzzleId, $this->rows[$index]['candidateIds'], true)) {
            return;
        }

        if ($this->hasPuzzle($puzzleId)) {
            $this->remove($ean);
            $this->notify('duplicate', $ean, null);
            return;
        }

        $this->resolveRowTo($ean, $puzzleId);
        $this->notify('chosen', $ean, null);
    }

    #[LiveAction]
    public function reopen(#[LiveArg] string $ean): void
    {
        $this->clearTransient();
        $index = $this->rowIndex($ean);

        if ($index === null) {
            return;
        }

        $row = $this->rows[$index];

        if ($row['state'] === 'unknown') {
            $code = Ean::tryFrom($ean);

            if ($code !== null) {
                $this->openResolveSheet($code);
            }

            return;
        }

        // A resolved row goes back to the chooser when the code has several candidates
        $code = Ean::tryFrom($ean);
        $candidates = $code !== null ? $this->getMultiscanCandidates->forEan($code) : [];

        if (count($candidates) > 1) {
            $this->replaceRow($ean, [
                'ean' => $ean,
                'puzzleId' => null,
                'state' => 'ambiguous',
                'candidateIds' => array_map(static fn (PuzzleOverview $c): string => $c->puzzleId, $candidates),
            ]);
        }
    }

    #[LiveAction]
    public function remove(#[LiveArg] string $ean): void
    {
        $this->clearTransient();
        $this->rows = array_values(array_filter($this->rows, static fn (array $row): bool => $row['ean'] !== $ean));

        if ($this->resolvingEan === $ean) {
            $this->closeResolveSheet();
        }
    }

    #[LiveAction]
    public function clear(): void
    {
        $this->clearTransient();
        $this->rows = [];
        $this->recap = null;
        $this->closeResolveSheet();
    }

    #[LiveAction]
    public function dismissRecap(): void
    {
        $this->recap = null;
    }

    #[LiveAction]
    public function dismissError(): void
    {
        $this->error = null;
        $this->errorParams = [];
    }

    /**
     * Unknown rows get a fresh lookup - the puzzle may have been added meanwhile
     * (the full add form in another tab, a link by somebody else).
     */
    #[LiveAction]
    public function recheckUnknown(): void
    {
        $this->clearTransient();
        $resolved = 0;

        foreach ($this->rows as $row) {
            if ($row['state'] !== 'unknown') {
                continue;
            }

            $code = Ean::tryFrom($row['ean']);

            if ($code === null) {
                continue;
            }

            $candidates = $this->getMultiscanCandidates->forEan($code);

            if (count($candidates) === 1 && !$this->hasPuzzle($candidates[0]->puzzleId)) {
                $this->resolveRowTo($row['ean'], $candidates[0]->puzzleId);
                $resolved++;

                if ($this->resolvingEan === $row['ean']) {
                    $this->closeResolveSheet();
                }
            }
        }

        if ($resolved > 0) {
            $this->notify('rechecked', '', (string) $resolved);
        }
    }

    // ------------------------------------------------------------------ applying

    #[LiveAction]
    public function apply(): void
    {
        $this->clearTransient();
        $profile = $this->requireMember();

        $action = $this->currentAction();
        $report = $this->report();

        if ($report->eligible === []) {
            $this->error = 'multiscan.error.nothing_to_apply';
            return;
        }

        $collectionId = $this->collectionId === Collection::SYSTEM_ID ? null : trim($this->collectionId);

        if ($collectionId === '') {
            $collectionId = null;
        }

        $personName = null;

        try {
            if ($action === MultiscanAction::AddToLibrary && $collectionId !== null && !$this->collectionBelongsToPlayer($collectionId)) {
                if (Uuid::isValid($collectionId)) {
                    $this->error = 'multiscan.error.collection_not_found';
                    return;
                }

                // Not an id: a name typed into the picker - create the collection like the add form does
                $collectionId = $this->createCollection($profile->playerId, mb_substr($collectionId, 0, 100));
                $this->collectionId = $collectionId;
                $this->collections = null;
            }

            $participant = null;

            if ($action->needsPerson()) {
                if (trim($this->person) === '') {
                    $this->error = 'multiscan.error.person_required';
                    return;
                }

                $participant = $this->participantParser->parse($this->person, $profile->playerId);
                $personName = $participant->displayName;
            }

            $notes = trim($this->notes) === '' ? null : trim($this->notes);

            $message = match ($action) {
                MultiscanAction::AddToLibrary => new AddPuzzlesToCollection($profile->playerId, $report->eligible, $collectionId),
                MultiscanAction::AddToWishlist => new AddPuzzlesToWishList($profile->playerId, $report->eligible),
                MultiscanAction::Lend => new LendPuzzlesToPlayer($profile->playerId, $report->eligible, $participant?->playerId, $participant?->playerName, $notes),
                MultiscanAction::Borrow => new BorrowPuzzlesFromPlayer($profile->playerId, $report->eligible, $participant?->playerId, $participant?->playerName, $notes),
                MultiscanAction::Return => new ReturnLentPuzzles($profile->playerId, $report->eligible),
            };

            $this->messageBus->dispatch($message);
        } catch (HandlerFailedException $e) {
            $this->failWith($e->getPrevious() ?? $e);
            return;
        } catch (PlayerNotFound | CannotLendToSelf $e) {
            $this->failWith($e);
            return;
        }

        $names = [];

        foreach ($report->eligible as $puzzleId) {
            if (isset($report->counterpartyNames[$puzzleId])) {
                $names[$puzzleId] = $report->counterpartyNames[$puzzleId];
            }
        }

        $this->recap = [
            'action' => $action->value,
            'puzzleIds' => $report->eligible,
            'person' => $personName,
            'names' => $names,
        ];

        // The pile stays: "add to library, then lend them" is the everyday sequence, so every
        // row keeps its place and only its chip changes; "Clear all" empties the tray.
        $this->notes = '';
        $this->getUserPuzzleStatuses->reset();
        $this->hydratedRows = null;
    }

    // ------------------------------------------------------------------ resolve sheet

    #[LiveAction]
    public function closeResolve(): void
    {
        $this->clearTransient();
        $this->closeResolveSheet();
    }

    #[LiveAction]
    public function toggleQuickAdd(): void
    {
        $this->resolveError = null;
        $this->quickAddOpen = !$this->quickAddOpen;
    }

    #[LiveAction]
    public function link(#[LiveArg] string $puzzleId): void
    {
        $this->clearTransient();
        $profile = $this->requireMember();

        if ($this->resolvingEan === null || !Uuid::isValid($puzzleId)) {
            return;
        }

        $ean = $this->resolvingEan;

        if ($this->hasPuzzle($puzzleId)) {
            $this->remove($ean);
            $this->notify('duplicate', $ean, null);
            return;
        }

        try {
            $this->messageBus->dispatch(new LinkEanToPuzzle($puzzleId, $profile->playerId, $ean));
        } catch (HandlerFailedException $e) {
            $this->resolveError = $e->getPrevious() instanceof EanAlreadyAssigned
                ? 'multiscan.resolve.error.already_assigned'
                : 'multiscan.resolve.error.link_failed';
            return;
        }

        $this->resolveRowTo($ean, $puzzleId);
        $this->notify('linked', $ean, null);
        $this->closeResolveSheet();
    }

    #[LiveAction]
    public function createPuzzle(Request $request): void
    {
        $this->clearTransient();
        $profile = $this->requireMember();

        if ($this->resolvingEan === null) {
            return;
        }

        $ean = Ean::tryFrom($this->resolvingEan);
        $name = trim($this->newName);
        $pieces = (int) trim($this->newPiecesCount);
        $brand = $this->resolveBrandInput();

        if ($ean === null || $name === '' || $pieces < 2 || $brand === '') {
            $this->resolveError = 'multiscan.resolve.error.fill_all';
            $this->quickAddOpen = true;
            return;
        }

        if ($profile->userId === null) {
            throw new PlayerNotFound();
        }

        // Somebody registered the code meanwhile (or it is a hidden puzzle): never create a duplicate
        $existing = $this->findPuzzlesByExactEan->ids($ean);

        if ($existing !== []) {
            $candidates = $this->getMultiscanCandidates->forEan($ean);

            if (count($candidates) === 1) {
                $this->resolveRowTo($ean->digits, $candidates[0]->puzzleId);
                $this->notify('rechecked', $ean->digits, '1');
                $this->closeResolveSheet();
            } elseif (count($candidates) > 1) {
                // Several visible puzzles carry it now: let the member pick, as a fresh scan would
                $this->replaceRow($ean->digits, [
                    'ean' => $ean->digits,
                    'puzzleId' => null,
                    'state' => 'ambiguous',
                    'candidateIds' => array_map(static fn (PuzzleOverview $c): string => $c->puzzleId, $candidates),
                ]);
                $this->notify('ambiguous', $ean->digits, null);
                $this->closeResolveSheet();
            } else {
                // Only a hidden puzzle carries it
                $this->resolveError = 'multiscan.resolve.error.already_assigned';
            }

            return;
        }

        $photo = $request->files->get('photo');
        $puzzleId = Uuid::uuid7();

        try {
            $this->messageBus->dispatch(new AddPuzzle(
                puzzleId: $puzzleId,
                userId: $profile->userId,
                puzzleName: $name,
                brand: $brand,
                piecesCount: $pieces,
                puzzlePhoto: $photo instanceof UploadedFile ? $photo : null,
                puzzleEan: $ean->digits,
                puzzleIdentificationNumber: null,
            ));
        } catch (HandlerFailedException $e) {
            $this->resolveError = $e->getPrevious() instanceof ManufacturerNotFound
                ? 'multiscan.resolve.error.brand_not_found'
                : 'multiscan.resolve.error.create_failed';
            $this->quickAddOpen = true;
            return;
        }

        $this->resolveRowTo($ean->digits, $puzzleId->toString());
        $this->notify('created', $ean->digits, $name);
        $this->closeResolveSheet();
    }

    // ------------------------------------------------------------------ rendering helpers

    public function currentAction(): MultiscanAction
    {
        return MultiscanAction::tryFrom($this->action) ?? MultiscanAction::AddToLibrary;
    }

    /**
     * @return list<MultiscanAction>
     */
    public function actions(): array
    {
        return MultiscanAction::cases();
    }

    /**
     * @return list<MultiscanRow>
     */
    public function trayRows(): array
    {
        if ($this->hydratedRows !== null) {
            return $this->hydratedRows;
        }

        $ids = [];

        foreach ($this->rows as $row) {
            if ($row['puzzleId'] !== null) {
                $ids[] = $row['puzzleId'];
            }

            foreach ($row['candidateIds'] as $candidateId) {
                $ids[] = $candidateId;
            }
        }

        foreach ($this->recap['puzzleIds'] ?? [] as $puzzleId) {
            $ids[] = $puzzleId;
        }

        $puzzles = $this->getPuzzleOverview->byIds($ids);
        $this->recapPuzzles = $puzzles;
        $statuses = $this->statuses();
        $report = $this->report();

        $rows = [];

        foreach ($this->rows as $row) {
            $puzzle = $row['puzzleId'] !== null ? ($puzzles[$row['puzzleId']] ?? null) : null;

            if ($row['state'] === 'resolved' && $puzzle === null) {
                // Deleted or merged away since it was scanned - shows as unknown, never crashes the tray
                $rows[] = new MultiscanRow($row['ean'], 'unknown', null, [], null, [], false, null, []);
                continue;
            }

            $candidates = [];

            foreach ($row['candidateIds'] as $candidateId) {
                if (isset($puzzles[$candidateId])) {
                    $candidates[] = $puzzles[$candidateId];
                }
            }

            [$chip, $chipParams] = $puzzle !== null ? $this->chipFor($puzzle->puzzleId, $statuses) : [null, []];
            $eligible = $puzzle !== null && $report->isEligible($puzzle->puzzleId);
            $reason = $puzzle !== null ? $report->reasonFor($puzzle->puzzleId) : null;
            $reasonParams = $puzzle !== null && isset($report->counterpartyNames[$puzzle->puzzleId])
                ? ['%name%' => $report->counterpartyNames[$puzzle->puzzleId]]
                : [];

            if ($reason === 'already_lent' && $reasonParams === []) {
                $reason = 'already_lent_unnamed';
            }

            $state = in_array($row['state'], ['resolved', 'ambiguous'], true) ? $row['state'] : 'unknown';

            $rows[] = new MultiscanRow($row['ean'], $state, $puzzle, $candidates, $chip, $chipParams, $eligible, $reason, $reasonParams);
        }

        return $this->hydratedRows = $rows;
    }

    /**
     * @return list<MultiscanRow>
     */
    public function actionableRows(): array
    {
        return array_values(array_filter($this->trayRows(), static fn (MultiscanRow $row): bool => $row->state !== 'unknown'));
    }

    /**
     * @return list<MultiscanRow>
     */
    public function unresolvedRows(): array
    {
        return array_values(array_filter($this->trayRows(), static fn (MultiscanRow $row): bool => $row->state === 'unknown'));
    }

    public function eligibleCount(): int
    {
        return count($this->report()->eligible);
    }

    /**
     * @return array<string, int> action slug => how many tray rows it applies to
     */
    public function actionCounts(): array
    {
        $ids = $this->resolvedPuzzleIds();
        $statuses = $this->statuses();
        $counts = [];

        foreach (MultiscanAction::cases() as $action) {
            $collectionId = $this->collectionId === Collection::SYSTEM_ID ? null : $this->collectionId;
            $counts[$action->value] = count($this->eligibility->check($action, $ids, $statuses, $collectionId)->eligible);
        }

        return $counts;
    }

    /**
     * Return has two faces: puzzles coming back to me (I own them) and puzzles I give back (I hold them).
     */
    public function returnFace(): string
    {
        $statuses = $this->statuses();
        $owned = 0;
        $held = 0;

        foreach ($this->resolvedPuzzleIds() as $puzzleId) {
            if (isset($statuses->lentPuzzleIds[$puzzleId])) {
                $owned++;
            } elseif (isset($statuses->borrowedPuzzleIds[$puzzleId])) {
                $held++;
            }
        }

        return match (true) {
            $owned > 0 && $held > 0 => 'mixed',
            $held > 0 => 'give_back',
            default => 'returned',
        };
    }

    /**
     * @return list<PuzzleOverview>
     */
    public function recapPuzzles(): array
    {
        if ($this->recap === null) {
            return [];
        }

        $this->trayRows();
        $puzzles = [];

        foreach ($this->recap['puzzleIds'] as $puzzleId) {
            if (isset($this->recapPuzzles[$puzzleId])) {
                $puzzles[] = $this->recapPuzzles[$puzzleId];
            }
        }

        return $puzzles;
    }

    /**
     * @return list<CollectionOverview>
     */
    public function collectionOptions(): array
    {
        if ($this->collections !== null) {
            return $this->collections;
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();

        return $this->collections = $profile === null ? [] : array_values($this->getPlayerCollections->byPlayerId($profile->playerId));
    }

    /**
     * @return list<ManufacturerOverview>
     */
    public function brandOptions(): array
    {
        if ($this->manufacturers !== null) {
            return $this->manufacturers;
        }

        $list = $this->getManufacturers->onlyApprovedOrAddedByPlayer();
        usort($list, static fn (ManufacturerOverview $a, ManufacturerOverview $b): int => strcasecmp($a->manufacturerName, $b->manufacturerName));

        return $this->manufacturers = $list;
    }

    /**
     * @return list<PuzzleOverview>
     */
    public function resolveResults(): array
    {
        $query = trim($this->resolveQuery);

        if ($this->resolvingEan === null || mb_strlen($query) < 2) {
            return [];
        }

        try {
            return $this->searchPuzzle->byUserInput(
                brandId: $this->resolveBrandId,
                search: $query,
                pieces: PiecesRange::any(),
                tag: null,
                limit: self::SEARCH_LIMIT,
            );
        } catch (ManufacturerNotFound) {
            return [];
        }
    }

    public function likelyWrongBarcode(): bool
    {
        return $this->resolvingEan !== null && $this->resolveBrandId === null && strlen($this->resolvingEan) !== 13;
    }

    // ------------------------------------------------------------------ internals

    /**
     * @throws HandlerFailedException
     */
    private function createCollection(string $playerId, string $name): string
    {
        $newId = Uuid::uuid7()->toString();

        try {
            $this->messageBus->dispatch(new CreateCollection(
                collectionId: $newId,
                playerId: $playerId,
                name: $name,
                description: null,
                visibility: CollectionVisibility::Private,
            ));
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();

            if ($previous instanceof CollectionAlreadyExists) {
                return $previous->collectionId;
            }

            throw $e;
        }

        return $newId;
    }

    /**
     * People for the person picker, the most useful first: those I lent to (Lend) or borrowed
     * from (Borrow) most often, then the other direction, then favourites not already listed.
     *
     * @return array{recent: list<LendBorrowCounterparty>, favorites: list<PlayerIdentification>}
     */
    public function personSuggestions(): array
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return ['recent' => [], 'favorites' => []];
        }

        $preferredRole = $this->currentAction() === MultiscanAction::Borrow ? 'borrow' : 'lend';
        $all = $this->getLendBorrowCounterparties->byPlayerId($profile->playerId);
        $recent = [];
        $seen = [];

        foreach ([$preferredRole, $preferredRole === 'lend' ? 'borrow' : 'lend'] as $role) {
            foreach ($all as $counterparty) {
                if ($counterparty->role === $role && !isset($seen[$counterparty->value])) {
                    $seen[$counterparty->value] = true;
                    $recent[] = $counterparty;
                }
            }
        }

        $favorites = [];

        foreach ($this->getFavoritePlayers->forPlayerId($profile->playerId) as $favorite) {
            if (!isset($seen['#' . $favorite->playerCode])) {
                $favorites[] = $favorite;
            }
        }

        return ['recent' => $recent, 'favorites' => $favorites];
    }

    /**
     * Quick-add brand: a typed name that matches a known brand (case-insensitive) is that brand,
     * otherwise the dropdown pick, otherwise the typed name as a new brand. Never a duplicate of
     * an existing manufacturer just because somebody typed "Ravensburger" again.
     */
    private function resolveBrandInput(): string
    {
        $typed = trim($this->newBrandName);

        if ($typed !== '') {
            foreach ($this->brandOptions() as $brand) {
                if (strcasecmp($brand->manufacturerName, $typed) === 0) {
                    return $brand->manufacturerId;
                }
            }

            return $typed;
        }

        return trim($this->newBrand);
    }

    private function requireMember(): PlayerProfile
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            throw new PlayerNotFound();
        }

        if ($profile->activeMembership === false) {
            // Each live action is its own request: the page gate is not enough
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Multiscan is a members-only feature.');
        }

        return $profile;
    }

    private function statuses(): UserPuzzleStatuses
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        return $this->getUserPuzzleStatuses->byPlayerId($profile?->playerId);
    }

    private function report(): MultiscanEligibilityReport
    {
        $collectionId = $this->collectionId === Collection::SYSTEM_ID ? null : $this->collectionId;

        return $this->eligibility->check($this->currentAction(), $this->resolvedPuzzleIds(), $this->statuses(), $collectionId);
    }

    /**
     * @return list<string>
     */
    private function resolvedPuzzleIds(): array
    {
        $ids = [];

        foreach ($this->rows as $row) {
            if ($row['state'] === 'resolved' && $row['puzzleId'] !== null) {
                $ids[] = $row['puzzleId'];
            }
        }

        return $ids;
    }

    /**
     * @return array{null|string, array<string, string>}
     */
    private function chipFor(string $puzzleId, UserPuzzleStatuses $statuses): array
    {
        if (isset($statuses->lentPuzzleIds[$puzzleId])) {
            return isset($statuses->lentToNames[$puzzleId])
                ? ['lent', ['%name%' => $statuses->lentToNames[$puzzleId]]]
                : ['lent_unnamed', []];
        }

        if (isset($statuses->borrowedPuzzleIds[$puzzleId])) {
            return ['borrowed', ['%name%' => $statuses->borrowedFromNames[$puzzleId] ?? '']];
        }

        if (in_array($puzzleId, $statuses->collection, true)) {
            return ['in_library', []];
        }

        if (in_array($puzzleId, $statuses->wishlist, true)) {
            return ['on_wishlist', []];
        }

        return ['not_in_library', []];
    }

    /**
     * Several candidates: exactly one of them already in my library / lend list wins silently.
     *
     * @param list<PuzzleOverview> $candidates
     */
    private function autoPick(array $candidates): null|PuzzleOverview
    {
        $statuses = $this->statuses();
        $mine = [];

        foreach ($candidates as $candidate) {
            $id = $candidate->puzzleId;

            if (
                in_array($id, $statuses->collection, true)
                || isset($statuses->lentPuzzleIds[$id])
                || isset($statuses->borrowedPuzzleIds[$id])
            ) {
                $mine[] = $candidate;
            }
        }

        return count($mine) === 1 ? $mine[0] : null;
    }

    private function addResolvedRow(Ean $code, string $puzzleId, string $puzzleName): void
    {
        if ($this->hasPuzzle($puzzleId)) {
            $this->notify('duplicate', $code->digits, $puzzleName);
            return;
        }

        $this->rows[] = ['ean' => $code->digits, 'puzzleId' => $puzzleId, 'state' => 'resolved', 'candidateIds' => []];
        $this->notify('found', $code->digits, $puzzleName);
    }

    private function resolveRowTo(string $ean, string $puzzleId): void
    {
        $this->replaceRow($ean, ['ean' => $ean, 'puzzleId' => $puzzleId, 'state' => 'resolved', 'candidateIds' => []]);
    }

    /**
     * @param array{ean: string, puzzleId: null|string, state: string, candidateIds: list<string>} $replacement
     */
    private function replaceRow(string $ean, array $replacement): void
    {
        $rows = [];
        $found = false;

        foreach ($this->rows as $row) {
            if ($row['ean'] === $ean) {
                $rows[] = $replacement;
                $found = true;
            } else {
                $rows[] = $row;
            }
        }

        if ($found === false) {
            $rows[] = $replacement;
        }

        $this->rows = $rows;
    }

    private function openResolveSheet(Ean $code): void
    {
        $this->resolvingEan = $code->digits;
        $this->resolveQuery = '';
        $this->resolveError = null;
        $this->quickAddOpen = false;
        $this->newName = '';
        $this->newPiecesCount = '';
        $this->newBrand = '';
        $this->newBrandName = '';
        $this->resolveBrandId = null;
        $this->resolveBrandName = null;

        $brands = $this->getManufacturers->allByEanPrefix($code->digits);

        if (count($brands) === 1) {
            $this->resolveBrandId = $brands[0]['manufacturer_id'];
            $this->resolveBrandName = $brands[0]['manufacturer_name'];
            $this->newBrand = $brands[0]['manufacturer_id'];
        }
    }

    private function closeResolveSheet(): void
    {
        $this->resolvingEan = null;
        $this->resolveQuery = '';
        $this->resolveError = null;
        $this->quickAddOpen = false;
        $this->resolveBrandId = null;
        $this->resolveBrandName = null;
    }

    private function notify(string $type, string $ean, null|string $name): void
    {
        $this->notice = ['type' => $type, 'ean' => $ean, 'name' => $name];
        $this->noticeSeq++;
    }

    private function clearTransient(): void
    {
        $this->notice = null;
        $this->error = null;
        $this->errorParams = [];
        $this->hydratedRows = null;
    }

    private function failWith(\Throwable $e): void
    {
        [$this->error, $this->errorParams] = match (true) {
            $e instanceof MultiscanBatchRejected => ['multiscan.error.rejected', ['%reason%' => $e->reason]],
            $e instanceof CannotLendToSelf => ['multiscan.error.cannot_lend_to_self', []],
            $e instanceof PlayerNotFound => ['multiscan.error.player_not_found', []],
            $e instanceof CollectionNotFound => ['multiscan.error.collection_not_found', []],
            default => ['multiscan.error.failed', []],
        };
    }

    private function findRow(Ean $code): null|int
    {
        foreach ($this->rows as $index => $row) {
            $rowCode = Ean::tryFrom($row['ean']);

            if ($rowCode !== null && $rowCode->equals($code)) {
                return $index;
            }
        }

        return null;
    }

    private function rowIndex(string $ean): null|int
    {
        foreach ($this->rows as $index => $row) {
            if ($row['ean'] === $ean) {
                return $index;
            }
        }

        return null;
    }

    private function rowName(Ean $code): null|string
    {
        // The render hydrates every row once anyway; reuse that instead of a second query
        foreach ($this->trayRows() as $row) {
            $rowCode = Ean::tryFrom($row->ean);

            if ($rowCode !== null && $rowCode->equals($code)) {
                return $row->puzzle?->puzzleName;
            }
        }

        return null;
    }

    private function hasPuzzle(string $puzzleId): bool
    {
        foreach ($this->rows as $row) {
            if ($row['puzzleId'] === $puzzleId) {
                return true;
            }
        }

        return false;
    }

    private function collectionBelongsToPlayer(string $collectionId): bool
    {
        if ($collectionId === Collection::SYSTEM_ID) {
            return true;
        }

        foreach ($this->collectionOptions() as $collection) {
            if ($collection->collectionId === $collectionId) {
                return true;
            }
        }

        return false;
    }
}

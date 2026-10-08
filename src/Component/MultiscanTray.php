<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Exceptions\CannotLendToSelf;
use SpeedPuzzling\Web\Exceptions\CollectionAlreadyExists;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Exceptions\EanAlreadyAssigned;
use SpeedPuzzling\Web\Exceptions\InvalidEan;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Exceptions\MultiscanBatchRejected;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzlesToCollection;
use SpeedPuzzling\Web\Message\AddPuzzlesToWishList;
use SpeedPuzzling\Web\Message\BorrowPuzzlesFromPlayer;
use SpeedPuzzling\Web\Message\CreateCollection;
use SpeedPuzzling\Web\Message\LendPuzzlesToPlayer;
use SpeedPuzzling\Web\Message\LinkEanToPuzzle;
use SpeedPuzzling\Web\Message\ReturnLentPuzzles;
use SpeedPuzzling\Web\Query\FindPuzzlesByExactEan;
use SpeedPuzzling\Web\Query\GetCurrentPuzzleIds;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetLendBorrowCounterparties;
use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Query\GetMultiscanCandidates;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzleRecord;
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
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Services\SecretPuzzleRefusalMessage;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use SpeedPuzzling\Web\Value\Ean;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\MultiscanAction;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleBoxPhoto;
use SpeedPuzzling\Web\Value\PuzzleSearchCriteria;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\Component\Validator\Validator\ValidatorInterface;

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
     * One row per scanned box. A code several puzzles share (a multipack) can have one row per puzzle,
     * so rows are addressed by `key`. `candidateIds` = every puzzle of a shared code (ambiguous rows
     * offer those no other row holds), empty for a code of one puzzle or none.
     *
     * @var list<array{key: string, ean: string, puzzleId: null|string, state: string, candidateIds: list<string>}>
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
     * `row` is the key of the row it concerns, when there is one.
     *
     * @var null|array{type: string, ean: string, name: null|string, row: null|string}
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

    /**
     * Id of the puzzle quick-add will create, fixed when the sheet opens: a retry after a
     * lost response finds the puzzle it already created instead of adding it twice.
     */
    #[LiveProp]
    public null|string $quickAddId = null;

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
        readonly private ValidatorInterface $validator,
        readonly private GetPuzzleRecord $getPuzzleRecord,
        readonly private SecretPuzzleRefusalMessage $secretPuzzleRefusalMessage,
        readonly private SecretPuzzleAccess $secretPuzzleAccess,
        readonly private GetCurrentPuzzleIds $getCurrentPuzzleIds,
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

    /**
     * A tray rendered before rows had keys (one row per code then) keeps working: the code is its key.
     */
    #[PostHydrate]
    public function keyLegacyRows(): void
    {
        /** @var list<array{key?: string, ean: string, puzzleId: null|string, state: string, candidateIds: list<string>}> $rows */
        $rows = $this->rows;

        $this->rows = array_map(
            static fn (array $row): array => ['key' => $row['key'] ?? $row['ean']] + $row,
            $rows,
        );
    }

    /**
     * A puzzle merged away since it was scanned becomes the puzzle it was merged into; one deleted without a merge
     * becomes an unknown code, never sent to an action. Costs nothing while every row's puzzle exists - the rows are
     * hydrated for the template anyway.
     */
    #[PreReRender]
    public function followMergedPuzzles(): void
    {
        $this->replaceVanishedPuzzles();
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

        $inTray = $this->rowsOf($code);

        // Scanned again while its row still waits for a decision: nothing new to add
        foreach ($inTray as $row) {
            if ($row['state'] !== 'resolved') {
                $this->notify('duplicate', $code->digits, null, $row['key']);
                return;
            }
        }

        // Only a code several puzzles share can have another row
        if ($inTray !== [] && array_merge(...array_column($inTray, 'candidateIds')) === []) {
            $this->notify('duplicate', $code->digits, $this->rowName($code), $inTray[0]['key']);
            return;
        }

        if (count($this->rows) >= self::MAX_ROWS) {
            $this->notify('full', $code->digits, null);
            return;
        }

        $candidates = $this->getMultiscanCandidates->forEan($code);
        $candidateIds = count($candidates) > 1
            ? array_map(static fn (PuzzleOverview $c): string => $c->puzzleId, $candidates)
            : [];

        if ($inTray !== []) {
            // A code several puzzles share (a multipack) scanned again: the next row takes a puzzle
            // the tray does not hold yet - a duplicate only once every one of them is in
            $candidates = array_values(array_filter($candidates, fn (PuzzleOverview $c): bool => !$this->hasPuzzle($c->puzzleId)));

            if ($candidates === []) {
                $this->notify('duplicate', $code->digits, $this->rowName($code), $inTray[0]['key']);
                return;
            }
        } elseif ($candidates === []) {
            $key = $this->newRowKey();
            $this->rows[] = ['key' => $key, 'ean' => $code->digits, 'puzzleId' => null, 'state' => 'unknown', 'candidateIds' => []];
            $this->notify('unknown', $code->digits, null, $key);
            $this->openResolveSheet($code);
            return;
        }

        $picked = count($candidates) === 1 ? $candidates[0] : $this->autoPick($candidates);

        if ($picked === null) {
            $key = $this->newRowKey();
            $this->rows[] = [
                'key' => $key,
                'ean' => $code->digits,
                'puzzleId' => null,
                'state' => 'ambiguous',
                'candidateIds' => $candidateIds,
            ];
            $this->notify('ambiguous', $code->digits, null, $key);
            return;
        }

        $this->addResolvedRow($code, $picked->puzzleId, $picked->puzzleName, $candidateIds);
    }

    #[LiveAction]
    public function choose(#[LiveArg] string $row, #[LiveArg] string $puzzleId): void
    {
        $this->clearTransient();
        $this->requireMember();

        $index = $this->rowIndex($row);

        if ($index === null || !in_array($puzzleId, $this->rows[$index]['candidateIds'], true)) {
            return;
        }

        $ean = $this->rows[$index]['ean'];

        if ($this->hasPuzzle($puzzleId, exceptRow: $row)) {
            // Taken by another row meanwhile: this row goes when it has nothing else to offer
            if ($this->offeredCandidateIds($this->rows[$index]) === []) {
                $this->remove($row);
            }

            $this->notify('duplicate', $ean, null);
            return;
        }

        $this->resolveRowTo($row, $puzzleId);
        $this->notify('chosen', $ean, null, $row);
    }

    #[LiveAction]
    public function reopen(#[LiveArg] string $row): void
    {
        $this->clearTransient();
        $index = $this->rowIndex($row);

        if ($index === null) {
            return;
        }

        $ean = $this->rows[$index]['ean'];
        $code = Ean::tryFrom($ean);

        if ($this->rows[$index]['state'] === 'unknown') {
            if ($code !== null) {
                $this->openResolveSheet($code);
            }

            return;
        }

        // A resolved row goes back to the chooser when the code has several candidates
        // that no other row holds (its own pick stays among them)
        $candidates = $code !== null ? $this->getMultiscanCandidates->forEan($code) : [];
        $reopened = [
            'key' => $row,
            'ean' => $ean,
            'puzzleId' => null,
            'state' => 'ambiguous',
            'candidateIds' => array_map(static fn (PuzzleOverview $c): string => $c->puzzleId, $candidates),
        ];

        if (count($candidates) > 1 && count($this->offeredCandidateIds($reopened)) > 1) {
            $this->replaceRow($row, $reopened);
        }
    }

    #[LiveAction]
    public function remove(#[LiveArg] string $row): void
    {
        $this->clearTransient();
        $index = $this->rowIndex($row);

        if ($index === null) {
            return;
        }

        $removed = $this->rows[$index];
        $this->rows = array_values(array_filter($this->rows, static fn (array $r): bool => $r['key'] !== $row));

        if ($removed['state'] === 'unknown' && $this->resolvingEan === $removed['ean']) {
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

    /**
     * Puts back a tray the browser kept for this tab (multiscan controller, sessionStorage) after
     * the page was reloaded - on phones opening the camera can make the browser drop the page.
     * Every code is looked up again, like a fresh scan: nothing from the browser is trusted beyond
     * "these codes, and this puzzle where it was picked among several".
     */
    #[LiveAction]
    public function restore(#[LiveArg] string $state): void
    {
        $this->clearTransient();
        $profile = $this->requireMember();

        if ($this->rows !== []) {
            return;
        }

        $data = json_decode($state, true);

        if (!is_array($data) || !is_array($data['rows'] ?? null)) {
            return;
        }

        /** @var array<string, list<PuzzleOverview>> $candidatesByCode */
        $candidatesByCode = [];

        foreach ($data['rows'] as $saved) {
            if (count($this->rows) >= self::MAX_ROWS) {
                break;
            }

            if (!is_array($saved) || !is_string($saved['ean'] ?? null)) {
                continue;
            }

            $code = Ean::tryFrom($saved['ean']);

            if ($code === null) {
                continue;
            }

            $candidates = $candidatesByCode[$code->digits] ??= $this->getMultiscanCandidates->forEan($code);
            $candidateIds = array_map(static fn (PuzzleOverview $c): string => $c->puzzleId, $candidates);
            $sharedIds = count($candidates) > 1 ? $candidateIds : [];
            $savedPick = is_string($saved['puzzleId'] ?? null) && in_array($saved['puzzleId'], $candidateIds, true) && !$this->hasPuzzle($saved['puzzleId'])
                ? $saved['puzzleId']
                : null;

            if ($this->rowsOf($code) !== []) {
                // Another row of a code several puzzles share comes back only with its own pick
                if ($savedPick !== null) {
                    $this->rows[] = ['key' => $this->newRowKey(), 'ean' => $code->digits, 'puzzleId' => $savedPick, 'state' => 'resolved', 'candidateIds' => $sharedIds];
                }

                continue;
            }

            $pickedId = $savedPick ?? (count($candidates) === 1 ? $candidates[0]->puzzleId : null);

            $this->rows[] = match (true) {
                $pickedId !== null => ['key' => $this->newRowKey(), 'ean' => $code->digits, 'puzzleId' => $pickedId, 'state' => 'resolved', 'candidateIds' => $sharedIds],
                $candidates === [] => ['key' => $this->newRowKey(), 'ean' => $code->digits, 'puzzleId' => null, 'state' => 'unknown', 'candidateIds' => []],
                default => ['key' => $this->newRowKey(), 'ean' => $code->digits, 'puzzleId' => null, 'state' => 'ambiguous', 'candidateIds' => $candidateIds],
            };
        }

        if (is_string($data['action'] ?? null) && MultiscanAction::tryFrom($data['action']) !== null) {
            $this->action = $data['action'];
        }

        if (is_string($data['collectionId'] ?? null) && ($data['collectionId'] === Collection::SYSTEM_ID || $this->collectionBelongsToPlayer($data['collectionId']))) {
            $this->collectionId = $data['collectionId'];
        }

        // A quick-add that was being filled in comes back with what was typed (the photo is retaken)
        $sheet = $data['sheet'] ?? null;

        $sheetCode = is_array($sheet) && is_string($sheet['ean'] ?? null) ? Ean::tryFrom($sheet['ean']) : null;

        if (is_array($sheet) && $sheetCode !== null && $this->unknownRowKey($sheetCode->digits) !== null) {
            $this->openResolveSheet($sheetCode);
            $this->quickAddOpen = ($sheet['quickAddOpen'] ?? false) === true;
            $this->newName = is_string($sheet['name'] ?? null) ? mb_substr($sheet['name'], 0, 255) : '';
            $this->newPiecesCount = is_string($sheet['pieces'] ?? null) ? mb_substr($sheet['pieces'], 0, 6) : '';
            $this->newBrandName = is_string($sheet['brandName'] ?? null) ? mb_substr($sheet['brandName'], 0, 100) : '';

            if (is_string($sheet['brand'] ?? null) && Uuid::isValid($sheet['brand'])) {
                $this->newBrand = $sheet['brand'];
            }

            // Same puzzle id as before the reload: a create that did go through is found, not repeated. The id comes
            // from the browser - taken only while no puzzle has it or the puzzle is this player's own creation, never
            // somebody else's (a secret one would show in the tray)
            if (
                is_string($sheet['quickAddId'] ?? null)
                && Uuid::isValid($sheet['quickAddId'])
                && $this->isFreeOrOwnPuzzleId($sheet['quickAddId'], $profile->playerId)
            ) {
                $this->quickAddId = $sheet['quickAddId'];
            }
        }

        if ($this->rows !== []) {
            $this->notify('restored', '', (string) count($this->rows));
        }
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
                $this->resolveRowTo($row['key'], $candidates[0]->puzzleId);
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
        $this->replaceVanishedPuzzles();
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
        } catch (PlayerNotFound | CannotLendToSelf | PuzzleNotRevealedYet $e) {
            // HTTP exceptions of the handler arrive unwrapped (UnwrapHttpExceptionMiddleware)
            $this->failWith($e);
            return;
        } catch (PuzzleNotFound $e) {
            $this->takeOutUnavailablePuzzles($e);
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
        $key = $this->resolvingRowKey() ?? $this->newRowKey();

        if ($this->hasPuzzle($puzzleId)) {
            $this->remove($key);
            $this->notify('duplicate', $ean, null);
            return;
        }

        try {
            $this->messageBus->dispatch(new LinkEanToPuzzle($puzzleId, $profile->playerId, $ean));
        } catch (HandlerFailedException $e) {
            $this->resolveError = match (true) {
                $e->getPrevious() instanceof EanAlreadyAssigned => 'multiscan.resolve.error.already_assigned',
                $e->getPrevious() instanceof InvalidEan => 'multiscan.resolve.error.invalid_ean',
                default => 'multiscan.resolve.error.link_failed',
            };
            return;
        } catch (PuzzleNotFound) {
            // An HTTP exception of the handler arrives unwrapped (UnwrapHttpExceptionMiddleware) - a puzzle kept secret
            $this->resolveError = 'multiscan.resolve.error.link_failed';
            return;
        }

        $this->resolveRowTo($key, $puzzleId);
        $this->notify('linked', $ean, null, $key);
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

        // The rule of every form (EanList::invalidCodes()) - a code no form would store is not stored from here either
        if ($ean !== null && EanList::isAcceptedNewCode($this->resolvingEan) === false) {
            $this->resolveError = 'multiscan.resolve.error.invalid_ean';
            $this->quickAddOpen = true;
            return;
        }

        if ($ean === null || $name === '' || $pieces < 2 || $brand === '') {
            $this->resolveError = 'multiscan.resolve.error.fill_all';
            $this->quickAddOpen = true;
            return;
        }

        if ($profile->userId === null) {
            throw new PlayerNotFound();
        }

        $key = $this->resolvingRowKey() ?? $this->newRowKey();
        $puzzleId = $this->quickAddId !== null && Uuid::isValid($this->quickAddId)
            ? Uuid::fromString($this->quickAddId)
            : Uuid::uuid7();
        $existingRecord = $this->getPuzzleRecord->byId($puzzleId->toString());

        if ($existingRecord !== null) {
            // A retry after the answer got lost on the way: this player created it already - just use it
            if ($existingRecord->addedById === $profile->playerId) {
                $this->resolveRowTo($key, $puzzleId->toString());
                $this->notify('created', $ean->digits, $name, $key);
                $this->closeResolveSheet();
                return;
            }

            // Somebody else's puzzle has that id - never take it over, create a new one
            $puzzleId = Uuid::uuid7();
        }

        // Somebody registered the code meanwhile (or it is a hidden puzzle): never create a duplicate
        $existing = $this->findPuzzlesByExactEan->ids($ean);

        if ($existing !== []) {
            $candidates = $this->getMultiscanCandidates->forEan($ean);

            if (count($candidates) === 1) {
                $this->resolveRowTo($key, $candidates[0]->puzzleId);
                $this->notify('rechecked', $ean->digits, '1');
                $this->closeResolveSheet();
            } elseif (count($candidates) > 1) {
                // Several visible puzzles carry it now: let the member pick, as a fresh scan would
                $this->replaceRow($key, [
                    'key' => $key,
                    'ean' => $ean->digits,
                    'puzzleId' => null,
                    'state' => 'ambiguous',
                    'candidateIds' => array_map(static fn (PuzzleOverview $c): string => $c->puzzleId, $candidates),
                ]);
                $this->notify('ambiguous', $ean->digits, null, $key);
                $this->closeResolveSheet();
            } else {
                // Only a hidden puzzle carries it - answered like any other failure: "already assigned" would tell
                // that a secret box has this code
                $this->resolveError = 'multiscan.resolve.error.create_failed';
            }

            return;
        }

        // Same rule as the add form: no new puzzle without a photo of its box
        $photo = $request->files->get('photo');

        if (!$photo instanceof UploadedFile) {
            $this->resolveError = 'multiscan.resolve.error.photo_required';
            $this->quickAddOpen = true;
            return;
        }

        if (count($this->validator->validate($photo, PuzzleBoxPhoto::constraint())) > 0) {
            $this->resolveError = 'multiscan.resolve.error.photo_invalid';
            $this->quickAddOpen = true;
            return;
        }

        try {
            $this->messageBus->dispatch(new AddPuzzle(
                puzzleId: $puzzleId,
                userId: $profile->userId,
                puzzleName: $name,
                brand: $brand,
                piecesCount: $pieces,
                puzzlePhoto: $photo,
                eans: EanList::fromStored($ean->digits),
                brandCodes: BrandCodeList::fromStored(null),
            ));
        } catch (ManufacturerNotFound) {
            // The picked brand is gone (merged or deleted since the sheet opened). Not wrapped:
            // UnwrapHttpExceptionMiddleware rethrows a handler's HTTP exception as it is
            $this->resolveError = 'multiscan.resolve.error.brand_not_found';
            $this->quickAddOpen = true;
            return;
        } catch (HandlerFailedException) {
            $this->resolveError = 'multiscan.resolve.error.create_failed';
            $this->quickAddOpen = true;
            return;
        }

        $this->resolveRowTo($key, $puzzleId->toString());
        $this->notify('created', $ean->digits, $name, $key);
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
        $offered = [];

        foreach ($this->rows as $row) {
            if ($row['puzzleId'] !== null) {
                $ids[] = $row['puzzleId'];
            }

            if ($row['state'] === 'ambiguous') {
                $offered[$row['key']] = $this->offeredCandidateIds($row);
                array_push($ids, ...$offered[$row['key']]);
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
                $rows[] = new MultiscanRow($row['key'], $row['ean'], 'unknown', null, [], null, [], false, null, []);
                continue;
            }

            $candidates = [];

            foreach ($offered[$row['key']] ?? [] as $candidateId) {
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

            $rows[] = new MultiscanRow($row['key'], $row['ean'], $state, $puzzle, $candidates, $chip, $chipParams, $eligible, $reason, $reasonParams);
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

        // Every brand, approved or not - one left out gets typed again as a new brand
        $list = $this->getManufacturers->allIncludingUnapproved();
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
                sortBy: PuzzleSearchCriteria::BEST_MATCH,
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
     * Quick-add brand: the typed name, otherwise the dropdown pick. AddPuzzleHandler turns a typed name
     * that is an existing brand (ignoring case and spacing, approved or not) into that brand
     * (ManufacturerResolver) - never a duplicate just because somebody typed "Ravensburger" again.
     */
    private function resolveBrandInput(): string
    {
        $typed = trim($this->newBrandName);

        return $typed !== '' ? $typed : trim($this->newBrand);
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

    /**
     * @param list<string> $candidateIds every puzzle of the code when several share it
     */
    private function addResolvedRow(Ean $code, string $puzzleId, string $puzzleName, array $candidateIds): void
    {
        if ($this->hasPuzzle($puzzleId)) {
            $this->notify('duplicate', $code->digits, $puzzleName);
            return;
        }

        $key = $this->newRowKey();
        $this->rows[] = ['key' => $key, 'ean' => $code->digits, 'puzzleId' => $puzzleId, 'state' => 'resolved', 'candidateIds' => $candidateIds];
        $this->notify('found', $code->digits, $puzzleName, $key);
    }

    /**
     * Resolves the row (or appends one, when it is gone meanwhile); a row of a shared code keeps its candidates.
     */
    private function resolveRowTo(string $key, string $puzzleId): void
    {
        $index = $this->rowIndex($key);
        $ean = $index !== null ? $this->rows[$index]['ean'] : (string) $this->resolvingEan;
        $candidateIds = $index !== null ? $this->rows[$index]['candidateIds'] : [];

        $this->replaceRow($key, ['key' => $key, 'ean' => $ean, 'puzzleId' => $puzzleId, 'state' => 'resolved', 'candidateIds' => $candidateIds]);
    }

    /**
     * @param array{key: string, ean: string, puzzleId: null|string, state: string, candidateIds: list<string>} $replacement
     */
    private function replaceRow(string $key, array $replacement): void
    {
        $rows = [];
        $found = false;

        foreach ($this->rows as $row) {
            if ($row['key'] === $key) {
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
        $this->quickAddId = Uuid::uuid7()->toString();
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
        $this->quickAddId = null;
        $this->resolveQuery = '';
        $this->resolveError = null;
        $this->quickAddOpen = false;
        $this->resolveBrandId = null;
        $this->resolveBrandName = null;
    }

    private function notify(string $type, string $ean, null|string $name, null|string $row = null): void
    {
        $this->notice = ['type' => $type, 'ean' => $ean, 'name' => $name, 'row' => $row];
        $this->noticeSeq++;
    }

    private function clearTransient(): void
    {
        $this->notice = null;
        $this->error = null;
        $this->errorParams = [];
        $this->hydratedRows = null;
    }

    /**
     * A puzzle of the batch became secret after it was scanned (a competition keeps it hidden from this player until
     * its reveal): "try again" would fail the same way, so it is taken out of the tray. Why it is no longer available
     * is not said - that would tell it is secret.
     */
    private function takeOutUnavailablePuzzles(PuzzleNotFound $e): void
    {
        $hidden = $this->secretPuzzleAccess->hiddenFromViewerAmong($this->resolvedPuzzleIds());
        $count = count($this->rows);

        $this->rows = array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => $row['puzzleId'] === null || !isset($hidden[strtolower($row['puzzleId'])]),
        ));
        $this->hydratedRows = null;
        $takenOut = $count - count($this->rows);

        if ($takenOut > 0) {
            $this->error = 'multiscan.error.unavailable';
            $this->errorParams = ['%count%' => (string) $takenOut];
        } else {
            $this->failWith($e);
        }
    }

    /**
     * Rows whose puzzle no longer exists: the survivor of its merge takes its place (unless already in the tray),
     * a puzzle deleted without a merge leaves an unknown code.
     */
    private function replaceVanishedPuzzles(): void
    {
        $vanished = [];

        foreach ($this->trayRows() as $index => $row) {
            $stored = $this->rows[$index];

            if ($stored['state'] === 'resolved' && $stored['puzzleId'] !== null && $row->puzzle === null) {
                $vanished[$stored['key']] = $stored['puzzleId'];
            }
        }

        if ($vanished === []) {
            return;
        }

        $currentIds = $this->getCurrentPuzzleIds->of(array_values($vanished));

        foreach ($vanished as $key => $puzzleId) {
            $survivorId = $currentIds[strtolower($puzzleId)] ?? null;

            if ($survivorId !== null && $this->hasPuzzle($survivorId, $key)) {
                $this->rows = array_values(array_filter($this->rows, static fn (array $r): bool => $r['key'] !== $key));
                continue;
            }

            $index = $this->rowIndex($key);
            assert($index !== null);
            $row = $this->rows[$index];

            $this->replaceRow($key, $survivorId !== null
                ? array_replace($row, ['puzzleId' => $survivorId])
                : array_replace($row, ['puzzleId' => null, 'state' => 'unknown', 'candidateIds' => []]));
        }

        $this->hydratedRows = null;
    }

    private function failWith(\Throwable $e): void
    {
        [$this->error, $this->errorParams] = match (true) {
            $e instanceof MultiscanBatchRejected => ['multiscan.error.rejected', ['%reason%' => $e->reason]],
            $e instanceof CannotLendToSelf => ['multiscan.error.cannot_lend_to_self', []],
            $e instanceof PlayerNotFound => ['multiscan.error.player_not_found', []],
            $e instanceof CollectionNotFound => ['multiscan.error.collection_not_found', []],
            $e instanceof PuzzleNotRevealedYet => $this->secretPuzzleRefusalMessage->translation($e),
            default => ['multiscan.error.failed', []],
        };
    }

    /**
     * Rows of this code - several when puzzles share it (a multipack)
     *
     * @return list<array{key: string, ean: string, puzzleId: null|string, state: string, candidateIds: list<string>}>
     */
    private function rowsOf(Ean $code): array
    {
        $rows = [];

        foreach ($this->rows as $row) {
            $rowCode = Ean::tryFrom($row['ean']);

            if ($rowCode !== null && $rowCode->equals($code)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function rowIndex(string $key): null|int
    {
        foreach ($this->rows as $index => $row) {
            if ($row['key'] === $key) {
                return $index;
            }
        }

        return null;
    }

    private function unknownRowKey(string $ean): null|string
    {
        foreach ($this->rows as $row) {
            if ($row['ean'] === $ean && $row['state'] === 'unknown') {
                return $row['key'];
            }
        }

        return null;
    }

    /**
     * The row the resolve sheet works on (a code nobody has is in the tray once)
     */
    public function resolvingRowKey(): null|string
    {
        return $this->resolvingEan !== null ? $this->unknownRowKey($this->resolvingEan) : null;
    }

    private function newRowKey(): string
    {
        return 'r' . bin2hex(random_bytes(6));
    }

    /**
     * What an ambiguous row offers: the code's puzzles that no other row holds
     *
     * @param array{key: string, ean: string, puzzleId: null|string, state: string, candidateIds: list<string>} $row
     * @return list<string>
     */
    private function offeredCandidateIds(array $row): array
    {
        return array_values(array_filter(
            $row['candidateIds'],
            fn (string $candidateId): bool => !$this->hasPuzzle($candidateId, exceptRow: $row['key']),
        ));
    }

    /**
     * Codes in the tray that more of their puzzles can still join - scanning such a code again is no
     * duplicate, so the browser asks the server instead of answering "already in the tray" itself.
     *
     * @return list<string>
     */
    public function openSharedCodes(): array
    {
        $codes = [];

        foreach ($this->rows as $row) {
            if ($row['state'] !== 'resolved' || $row['candidateIds'] === []) {
                continue;
            }

            foreach ($row['candidateIds'] as $candidateId) {
                if (!$this->hasPuzzle($candidateId)) {
                    $codes[$row['ean']] = true;
                    break;
                }
            }
        }

        // A code with a row still waiting for a decision is a plain duplicate
        foreach ($this->rows as $row) {
            if ($row['state'] !== 'resolved') {
                unset($codes[$row['ean']]);
            }
        }

        return array_keys($codes);
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

    private function isFreeOrOwnPuzzleId(string $puzzleId, string $playerId): bool
    {
        $record = $this->getPuzzleRecord->byId($puzzleId);

        return $record === null || $record->addedById === $playerId;
    }

    private function hasPuzzle(string $puzzleId, null|string $exceptRow = null): bool
    {
        foreach ($this->rows as $row) {
            if ($row['puzzleId'] === $puzzleId && $row['key'] !== $exceptRow) {
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

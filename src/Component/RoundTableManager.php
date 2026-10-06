<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddRoundTable;
use SpeedPuzzling\Web\Message\AddTableRow;
use SpeedPuzzling\Web\Message\AddTableSpot;
use SpeedPuzzling\Web\Message\AssignPlayerToSpot;
use SpeedPuzzling\Web\Message\DeleteRoundTable;
use SpeedPuzzling\Web\Message\DeleteTableRow;
use SpeedPuzzling\Web\Message\DeleteTableSpot;
use SpeedPuzzling\Web\Query\GetTableLayoutForRound;
use SpeedPuzzling\Web\Query\SearchPlayers;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Results\TableLayoutRow;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent]
final class RoundTableManager
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $roundId = '';

    #[LiveProp]
    public string $competitionId = '';

    #[LiveProp(writable: true)]
    public string $searchQuery = '';

    #[LiveProp(writable: true)]
    public null|string $editingSpotId = null;

    /** @var array<TableLayoutRow> */
    public array $rows = [];

    public function __construct(
        private readonly GetTableLayoutForRound $getTableLayoutForRound,
        private readonly SearchPlayers $searchPlayers,
        private readonly MessageBusInterface $messageBus,
        private readonly Security $security,
    ) {
    }

    /**
     * The page checks the permission once; every Live request is a request of its own.
     */
    #[PostHydrate]
    public function denyAccessUnlessMaintainer(): void
    {
        if (!$this->security->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $this->competitionId)) {
            throw new AccessDeniedHttpException();
        }
    }

    #[PostMount]
    #[PreReRender]
    public function loadLayout(): void
    {
        $this->rows = $this->getTableLayoutForRound->byRoundId($this->roundId);
    }

    /**
     * @return list<PlayerIdentification>
     */
    public function getSearchResults(): array
    {
        $query = trim($this->searchQuery);

        if (strlen($query) < 2) {
            return [];
        }

        return $this->searchPlayers->fulltext($query, limit: 10, includeHidden: true);
    }

    #[LiveAction]
    public function addRow(): void
    {
        $this->messageBus->dispatch(new AddTableRow(
            rowId: Uuid::uuid7(),
            roundId: $this->roundId,
        ));
    }

    #[LiveAction]
    public function deleteRow(#[LiveArg] string $rowId): void
    {
        $this->assertPartOfThisRound('row', $rowId);

        $this->messageBus->dispatch(new DeleteTableRow(
            rowId: $rowId,
        ));
    }

    #[LiveAction]
    public function addTable(#[LiveArg] string $rowId): void
    {
        $this->assertPartOfThisRound('row', $rowId);

        $this->messageBus->dispatch(new AddRoundTable(
            tableId: Uuid::uuid7(),
            rowId: $rowId,
        ));
    }

    #[LiveAction]
    public function deleteTable(#[LiveArg] string $tableId): void
    {
        $this->assertPartOfThisRound('table', $tableId);

        $this->messageBus->dispatch(new DeleteRoundTable(
            tableId: $tableId,
        ));
    }

    #[LiveAction]
    public function addSpot(#[LiveArg] string $tableId): void
    {
        $this->assertPartOfThisRound('table', $tableId);

        $this->messageBus->dispatch(new AddTableSpot(
            spotId: Uuid::uuid7(),
            tableId: $tableId,
        ));
    }

    #[LiveAction]
    public function deleteSpot(#[LiveArg] string $spotId): void
    {
        $this->assertPartOfThisRound('spot', $spotId);

        $this->messageBus->dispatch(new DeleteTableSpot(
            spotId: $spotId,
        ));
    }

    #[LiveAction]
    public function startEditSpot(#[LiveArg] string $spotId): void
    {
        $this->assertPartOfThisRound('spot', $spotId);

        $this->editingSpotId = $spotId;
        $this->searchQuery = '';
    }

    #[LiveAction]
    public function assignPlayer(#[LiveArg] string $spotId, #[LiveArg] string $playerId): void
    {
        $this->assertPartOfThisRound('spot', $spotId);

        $this->messageBus->dispatch(new AssignPlayerToSpot(
            spotId: $spotId,
            playerId: $playerId,
        ));
        $this->editingSpotId = null;
        $this->searchQuery = '';
    }

    #[LiveAction]
    public function assignManualName(#[LiveArg] string $spotId, #[LiveArg] string $playerName): void
    {
        $this->assertPartOfThisRound('spot', $spotId);

        $this->messageBus->dispatch(new AssignPlayerToSpot(
            spotId: $spotId,
            playerName: $playerName,
        ));
        $this->editingSpotId = null;
        $this->searchQuery = '';
    }

    #[LiveAction]
    public function clearSpot(#[LiveArg] string $spotId): void
    {
        $this->assertPartOfThisRound('spot', $spotId);

        $this->messageBus->dispatch(new AssignPlayerToSpot(
            spotId: $spotId,
        ));
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->editingSpotId = null;
        $this->searchQuery = '';
    }

    /**
     * A row, table or spot id coming from the browser must be part of this component's round -
     * the layout is read from the database, the page's $rows are not loaded during an action.
     *
     * @param 'row'|'table'|'spot' $kind
     */
    private function assertPartOfThisRound(string $kind, string $id): void
    {
        foreach ($this->getTableLayoutForRound->byRoundId($this->roundId) as $row) {
            if ($kind === 'row' && $row->id === $id) {
                return;
            }

            foreach ($row->tables as $table) {
                if ($kind === 'table' && $table->id === $id) {
                    return;
                }

                foreach ($table->spots as $spot) {
                    if ($kind === 'spot' && $spot->id === $id) {
                        return;
                    }
                }
            }
        }

        throw new NotFoundHttpException();
    }
}

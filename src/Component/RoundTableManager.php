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
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
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
    ) {
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

        return $this->searchPlayers->fulltext($query, limit: 10);
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
        $this->messageBus->dispatch(new DeleteTableRow(
            rowId: $rowId,
        ));
    }

    #[LiveAction]
    public function addTable(#[LiveArg] string $rowId): void
    {
        $this->messageBus->dispatch(new AddRoundTable(
            tableId: Uuid::uuid7(),
            rowId: $rowId,
        ));
    }

    #[LiveAction]
    public function deleteTable(#[LiveArg] string $tableId): void
    {
        $this->messageBus->dispatch(new DeleteRoundTable(
            tableId: $tableId,
        ));
    }

    #[LiveAction]
    public function addSpot(#[LiveArg] string $tableId): void
    {
        $this->messageBus->dispatch(new AddTableSpot(
            spotId: Uuid::uuid7(),
            tableId: $tableId,
        ));
    }

    #[LiveAction]
    public function deleteSpot(#[LiveArg] string $spotId): void
    {
        $this->messageBus->dispatch(new DeleteTableSpot(
            spotId: $spotId,
        ));
    }

    #[LiveAction]
    public function startEditSpot(#[LiveArg] string $spotId): void
    {
        $this->editingSpotId = $spotId;
        $this->searchQuery = '';
    }

    #[LiveAction]
    public function assignPlayer(#[LiveArg] string $spotId, #[LiveArg] string $playerId): void
    {
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
}

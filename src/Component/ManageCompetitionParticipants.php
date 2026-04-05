<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Message\AddCompetitionParticipant;
use SpeedPuzzling\Web\Message\EditCompetitionParticipant;
use SpeedPuzzling\Web\Message\RestoreCompetitionParticipant;
use SpeedPuzzling\Web\Message\SoftDeleteCompetitionParticipant;
use SpeedPuzzling\Web\Query\GetCompetitionParticipantsForManagement;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Query\SearchPlayers;
use SpeedPuzzling\Web\Results\CompetitionRoundInfo;
use SpeedPuzzling\Web\Results\ManageableCompetitionParticipant;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent]
final class ManageCompetitionParticipants
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $competitionId = '';

    #[LiveProp(writable: true)]
    public null|string $editingParticipantId = null;

    #[LiveProp(writable: true)]
    public string $searchQuery = '';

    #[LiveProp(writable: true)]
    public bool $showDeleted = false;

    #[LiveProp(writable: true)]
    public bool $showAddForm = false;

    // Edit fields
    #[LiveProp(writable: true)]
    public string $editName = '';

    #[LiveProp(writable: true)]
    public string $editCountry = '';

    #[LiveProp(writable: true)]
    public string $editExternalId = '';

    /** @var array<string> */
    #[LiveProp(writable: true)]
    public array $editRoundIds = [];

    // Add fields
    #[LiveProp(writable: true)]
    public string $addName = '';

    #[LiveProp(writable: true)]
    public string $addCountry = '';

    #[LiveProp(writable: true)]
    public string $addExternalId = '';

    // Player search for edit
    #[LiveProp(writable: true)]
    public string $playerSearchQuery = '';

    #[LiveProp(writable: true)]
    public null|string $editPlayerId = null;

    #[LiveProp(writable: true)]
    public null|string $editPlayerName = null;

    // Player search for add
    #[LiveProp(writable: true)]
    public string $addPlayerSearchQuery = '';

    #[LiveProp(writable: true)]
    public null|string $addPlayerId = null;

    #[LiveProp(writable: true)]
    public null|string $addPlayerName = null;

    /** @var array<ManageableCompetitionParticipant> */
    public array $participants = [];

    /** @var array<string, CompetitionRoundInfo> */
    public array $competitionRounds = [];

    public int $activeCount = 0;
    public int $deletedCount = 0;

    public function __construct(
        private readonly GetCompetitionParticipantsForManagement $getParticipants,
        private readonly GetCompetitionRounds $getCompetitionRounds,
        private readonly SearchPlayers $searchPlayers,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[PostMount]
    #[PreReRender]
    public function loadData(): void
    {
        $all = $this->getParticipants->all($this->competitionId, includeDeleted: true);
        $this->competitionRounds = $this->getCompetitionRounds->ofCompetition($this->competitionId);

        $this->activeCount = 0;
        $this->deletedCount = 0;

        foreach ($all as $p) {
            if ($p->isDeleted()) {
                $this->deletedCount++;
            } else {
                $this->activeCount++;
            }
        }

        if ($this->showDeleted) {
            $this->participants = $all;
        } else {
            $this->participants = array_filter($all, static fn (ManageableCompetitionParticipant $p): bool => !$p->isDeleted());
            $this->participants = array_values($this->participants);
        }
    }

    /**
     * @return list<PlayerIdentification>
     */
    public function getEditSearchResults(): array
    {
        $query = trim($this->playerSearchQuery);

        if (strlen($query) < 2) {
            return [];
        }

        return $this->searchPlayers->fulltext($query, limit: 10);
    }

    /**
     * @return list<PlayerIdentification>
     */
    public function getAddSearchResults(): array
    {
        $query = trim($this->addPlayerSearchQuery);

        if (strlen($query) < 2) {
            return [];
        }

        return $this->searchPlayers->fulltext($query, limit: 10);
    }

    #[LiveAction]
    public function startEdit(#[LiveArg] string $participantId): void
    {
        $this->editingParticipantId = $participantId;
        $this->playerSearchQuery = '';

        foreach ($this->participants as $p) {
            if ($p->participantId === $participantId) {
                $this->editName = $p->participantName;
                $this->editCountry = $p->participantCountry !== null ? $p->participantCountry->name : '';
                $this->editExternalId = $p->externalId ?? '';
                $this->editPlayerId = $p->playerId;
                $this->editPlayerName = $p->playerName ?? $p->playerCode;
                $this->editRoundIds = $p->roundIds;
                break;
            }
        }
    }

    #[LiveAction]
    public function saveEdit(): void
    {
        if ($this->editingParticipantId === null || $this->editName === '') {
            return;
        }

        $this->messageBus->dispatch(new EditCompetitionParticipant(
            participantId: $this->editingParticipantId,
            name: $this->editName,
            country: $this->editCountry !== '' ? $this->editCountry : null,
            externalId: $this->editExternalId !== '' ? $this->editExternalId : null,
            playerId: $this->editPlayerId,
            roundIds: $this->editRoundIds,
        ));

        $this->editingParticipantId = null;
        $this->playerSearchQuery = '';
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->editingParticipantId = null;
        $this->playerSearchQuery = '';
    }

    #[LiveAction]
    public function selectEditPlayer(#[LiveArg] string $playerId, #[LiveArg] string $playerName): void
    {
        $this->editPlayerId = $playerId;
        $this->editPlayerName = $playerName;
        $this->playerSearchQuery = '';
    }

    #[LiveAction]
    public function clearEditPlayer(): void
    {
        $this->editPlayerId = null;
        $this->editPlayerName = null;
    }

    #[LiveAction]
    public function toggleEditRound(#[LiveArg] string $roundId): void
    {
        $key = array_search($roundId, $this->editRoundIds, true);

        if ($key !== false) {
            unset($this->editRoundIds[$key]);
            $this->editRoundIds = array_values($this->editRoundIds);
        } else {
            $this->editRoundIds[] = $roundId;
        }
    }

    #[LiveAction]
    public function showAdd(): void
    {
        $this->showAddForm = true;
        $this->addName = '';
        $this->addCountry = '';
        $this->addExternalId = '';
        $this->addPlayerId = null;
        $this->addPlayerName = null;
        $this->addPlayerSearchQuery = '';
    }

    #[LiveAction]
    public function addParticipant(): void
    {
        if ($this->addName === '') {
            return;
        }

        $this->messageBus->dispatch(new AddCompetitionParticipant(
            competitionId: $this->competitionId,
            name: $this->addName,
            country: $this->addCountry !== '' ? $this->addCountry : null,
            externalId: $this->addExternalId !== '' ? $this->addExternalId : null,
            playerId: $this->addPlayerId,
        ));

        $this->showAddForm = false;
    }

    #[LiveAction]
    public function cancelAdd(): void
    {
        $this->showAddForm = false;
    }

    #[LiveAction]
    public function selectAddPlayer(#[LiveArg] string $playerId, #[LiveArg] string $playerName): void
    {
        $this->addPlayerId = $playerId;
        $this->addPlayerName = $playerName;
        $this->addPlayerSearchQuery = '';
    }

    #[LiveAction]
    public function clearAddPlayer(): void
    {
        $this->addPlayerId = null;
        $this->addPlayerName = null;
    }

    #[LiveAction]
    public function deleteParticipant(#[LiveArg] string $participantId): void
    {
        $this->messageBus->dispatch(new SoftDeleteCompetitionParticipant(
            participantId: $participantId,
        ));
    }

    #[LiveAction]
    public function restoreParticipant(#[LiveArg] string $participantId): void
    {
        $this->messageBus->dispatch(new RestoreCompetitionParticipant(
            participantId: $participantId,
        ));
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getCountryChoicesGroupedByRegion(): array
    {
        $centralEurope = [
            CountryCode::cz, CountryCode::sk, CountryCode::pl, CountryCode::hu,
            CountryCode::at, CountryCode::si, CountryCode::ch, CountryCode::li,
        ];

        $westernEurope = [
            CountryCode::de, CountryCode::fr, CountryCode::nl, CountryCode::be,
            CountryCode::lu, CountryCode::ie, CountryCode::gb, CountryCode::mc,
        ];

        $southernEurope = [
            CountryCode::es, CountryCode::pt, CountryCode::it, CountryCode::gr,
            CountryCode::hr, CountryCode::ba, CountryCode::rs, CountryCode::me,
            CountryCode::mk, CountryCode::al, CountryCode::mt, CountryCode::cy,
        ];

        $northernEurope = [
            CountryCode::se, CountryCode::no, CountryCode::dk, CountryCode::fi,
            CountryCode::is, CountryCode::ee, CountryCode::lv, CountryCode::lt,
        ];

        $easternEurope = [
            CountryCode::ro, CountryCode::bg, CountryCode::ua, CountryCode::md,
            CountryCode::by,
        ];

        $northAmerica = [
            CountryCode::us, CountryCode::ca, CountryCode::mx,
        ];

        $groups = [
            $this->translator->trans('sell_swap_list.settings.region.central_europe') => $centralEurope,
            $this->translator->trans('sell_swap_list.settings.region.western_europe') => $westernEurope,
            $this->translator->trans('sell_swap_list.settings.region.southern_europe') => $southernEurope,
            $this->translator->trans('sell_swap_list.settings.region.northern_europe') => $northernEurope,
            $this->translator->trans('sell_swap_list.settings.region.eastern_europe') => $easternEurope,
            $this->translator->trans('sell_swap_list.settings.region.north_america') => $northAmerica,
        ];

        $usedCodes = [];
        foreach ($groups as $countries) {
            foreach ($countries as $country) {
                $usedCodes[] = $country->name;
            }
        }

        $restOfWorld = [];
        foreach (CountryCode::cases() as $country) {
            if (!in_array($country->name, $usedCodes, true)) {
                $restOfWorld[] = $country;
            }
        }

        $groups[$this->translator->trans('sell_swap_list.settings.region.rest_of_world')] = $restOfWorld;

        $choices = [];
        foreach ($groups as $groupName => $countries) {
            foreach ($countries as $country) {
                $choices[$groupName][$country->name] = $country->value;
            }
        }

        return $choices;
    }
}

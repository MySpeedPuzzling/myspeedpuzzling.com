<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Exceptions\OfficialResultsProtected;
use SpeedPuzzling\Web\Message\AddCompetitionParticipant;
use SpeedPuzzling\Web\Message\EditCompetitionParticipant;
use SpeedPuzzling\Web\Message\MarkParticipantPaid;
use SpeedPuzzling\Web\Message\PromoteParticipantFromWaitlist;
use SpeedPuzzling\Web\Message\RestoreCompetitionParticipant;
use SpeedPuzzling\Web\Message\SoftDeleteCompetitionParticipant;
use SpeedPuzzling\Web\Message\UnmarkParticipantPaid;
use SpeedPuzzling\Web\Query\GetCompetitionParticipantsForManagement;
use SpeedPuzzling\Web\Query\GetCompetitionRegistrationOverview;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Query\SearchPlayers;
use SpeedPuzzling\Web\Results\CompetitionRoundInfo;
use SpeedPuzzling\Web\Results\ManageableCompetitionParticipant;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Results\RegistrationOverview;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent]
final class ManageCompetitionParticipants
{
    use DefaultActionTrait;

    // Why the last save or removal was refused (official results it would lose) - shown once, for this render only
    public null|string $protectedMessage = null;

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

    #[LiveProp(writable: true)]
    public string $statusFilter = '';

    // Edit fields
    #[LiveProp(writable: true)]
    public string $editName = '';

    #[LiveProp(writable: true)]
    public string $editCountry = '';

    #[LiveProp(writable: true)]
    public string $editExternalId = '';

    #[LiveProp(writable: true)]
    public string $editOrganizerNote = '';

    /** @var array<string> */
    #[LiveProp(writable: true)]
    public array $editRoundIds = [];

    #[LiveProp]
    public bool $editNameMissing = false;

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
    public int $reservedCount = 0;
    public int $paidCount = 0;
    public int $waitlistedCount = 0;

    public null|RegistrationOverview $registration = null;

    public function __construct(
        private readonly GetCompetitionParticipantsForManagement $getParticipants,
        private readonly GetCompetitionRounds $getCompetitionRounds,
        private readonly GetCompetitionRegistrationOverview $getRegistrationOverview,
        private readonly SearchPlayers $searchPlayers,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
    ) {
    }

    /**
     * The page checks the permission once, but every Live request is a request of its own:
     * a maintainer removed from the event (or anybody replaying the component's props) must not
     * keep changing its participants.
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
    public function loadData(): void
    {
        $all = $this->getParticipants->all($this->competitionId, includeDeleted: true);
        $this->competitionRounds = $this->getCompetitionRounds->ofCompetition($this->competitionId);
        $this->registration = $this->getRegistrationOverview->forCompetition($this->competitionId, null);

        $this->activeCount = 0;
        $this->deletedCount = 0;
        $this->reservedCount = 0;
        $this->paidCount = 0;
        $this->waitlistedCount = 0;

        foreach ($all as $p) {
            if ($p->isDeleted()) {
                $this->deletedCount++;

                continue;
            }

            $this->activeCount++;

            // Legacy participants without explicit status behave as reserved
            $status = $p->registrationStatus ?? RegistrationStatus::Reserved;

            if ($status === RegistrationStatus::Paid) {
                $this->paidCount++;
            } elseif ($status === RegistrationStatus::Waitlisted) {
                $this->waitlistedCount++;
            } else {
                $this->reservedCount++;
            }
        }

        if ($this->showDeleted) {
            $this->participants = $all;
        } else {
            $this->participants = array_filter($all, static fn (ManageableCompetitionParticipant $p): bool => !$p->isDeleted());
            $this->participants = array_values($this->participants);
        }

        if ($this->statusFilter !== '' && $this->registration->registrationManaged === true) {
            $filter = RegistrationStatus::tryFrom($this->statusFilter);

            if ($filter !== null) {
                // Legacy participants without explicit status behave as reserved
                $this->participants = array_values(array_filter(
                    $this->participants,
                    static fn (ManageableCompetitionParticipant $p): bool =>
                        ($p->registrationStatus ?? RegistrationStatus::Reserved) === $filter,
                ));
            }
        }
    }

    public function hasPromotableSpot(): bool
    {
        if ($this->registration === null || $this->registration->registrationManaged === false) {
            return false;
        }

        if ($this->registration->waitlistedCount === 0) {
            return false;
        }

        return $this->registration->capacity === null
            || $this->registration->spotsTaken < $this->registration->capacity;
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

        return $this->searchPlayers->fulltext($query, limit: 10, includeHidden: true);
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

        return $this->searchPlayers->fulltext($query, limit: 10, includeHidden: true);
    }

    /**
     * The form is filled from the database, never from the list rendered for the page: actions
     * run on a freshly hydrated component whose list is not loaded yet, and every value left
     * over from editing another participant must be replaced - a save writes all of them.
     */
    #[LiveAction]
    public function startEdit(#[LiveArg] string $participantId): void
    {
        $participant = $this->getParticipants->byId($this->competitionId, $participantId);

        $this->editingParticipantId = $participant->participantId;
        $this->editName = $participant->participantName;
        $this->editCountry = $participant->participantCountry !== null ? $participant->participantCountry->name : '';
        $this->editExternalId = $participant->externalId ?? '';
        $this->editOrganizerNote = $participant->organizerNote ?? '';
        $this->editPlayerId = $participant->playerId;
        $this->editPlayerName = $participant->playerName ?? $participant->playerCode;
        $this->editRoundIds = array_values($participant->roundIds);
        $this->editNameMissing = false;
        $this->playerSearchQuery = '';
    }

    #[LiveAction]
    public function saveEdit(): void
    {
        if ($this->editingParticipantId === null) {
            return;
        }

        // Throws for a participant of another competition
        $participant = $this->getParticipants->byId($this->competitionId, $this->editingParticipantId);

        $name = trim($this->editName);

        if ($name === '') {
            $this->editNameMissing = true;

            return;
        }

        try {
            $this->messageBus->dispatch(new EditCompetitionParticipant(
                participantId: $participant->participantId,
                name: $name,
                country: CountryCode::fromCode($this->editCountry)?->name,
                externalId: trim($this->editExternalId) !== '' ? trim($this->editExternalId) : null,
                playerId: $this->editPlayerId,
                roundIds: array_values(array_unique($this->editRoundIds)),
                organizerNote: trim($this->editOrganizerNote) !== '' ? trim($this->editOrganizerNote) : null,
            ));
        } catch (OfficialResultsProtected $protected) {
            // Nothing saved - the form stays open with the reason
            $this->protectedMessage = $protected->translationKey();

            return;
        }

        $this->resetEditForm();
    }

    #[LiveAction]
    public function cancelEdit(): void
    {
        $this->resetEditForm();
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
        $name = trim($this->addName);

        if ($name === '') {
            return;
        }

        $this->messageBus->dispatch(new AddCompetitionParticipant(
            competitionId: $this->competitionId,
            name: $name,
            country: CountryCode::fromCode($this->addCountry)?->name,
            externalId: trim($this->addExternalId) !== '' ? trim($this->addExternalId) : null,
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
        // Throws for a participant of another competition
        $participant = $this->getParticipants->byId($this->competitionId, $participantId);

        try {
            $this->messageBus->dispatch(new SoftDeleteCompetitionParticipant(
                participantId: $participant->participantId,
            ));
        } catch (OfficialResultsProtected $protected) {
            $this->protectedMessage = $protected->translationKey();

            return;
        }

        if ($this->editingParticipantId === $participant->participantId) {
            $this->resetEditForm();
        }
    }

    #[LiveAction]
    public function restoreParticipant(#[LiveArg] string $participantId): void
    {
        // Throws for a participant of another competition
        $participant = $this->getParticipants->byId($this->competitionId, $participantId);

        $this->messageBus->dispatch(new RestoreCompetitionParticipant(
            participantId: $participant->participantId,
        ));
    }

    #[LiveAction]
    public function markPaid(#[LiveArg] string $participantId): void
    {
        // Throws for a participant of another competition
        $participant = $this->getParticipants->byId($this->competitionId, $participantId);

        $this->messageBus->dispatch(new MarkParticipantPaid(
            participantId: $participant->participantId,
        ));
    }

    #[LiveAction]
    public function unmarkPaid(#[LiveArg] string $participantId): void
    {
        // Throws for a participant of another competition
        $participant = $this->getParticipants->byId($this->competitionId, $participantId);

        $this->messageBus->dispatch(new UnmarkParticipantPaid(
            participantId: $participant->participantId,
        ));
    }

    #[LiveAction]
    public function promoteFromWaitlist(#[LiveArg] string $participantId): void
    {
        // Throws for a participant of another competition
        $participant = $this->getParticipants->byId($this->competitionId, $participantId);

        $this->messageBus->dispatch(new PromoteParticipantFromWaitlist(
            participantId: $participant->participantId,
        ));
    }

    /**
     * Nothing of a closed form may survive into the next one.
     */
    private function resetEditForm(): void
    {
        $this->editingParticipantId = null;
        $this->editName = '';
        $this->editCountry = '';
        $this->editExternalId = '';
        $this->editOrganizerNote = '';
        $this->editPlayerId = null;
        $this->editPlayerName = null;
        $this->editRoundIds = [];
        $this->editNameMissing = false;
        $this->playerSearchQuery = '';
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

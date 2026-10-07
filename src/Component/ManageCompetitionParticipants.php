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
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Query\SearchPlayers;
use SpeedPuzzling\Web\Results\CompetitionRoundInfo;
use SpeedPuzzling\Web\Results\ManageableCompetitionParticipant;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
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

    /**
     * Managed registration (docs/features/competitions-management/registration.md, D17) - set by the page from the
     * event, not writable: everything about registration on this component exists only when it is true. The handlers
     * check the event again, so a page opened before the organiser switched management off changes nothing.
     */
    #[LiveProp]
    public bool $registrationManaged = false;

    #[LiveProp]
    public null|int $capacity = null;

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

    /**
     * The round entries and the player the row was opened with - not writable. The save sends what this edit changed
     * against them (EditCompetitionParticipant), so whatever happened to the person meanwhile - advanced to a final and
     * seated by the results desk, connected by the player - stays.
     *
     * @var array<string>
     */
    #[LiveProp]
    public array $editOriginalRoundIds = [];

    #[LiveProp]
    public null|string $editOriginalPlayerId = null;

    #[LiveProp]
    public bool $editNameMissing = false;

    #[LiveProp]
    public bool $editOrganizerNoteTooLong = false;

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

    // The first in line of the waitlist (managed registration) - offered when a spot is free
    public null|ManageableCompetitionParticipant $nextInLine = null;

    public function __construct(
        private readonly GetCompetitionParticipantsForManagement $getParticipants,
        private readonly GetCompetitionRounds $getCompetitionRounds,
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

        $this->activeCount = 0;
        $this->deletedCount = 0;

        foreach ($all as $p) {
            if ($p->isDeleted()) {
                $this->deletedCount++;
            } else {
                $this->activeCount++;
            }
        }

        if ($this->registrationManaged) {
            // From the rows already loaded - managed registration costs this page no statement of its own
            $this->countRegistrations($all);
        }

        if ($this->showDeleted) {
            $this->participants = $all;
        } else {
            $this->participants = array_filter($all, static fn (ManageableCompetitionParticipant $p): bool => !$p->isDeleted());
            $this->participants = array_values($this->participants);
        }

        if ($this->statusFilter !== '' && $this->registrationManaged) {
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

    /**
     * Rows holding a spot - reserved, paid, and rows without a status (they were "going" before management).
     */
    public function spotsTaken(): int
    {
        return $this->reservedCount + $this->paidCount;
    }

    public function hasPromotableSpot(): bool
    {
        return $this->registrationManaged
            && $this->nextInLine !== null
            && ($this->capacity === null || $this->spotsTaken() < $this->capacity);
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
        $this->editOriginalRoundIds = array_values($participant->roundIds);
        $this->editOriginalPlayerId = $participant->playerId;
        $this->editNameMissing = false;
        $this->editOrganizerNoteTooLong = false;
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

        $organizerNote = trim($this->editOrganizerNote);

        if ($this->registrationManaged && mb_strlen($organizerNote) > CompetitionParticipant::ORGANIZER_NOTE_MAX_LENGTH) {
            $this->editOrganizerNoteTooLong = true;

            return;
        }

        $roundIds = array_values(array_unique($this->editRoundIds));

        try {
            $this->messageBus->dispatch(new EditCompetitionParticipant(
                competitionId: $this->competitionId,
                participantId: $participant->participantId,
                name: $name,
                country: CountryCode::fromCode($this->editCountry)?->name,
                externalId: trim($this->editExternalId) !== '' ? trim($this->editExternalId) : null,
                // Only what this edit changed - the row may be minutes old on a busy event day
                changePlayer: $this->editPlayerId !== $this->editOriginalPlayerId,
                playerId: $this->editPlayerId,
                addRoundIds: array_values(array_diff($roundIds, $this->editOriginalRoundIds)),
                removeRoundIds: array_values(array_diff($this->editOriginalRoundIds, $roundIds)),
                // The note is edited only while registration is managed - otherwise it stays as it is
                changeOrganizerNote: $this->registrationManaged,
                organizerNote: $organizerNote !== '' ? $organizerNote : null,
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
                competitionId: $this->competitionId,
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
            competitionId: $this->competitionId,
            participantId: $participant->participantId,
        ));
    }

    /**
     * Managed registration's row actions - each one its own message, never part of the row's save. The participant is
     * looked up in this event first (byId() throws for any other), the handler checks it again under the event's lock.
     */
    #[LiveAction]
    public function markPaid(#[LiveArg] string $participantId): void
    {
        $participant = $this->getParticipants->byId($this->competitionId, $participantId);

        $this->messageBus->dispatch(new MarkParticipantPaid(
            competitionId: $this->competitionId,
            participantId: $participant->participantId,
        ));
    }

    /**
     * Paid while on the waitlist: the organiser gives them a spot and confirms the payment in one step - explicitly.
     */
    #[LiveAction]
    public function promoteAndMarkPaid(#[LiveArg] string $participantId): void
    {
        $participant = $this->getParticipants->byId($this->competitionId, $participantId);

        $this->messageBus->dispatch(new MarkParticipantPaid(
            competitionId: $this->competitionId,
            participantId: $participant->participantId,
            promoteFromWaitlist: true,
        ));
    }

    #[LiveAction]
    public function unmarkPaid(#[LiveArg] string $participantId): void
    {
        $participant = $this->getParticipants->byId($this->competitionId, $participantId);

        $this->messageBus->dispatch(new UnmarkParticipantPaid(
            competitionId: $this->competitionId,
            participantId: $participant->participantId,
        ));
    }

    #[LiveAction]
    public function promoteFromWaitlist(#[LiveArg] string $participantId): void
    {
        $participant = $this->getParticipants->byId($this->competitionId, $participantId);

        $this->messageBus->dispatch(new PromoteParticipantFromWaitlist(
            competitionId: $this->competitionId,
            participantId: $participant->participantId,
        ));
    }

    /**
     * @param array<ManageableCompetitionParticipant> $participants
     */
    private function countRegistrations(array $participants): void
    {
        $this->reservedCount = 0;
        $this->paidCount = 0;
        $this->waitlistedCount = 0;
        $this->nextInLine = null;

        foreach ($participants as $participant) {
            if ($participant->isDeleted()) {
                continue;
            }

            // Rows without a status hold a spot - they count as reserved
            $status = $participant->registrationStatus ?? RegistrationStatus::Reserved;

            if ($status === RegistrationStatus::Paid) {
                $this->paidCount++;
            } elseif ($status === RegistrationStatus::Waitlisted) {
                $this->waitlistedCount++;

                if ($this->nextInLine === null || self::isAheadInLine($participant, $this->nextInLine)) {
                    $this->nextInLine = $participant;
                }
            } else {
                $this->reservedCount++;
            }
        }
    }

    private static function isAheadInLine(ManageableCompetitionParticipant $participant, ManageableCompetitionParticipant $other): bool
    {
        $registeredAt = $participant->registeredAt?->format('Y-m-d H:i:s.u') ?? '';
        $otherRegisteredAt = $other->registeredAt?->format('Y-m-d H:i:s.u') ?? '';

        return [$registeredAt, $participant->participantId] < [$otherRegisteredAt, $other->participantId];
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
        $this->editOriginalRoundIds = [];
        $this->editOriginalPlayerId = null;
        $this->editNameMissing = false;
        $this->editOrganizerNoteTooLong = false;
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

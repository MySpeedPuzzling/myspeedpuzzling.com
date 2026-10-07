<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\RoundResults;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\PuzzlingTeam;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\GetPublishedRoundResults;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\OfficialEntryTime;
use SpeedPuzzling\Web\Results\PublishedRoundEntrant;
use SpeedPuzzling\Web\Results\PublishedRoundEntry;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantNameKey;
use SpeedPuzzling\Web\Value\OfficialEntryProfileState;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\TeamComposition;

/**
 * "Add to my profile" of a round's published official results (docs/features/competitions-management/official-results.md):
 * the add-time form's `?official_entry=<participant_round|team>:<id>` read back on the server. The entry fills the form
 * in only when the round page offers exactly that to this viewer right now (GetPublishedRoundResults decides it for
 * both) - anything else is ignored silently, like an unknown `?competition=`.
 *
 * Nothing about the entry is stored: the player saves an ordinary time, and every rule of the add form (first tries,
 * duplicates, secret puzzles, privacy) runs as for any other.
 *
 * A pair/team entry carries the round's category: the form opens in Pair/Team mode with every person the organiser
 * recorded besides the viewer (linked ones by their code, the others as guests) - never as a solo time, also when the
 * organiser recorded a name only or fewer people than the round needs (the form then asks for them).
 *
 * A linked member hidden from the viewer is never filled in by their code (browser verification of PR #136, privacy):
 * a private player the viewer may not see (the round page shows them by the organiser's name only -
 * PrivateProfileAccess) or a player blocked in either direction comes as a guest under the organiser's participant
 * name, which the official results show anyway. A guest becomes that player only with their consent
 * (docs/features/pairs-and-teams/README.md, guest links).
 */
readonly final class OfficialEntryTimePrefill
{
    public function __construct(
        private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        private GetEditionRounds $getEditionRounds,
        private GetPublishedRoundResults $getPublishedRoundResults,
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private GetUserBlocks $getUserBlocks,
    ) {
    }

    /**
     * @param null|int $memberChoice `official_member`: in a pair/team nobody is linked to, the position of the member
     *                               the viewer said is them ("Which one are you?")
     */
    public function forViewer(string $competitionId, string $officialEntry, string $viewerPlayerId, null|string $viewerName, null|int $memberChoice = null): null|OfficialEntryTime
    {
        $ref = RoundEntryRef::tryFromString($officialEntry);

        if ($ref === null || $this->isCompetitionPubliclyVisible->check($competitionId) === false) {
            return null;
        }

        $entryRound = $this->getPublishedRoundResults->roundOfEntry($competitionId, $ref);

        if ($entryRound === null) {
            return null;
        }

        $round = null;
        foreach ($this->getEditionRounds->forCompetition($competitionId) as $candidate) {
            if ($candidate->id === $entryRound['round_id']) {
                $round = $candidate;
                break;
            }
        }

        if ($round === null) {
            return null;
        }

        $results = $this->getPublishedRoundResults->forRound($round, $viewerPlayerId);
        $entry = $results?->entry($ref->toString());

        if ($results === null || $results->profilePuzzleId === null || $entry === null || $entry->result->seconds === null || $entry->profileState !== OfficialEntryProfileState::Offer) {
            return null;
        }

        $category = $entry->isTeam ? $round->category : RoundCategory::Solo;
        [$groupPlayers, $memberChoices] = $entry->isTeam
            ? $this->coPuzzlers($entry, strtolower($viewerPlayerId), $viewerName, $memberChoice)
            : [[], []];

        return new OfficialEntryTime(
            puzzleId: $results->profilePuzzleId,
            seconds: $entry->result->seconds,
            finishedAt: self::roundDay($round),
            groupPlayers: $groupPlayers,
            teamName: $entry->isTeam ? $this->teamNameTheFormMaySet($entry, $groupPlayers, $viewerPlayerId) : null,
            roundName: $round->name,
            competitionName: $entryRound['competition_name'],
            // A pair/team row of a round whose category says solo cannot exist - but never a solo form for a team row
            category: $entry->isTeam && $category === RoundCategory::Solo ? RoundCategory::Team : $category,
            memberChoices: $memberChoices,
        );
    }

    /**
     * The members besides the viewer - linked ones by their code, the others as guests by the organiser's name - and,
     * when the viewer has to say which one they are, the names to pick from.
     *
     * In a pair/team nobody is linked to, the viewer is one of the names (the round page offers it only to a viewer
     * of one of the names - GetPublishedRoundResults): the one they picked (`$memberChoice`), else the one equal to
     * their own name (ParticipantNameKey, like the round page - one match only). Neither (two members of the viewer's
     * name): nobody is filled in (one of the names is the viewer - listing them all would make a pair of three) and the
     * names are offered to pick from.
     *
     * @return array{list<string>, list<string>}
     */
    private function coPuzzlers(PublishedRoundEntry $entry, string $viewerPlayerId, null|string $viewerName, null|int $memberChoice): array
    {
        $members = $entry->entrants;

        if ($entry->isViewers === false && $members !== []) {
            $viewerKey = $viewerName !== null && trim($viewerName) !== '' ? ParticipantNameKey::of($viewerName) : null;
            $matching = array_keys(array_filter(
                $members,
                static fn (PublishedRoundEntrant $member): bool => $viewerKey !== null && ParticipantNameKey::of($member->playerName) === $viewerKey,
            ));

            if ($memberChoice !== null && isset($members[$memberChoice])) {
                unset($members[$memberChoice]);
            } elseif (count($matching) === 1) {
                unset($members[$matching[0]]);
            } else {
                return [[], array_map(static fn (PublishedRoundEntrant $member): string => $member->playerName, $members)];
            }
        }

        $groupPlayers = [];
        $blockersOfViewer = null;

        foreach ($members as $member) {
            if ($member->linkedPlayerId === $viewerPlayerId) {
                continue;
            }

            if ($member->linkedPlayerId === null || $member->linkedPlayerCode === null) {
                $groupPlayers[] = $member->playerName;

                continue;
            }

            // Blocks the viewer: asked only when a linked member could be filled in by code - one query at most
            $blockersOfViewer ??= array_map(strtolower(...), $this->getUserBlocks->blockersOf([$viewerPlayerId]));

            $groupPlayers[] = $this->mayFillInByCode($member, $blockersOfViewer)
                ? '#' . $member->linkedPlayerCode
                : $member->playerName;
        }

        return [$groupPlayers, []];
    }

    /**
     * The round page shows the member's profile to the viewer (`playerId` - public, or a private player who lets the
     * viewer see them), and there is no block between the two in either direction.
     *
     * @param list<string> $blockersOfViewer
     */
    private function mayFillInByCode(PublishedRoundEntrant $member, array $blockersOfViewer): bool
    {
        return $member->playerId !== null
            && $this->hiddenPlayers->isHidden($member->linkedPlayerId) === false
            && in_array(strtolower((string) $member->linkedPlayerId), $blockersOfViewer, true) === false;
    }

    /**
     * The add form may only name a pair/team that has no name yet (PuzzlingTeam::nameIfUnnamed()) - so the organiser's
     * name is offered only when these exact people are no pair/team yet, or an unnamed one. Nobody filled in (a name
     * only, or the viewer still has to say who they are): the name comes along - the people they add decide, the save
     * names that pair/team only when it has no name yet.
     *
     * @param list<string> $groupPlayers
     */
    private function teamNameTheFormMaySet(PublishedRoundEntry $entry, array $groupPlayers, string $viewerPlayerId): null|string
    {
        $name = PuzzlingTeam::cleanName($entry->teamName);

        if ($name === null || $groupPlayers === []) {
            return $name;
        }

        $codes = [];
        foreach ($entry->entrants as $member) {
            if ($member->linkedPlayerId !== null && $member->linkedPlayerCode !== null) {
                $codes['#' . $member->linkedPlayerCode] = $member->linkedPlayerId;
            }
        }

        $puzzlers = [new Puzzler(playerId: $viewerPlayerId, playerName: null, playerCode: null, playerCountry: null, isPrivate: false)];
        foreach ($groupPlayers as $groupPlayer) {
            $puzzlers[] = isset($codes[$groupPlayer])
                ? new Puzzler(playerId: $codes[$groupPlayer], playerName: null, playerCode: null, playerCountry: null, isPrivate: false)
                : new Puzzler(playerId: null, playerName: $groupPlayer, playerCode: null, playerCountry: null, isPrivate: false);
        }

        $existingName = $this->database->fetchOne(
            'SELECT name FROM puzzling_team WHERE composition_key = :key',
            ['key' => TeamComposition::fromGroup(new PuzzlersGroup(null, $puzzlers))->key],
        );

        return is_string($existingName) ? null : $name;
    }

    /**
     * The round's day where it took place - the date the form's "finished" field shows.
     */
    private static function roundDay(EditionRoundDetail $round): DateTimeImmutable
    {
        $localDay = $round->startsAt->setTimezone(new DateTimeZone($round->timezone))->format('Y-m-d');

        return new DateTimeImmutable($localDay);
    }
}

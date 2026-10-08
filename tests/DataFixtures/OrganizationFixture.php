<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\FollowedCompetition;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Value\OrganizationKind;
use SpeedPuzzling\Web\Value\SocialLinks;

/**
 * Organizations and drafts (docs/features/organizations/implementation-plan.md 1.14): a published organization with two
 * series and a one-time event, every kind of draft, organizations waiting for approval. Made-up names. Dates are anchored
 * like EventsPageFixture: upcoming ones lie at least 20 days ahead, past ones in last year.
 */
final class OrganizationFixture extends Fixture implements DependentFixtureInterface
{
    // Published (approved, not a draft): created by PLAYER_WITH_STRIPE, maintained by PLAYER_WITH_FAVORITES
    public const string ORGANIZATION_RIVERBEND = '018d0042-0000-0000-0000-000000000001';
    public const string ORGANIZATION_RIVERBEND_NAME = 'Riverbend Jigsaw Association';
    public const string ORGANIZATION_RIVERBEND_SLUG = 'riverbend-jigsaw-association';
    // In person (us), under Riverbend: schedule "First Monday of the month, 7 pm", eligibility "21+"
    public const string SERIES_LANTERN_NIGHTS = '018d0042-0000-0000-0000-000000000002';
    public const string SERIES_LANTERN_NIGHTS_NAME = 'Lantern Brewing Puzzle Night';
    public const string SERIES_LANTERN_NIGHTS_SLUG = 'lantern-brewing-puzzle-night';
    // +22 and +50 days
    public const string EDITION_LANTERN_1 = '018d0042-0000-0000-0000-000000000003';
    public const string EDITION_LANTERN_2 = '018d0042-0000-0000-0000-000000000004';
    // +36 days, a draft edition in a published series
    public const string EDITION_LANTERN_DRAFT = '018d0042-0000-0000-0000-000000000005';
    public const string EDITION_LANTERN_DRAFT_NAME = 'Lantern Night Special';
    public const string EDITION_LANTERN_DRAFT_SLUG = 'lantern-night-special';
    // Online (us), under Riverbend: eligibility "Residents of Riverbend Valley", schedule "Third Wednesday…"
    public const string SERIES_RIVERBEND_VIRTUAL = '018d0042-0000-0000-0000-000000000006';
    public const string SERIES_RIVERBEND_VIRTUAL_NAME = 'Riverbend Virtual Contest';
    public const string SERIES_RIVERBEND_VIRTUAL_SLUG = 'riverbend-virtual-contest';
    // 15 June last year / +30 days
    public const string EDITION_VIRTUAL_PAST = '018d0042-0000-0000-0000-000000000007';
    public const string EDITION_VIRTUAL_NEXT = '018d0042-0000-0000-0000-000000000008';
    // One-time, in person (us), +60..+61 days, under Riverbend, eligibility "Residents of Riverbend Valley"
    public const string COMPETITION_RIVERBEND_OPEN = '018d0042-0000-0000-0000-000000000009';
    public const string COMPETITION_RIVERBEND_OPEN_NAME = 'Riverbend Spring Open';
    public const string COMPETITION_RIVERBEND_OPEN_SLUG = 'riverbend-spring-open';

    // One-time, in person (cz), +25 days, approved, a draft, no organization, created by PLAYER_WITH_STRIPE - would be
    // public once published
    public const string COMPETITION_DRAFT_NIGHT = '018d0042-0000-0000-0000-000000000010';
    public const string COMPETITION_DRAFT_NIGHT_NAME = 'Birchwood Puzzle Draft Night';
    public const string COMPETITION_DRAFT_NIGHT_SLUG = 'birchwood-puzzle-draft-night';
    // A solo round on it with PUZZLE_3000 (no other round and no round test uses it) - the puzzle page must not name the
    // draft
    public const string ROUND_DRAFT_NIGHT = '018d0042-0000-0000-0000-000000000011';
    public const string ROUND_PUZZLE_DRAFT_NIGHT = '018d0042-0000-0000-0000-000000000012';

    // In person (de), approved, a draft series, created by PLAYER_WITH_STRIPE; its edition (+27 days) is no draft itself
    public const string SERIES_QUIET_PINES_DRAFT = '018d0042-0000-0000-0000-000000000013';
    public const string SERIES_QUIET_PINES_DRAFT_NAME = 'Quiet Pines Puzzle Series';
    public const string SERIES_QUIET_PINES_DRAFT_SLUG = 'quiet-pines-puzzle-series';
    public const string EDITION_QUIET_PINES_1 = '018d0042-0000-0000-0000-000000000014';
    public const string EDITION_QUIET_PINES_1_NAME = 'Quiet Pines Evening 1';
    public const string EDITION_QUIET_PINES_1_SLUG = 'quiet-pines-evening-1';

    // A draft organization (club, ie, approved), created by PLAYER_WITH_STRIPE - it hides only itself: its published
    // series (+33 days edition) stays public. Ireland, not gb: PLAYER_WITH_STRIPE's home country must keep nothing planned
    // (EventsListUiTest)
    public const string ORGANIZATION_HARBOR_CLUB_DRAFT = '018d0042-0000-0000-0000-000000000015';
    public const string ORGANIZATION_HARBOR_CLUB_DRAFT_NAME = 'Harbor Puzzle Club';
    public const string ORGANIZATION_HARBOR_CLUB_DRAFT_SLUG = 'harbor-puzzle-club';
    public const string SERIES_HARBOR_CLUB_MEETS = '018d0042-0000-0000-0000-000000000016';
    public const string SERIES_HARBOR_CLUB_MEETS_NAME = 'Harbor Club Meets';
    public const string SERIES_HARBOR_CLUB_MEETS_SLUG = 'harbor-club-meets';
    public const string EDITION_HARBOR_CLUB_1 = '018d0042-0000-0000-0000-000000000017';

    // Waiting for approval (community, ca), created by PLAYER_WITH_FAVORITES; its series waits too, one edition +40 days
    public const string ORGANIZATION_MAPLE_PENDING = '018d0042-0000-0000-0000-000000000018';
    public const string ORGANIZATION_MAPLE_PENDING_NAME = 'Maple Leaf Puzzlers';
    public const string ORGANIZATION_MAPLE_PENDING_SLUG = 'maple-leaf-puzzlers';
    public const string SERIES_MAPLE_PENDING = '018d0042-0000-0000-0000-000000000019';
    public const string SERIES_MAPLE_PENDING_NAME = 'Maple Leaf Puzzle Evenings';
    public const string EDITION_MAPLE_PENDING_1 = '018d0042-0000-0000-0000-000000000020';

    // Waiting for approval AND a draft, created by PLAYER_WITH_FAVORITES - never in the approval queue
    public const string ORGANIZATION_CEDAR_PENDING_DRAFT = '018d0042-0000-0000-0000-000000000021';
    public const string ORGANIZATION_CEDAR_PENDING_DRAFT_NAME = 'Cedar Grove Puzzle Guild';
    public const string ORGANIZATION_CEDAR_PENDING_DRAFT_SLUG = 'cedar-grove-puzzle-guild';

    // One-time (at), +45 days, waiting for approval AND a draft, created by PLAYER_WITH_STRIPE - not in the queue, not on
    // the admin events page
    public const string COMPETITION_WILLOW_PENDING_DRAFT = '018d0042-0000-0000-0000-000000000022';
    public const string COMPETITION_WILLOW_PENDING_DRAFT_NAME = 'Willow Creek Draft Cup';

    // One-time (cz), 20 May last year, approved, a draft, created by PLAYER_WITH_STRIPE - not in the archive
    public const string COMPETITION_DRAFT_PAST = '018d0042-0000-0000-0000-000000000023';
    public const string COMPETITION_DRAFT_PAST_NAME = 'Old Harbor Draft Classic';

    // PLAYER_REGULAR follows Riverbend, the Lantern nights (also through Riverbend - deduplication) and Quiet Pines (a row
    // from before the series went back to draft - a draft never reaches "Your events")
    public const string FOLLOW_REGULAR_RIVERBEND = '018d0042-0000-0000-0000-000000000024';
    public const string FOLLOW_REGULAR_LANTERN = '018d0042-0000-0000-0000-000000000025';
    public const string FOLLOW_REGULAR_QUIET_PINES = '018d0042-0000-0000-0000-000000000026';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();
        $utc = new DateTimeZone('UTC');
        $today = new DateTimeImmutable($now->setTimezone($utc)->format('Y-m-d'), $utc);
        $lastYear = (int) $today->format('Y') - 1;

        $admin = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);
        $regular = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $stripe = $this->getReference(PlayerFixture::PLAYER_WITH_STRIPE, Player::class);
        $favorites = $this->getReference(PlayerFixture::PLAYER_WITH_FAVORITES, Player::class);

        // Riverbend Jigsaw Association - published
        $riverbend = new Organization(
            id: Uuid::fromString(self::ORGANIZATION_RIVERBEND),
            name: self::ORGANIZATION_RIVERBEND_NAME,
            slug: self::ORGANIZATION_RIVERBEND_SLUG,
            createdAt: $now,
            shortName: 'RJA',
            about: "The puzzle association of Riverbend Valley.\nMonthly online contests and casual nights at local venues.",
            website: 'https://riverbend-jigsaw.example',
            links: new SocialLinks(['https://www.instagram.com/riverbendjigsaw', 'https://discord.gg/riverbendjigsaw']),
            countryCode: 'us',
            region: 'Riverbend Valley',
            kind: OrganizationKind::Association,
            addedByPlayer: $stripe,
            approvedAt: $now,
            approvedByPlayer: $admin,
        );
        $riverbend->maintainers->add($favorites);
        $manager->persist($riverbend);
        $this->addReference(self::ORGANIZATION_RIVERBEND, $riverbend);

        $lantern = $this->series(self::SERIES_LANTERN_NIGHTS, self::SERIES_LANTERN_NIGHTS_NAME, self::SERIES_LANTERN_NIGHTS_SLUG, false, 'Riverbend', 'us', $stripe, $now, $admin, organization: $riverbend, eligibility: '21+', schedule: 'First Monday of the month, 7 pm');
        $manager->persist($lantern);
        $this->addReference(self::SERIES_LANTERN_NIGHTS, $lantern);

        foreach ([[self::EDITION_LANTERN_1, 'Lantern Night One', 'lantern-night-one', 22], [self::EDITION_LANTERN_2, 'Lantern Night Two', 'lantern-night-two', 50]] as [$id, $name, $slug, $days]) {
            $date = $today->modify('+' . $days . ' days');
            $edition = $this->competition($id, $name, $slug, 'Riverbend', 'us', $date, $date, series: $lantern);
            $manager->persist($edition);
            $this->addReference($id, $edition);
        }

        $lanternDraftDate = $today->modify('+36 days');
        $lanternDraft = $this->competition(self::EDITION_LANTERN_DRAFT, self::EDITION_LANTERN_DRAFT_NAME, self::EDITION_LANTERN_DRAFT_SLUG, 'Riverbend', 'us', $lanternDraftDate, $lanternDraftDate, series: $lantern, isDraft: true);
        $manager->persist($lanternDraft);
        $this->addReference(self::EDITION_LANTERN_DRAFT, $lanternDraft);

        $virtual = $this->series(self::SERIES_RIVERBEND_VIRTUAL, self::SERIES_RIVERBEND_VIRTUAL_NAME, self::SERIES_RIVERBEND_VIRTUAL_SLUG, true, null, 'us', $stripe, $now, $admin, organization: $riverbend, eligibility: 'Residents of Riverbend Valley', schedule: 'Third Wednesday of the month, 6:45 pm');
        $manager->persist($virtual);
        $this->addReference(self::SERIES_RIVERBEND_VIRTUAL, $virtual);

        $virtualPastDate = new DateTimeImmutable($lastYear . '-06-15', $utc);
        $virtualPast = $this->competition(self::EDITION_VIRTUAL_PAST, 'Virtual Contest 1', 'virtual-contest-1', null, null, $virtualPastDate, $virtualPastDate, isOnline: true, series: $virtual);
        $manager->persist($virtualPast);
        $this->addReference(self::EDITION_VIRTUAL_PAST, $virtualPast);

        $virtualNextDate = $today->modify('+30 days');
        $virtualNext = $this->competition(self::EDITION_VIRTUAL_NEXT, 'Virtual Contest 2', 'virtual-contest-2', null, null, $virtualNextDate, $virtualNextDate, isOnline: true, series: $virtual);
        $manager->persist($virtualNext);
        $this->addReference(self::EDITION_VIRTUAL_NEXT, $virtualNext);

        $open = $this->competition(self::COMPETITION_RIVERBEND_OPEN, self::COMPETITION_RIVERBEND_OPEN_NAME, self::COMPETITION_RIVERBEND_OPEN_SLUG, 'Riverbend', 'us', $today->modify('+60 days'), $today->modify('+61 days'), approvedAt: $now, addedBy: $stripe, organization: $riverbend, eligibility: 'Residents of Riverbend Valley');
        $manager->persist($open);
        $this->addReference(self::COMPETITION_RIVERBEND_OPEN, $open);

        // Birchwood Puzzle Draft Night - an approved one-time draft with a round
        $draftNightDate = $today->modify('+25 days');
        $draftNight = $this->competition(self::COMPETITION_DRAFT_NIGHT, self::COMPETITION_DRAFT_NIGHT_NAME, self::COMPETITION_DRAFT_NIGHT_SLUG, 'Birchwood', 'cz', $draftNightDate, $draftNightDate, approvedAt: $now, addedBy: $stripe, isDraft: true);
        $manager->persist($draftNight);
        $this->addReference(self::COMPETITION_DRAFT_NIGHT, $draftNight);

        $draftNightRound = new CompetitionRound(
            id: Uuid::fromString(self::ROUND_DRAFT_NIGHT),
            competition: $draftNight,
            name: 'Main round',
            minutesLimit: 90,
            startsAt: new DateTimeImmutable($draftNightDate->format('Y-m-d') . ' 19:00', new DateTimeZone('Europe/Prague'))->setTimezone($utc),
            slug: 'main-round',
            timezone: 'Europe/Prague',
        );
        $manager->persist($draftNightRound);
        $this->addReference(self::ROUND_DRAFT_NIGHT, $draftNightRound);

        $manager->persist(new CompetitionRoundPuzzle(
            id: Uuid::fromString(self::ROUND_PUZZLE_DRAFT_NIGHT),
            round: $draftNightRound,
            puzzle: $this->getReference(PuzzleFixture::PUZZLE_3000, Puzzle::class),
        ));

        // Quiet Pines Puzzle Series - a draft series, its edition hidden with it
        $quietPines = $this->series(self::SERIES_QUIET_PINES_DRAFT, self::SERIES_QUIET_PINES_DRAFT_NAME, self::SERIES_QUIET_PINES_DRAFT_SLUG, false, 'Quiet Pines', 'de', $stripe, $now, $admin, isDraft: true);
        $manager->persist($quietPines);
        $this->addReference(self::SERIES_QUIET_PINES_DRAFT, $quietPines);

        $quietPinesDate = $today->modify('+27 days');
        $quietPinesEdition = $this->competition(self::EDITION_QUIET_PINES_1, self::EDITION_QUIET_PINES_1_NAME, self::EDITION_QUIET_PINES_1_SLUG, 'Quiet Pines', 'de', $quietPinesDate, $quietPinesDate, series: $quietPines);
        $manager->persist($quietPinesEdition);
        $this->addReference(self::EDITION_QUIET_PINES_1, $quietPinesEdition);

        // Harbor Puzzle Club - a draft organization with a published series
        $harborClub = new Organization(
            id: Uuid::fromString(self::ORGANIZATION_HARBOR_CLUB_DRAFT),
            name: self::ORGANIZATION_HARBOR_CLUB_DRAFT_NAME,
            slug: self::ORGANIZATION_HARBOR_CLUB_DRAFT_SLUG,
            createdAt: $now,
            countryCode: 'ie',
            region: 'Harborside',
            kind: OrganizationKind::Club,
            isDraft: true,
            addedByPlayer: $stripe,
            approvedAt: $now,
            approvedByPlayer: $admin,
        );
        $manager->persist($harborClub);
        $this->addReference(self::ORGANIZATION_HARBOR_CLUB_DRAFT, $harborClub);

        $harborMeets = $this->series(self::SERIES_HARBOR_CLUB_MEETS, self::SERIES_HARBOR_CLUB_MEETS_NAME, self::SERIES_HARBOR_CLUB_MEETS_SLUG, false, 'Harborside', 'ie', $stripe, $now, $admin, organization: $harborClub);
        $manager->persist($harborMeets);
        $this->addReference(self::SERIES_HARBOR_CLUB_MEETS, $harborMeets);

        $harborMeetDate = $today->modify('+33 days');
        $harborMeet = $this->competition(self::EDITION_HARBOR_CLUB_1, 'Harbor Club Meet 1', 'harbor-club-meet-1', 'Harborside', 'ie', $harborMeetDate, $harborMeetDate, series: $harborMeets);
        $manager->persist($harborMeet);
        $this->addReference(self::EDITION_HARBOR_CLUB_1, $harborMeet);

        // Maple Leaf Puzzlers - waiting for approval, with a series waiting too
        $maple = new Organization(
            id: Uuid::fromString(self::ORGANIZATION_MAPLE_PENDING),
            name: self::ORGANIZATION_MAPLE_PENDING_NAME,
            slug: self::ORGANIZATION_MAPLE_PENDING_SLUG,
            createdAt: $now,
            countryCode: 'ca',
            region: 'Maple Grove',
            kind: OrganizationKind::Community,
            addedByPlayer: $favorites,
        );
        $manager->persist($maple);
        $this->addReference(self::ORGANIZATION_MAPLE_PENDING, $maple);

        $mapleSeries = $this->series(self::SERIES_MAPLE_PENDING, self::SERIES_MAPLE_PENDING_NAME, 'maple-leaf-puzzle-evenings', false, 'Maple Grove', 'ca', $favorites, $now, null, organization: $maple);
        $manager->persist($mapleSeries);
        $this->addReference(self::SERIES_MAPLE_PENDING, $mapleSeries);

        $mapleDate = $today->modify('+40 days');
        $mapleEdition = $this->competition(self::EDITION_MAPLE_PENDING_1, 'Maple Evening 1', 'maple-evening-1', 'Maple Grove', 'ca', $mapleDate, $mapleDate, series: $mapleSeries);
        $manager->persist($mapleEdition);
        $this->addReference(self::EDITION_MAPLE_PENDING_1, $mapleEdition);

        // Cedar Grove Puzzle Guild - waiting for approval and a draft
        $cedar = new Organization(
            id: Uuid::fromString(self::ORGANIZATION_CEDAR_PENDING_DRAFT),
            name: self::ORGANIZATION_CEDAR_PENDING_DRAFT_NAME,
            slug: self::ORGANIZATION_CEDAR_PENDING_DRAFT_SLUG,
            createdAt: $now,
            countryCode: 'us',
            kind: OrganizationKind::Club,
            isDraft: true,
            addedByPlayer: $favorites,
        );
        $manager->persist($cedar);
        $this->addReference(self::ORGANIZATION_CEDAR_PENDING_DRAFT, $cedar);

        // Willow Creek Draft Cup - waiting for approval and a draft
        $willowDate = $today->modify('+45 days');
        $willow = $this->competition(self::COMPETITION_WILLOW_PENDING_DRAFT, self::COMPETITION_WILLOW_PENDING_DRAFT_NAME, 'willow-creek-draft-cup', 'Willow Creek', 'at', $willowDate, $willowDate, addedBy: $stripe, isDraft: true);
        $manager->persist($willow);
        $this->addReference(self::COMPETITION_WILLOW_PENDING_DRAFT, $willow);

        // Old Harbor Draft Classic - an approved draft from last year
        $draftPastDate = new DateTimeImmutable($lastYear . '-05-20', $utc);
        $draftPast = $this->competition(self::COMPETITION_DRAFT_PAST, self::COMPETITION_DRAFT_PAST_NAME, 'old-harbor-draft-classic', 'Old Harbor', 'cz', $draftPastDate, $draftPastDate, approvedAt: $now, addedBy: $stripe, isDraft: true);
        $manager->persist($draftPast);
        $this->addReference(self::COMPETITION_DRAFT_PAST, $draftPast);

        $manager->persist(FollowedCompetition::ofOrganization(Uuid::fromString(self::FOLLOW_REGULAR_RIVERBEND), $regular, $riverbend, $now));
        $manager->persist(FollowedCompetition::ofSeries(Uuid::fromString(self::FOLLOW_REGULAR_LANTERN), $regular, $lantern, $now));
        $manager->persist(FollowedCompetition::ofSeries(Uuid::fromString(self::FOLLOW_REGULAR_QUIET_PINES), $regular, $quietPines, $now));

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            PuzzleFixture::class,
            EventsPageFixture::class,
        ];
    }

    private function series(
        string $id,
        string $name,
        string $slug,
        bool $isOnline,
        null|string $location,
        string $countryCode,
        Player $addedBy,
        DateTimeImmutable $now,
        null|Player $approvedBy,
        null|Organization $organization = null,
        bool $isDraft = false,
        null|string $eligibility = null,
        null|string $schedule = null,
    ): CompetitionSeries {
        return new CompetitionSeries(
            id: Uuid::fromString($id),
            name: $name,
            slug: $slug,
            logo: null,
            description: null,
            link: null,
            isOnline: $isOnline,
            location: $location,
            locationCountryCode: $countryCode,
            addedByPlayer: $addedBy,
            approvedAt: $approvedBy !== null ? $now : null,
            approvedByPlayer: $approvedBy,
            createdAt: $now,
            organization: $organization,
            isDraft: $isDraft,
            eligibility: $eligibility,
            schedule: $schedule,
        );
    }

    private function competition(
        string $id,
        string $name,
        string $slug,
        null|string $location,
        null|string $countryCode,
        null|DateTimeImmutable $dateFrom,
        null|DateTimeImmutable $dateTo,
        bool $isOnline = false,
        null|CompetitionSeries $series = null,
        null|DateTimeImmutable $approvedAt = null,
        null|Player $addedBy = null,
        null|Organization $organization = null,
        bool $isDraft = false,
        null|string $eligibility = null,
    ): Competition {
        return new Competition(
            id: Uuid::fromString($id),
            name: $name,
            slug: $slug,
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: $location,
            locationCountryCode: $countryCode,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            tag: null,
            isOnline: $isOnline,
            series: $series,
            addedByPlayer: $addedBy,
            approvedAt: $approvedAt,
            createdAt: $this->clock->now(),
            organization: $organization,
            isDraft: $isDraft,
            eligibility: $eligibility,
        );
    }
}

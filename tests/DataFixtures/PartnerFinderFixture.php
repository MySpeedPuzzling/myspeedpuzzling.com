<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\PartnerListing;
use SpeedPuzzling\Web\Entity\PartnerRequest;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Results\PartnerStrengthsNormalized;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\PartnerGoal;
use SpeedPuzzling\Web\Value\PartnerKind;
use SpeedPuzzling\Web\Value\PartnerListingClosedReason;
use SpeedPuzzling\Web\Value\PartnerStrength;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * Partner finder (docs/features/partner-finder.md, .claude/fixtures.md "Partner finder"): one qualifying in-person event
 * with a pair round and a team round, PLAYER_REGULAR looking for a pair, PLAYER_WITH_FAVORITES' group of two looking for
 * two more, PLAYER_WITH_STRIPE's withdrawn listing and their request to PLAYER_REGULAR.
 */
final class PartnerFinderFixture extends Fixture implements DependentFixtureInterface
{
    /** Qualifying one-time in-person event, +35 days, Pardubice (cz), plain registration ("I'm going") */
    public const string COMPETITION_PARTNER_FINDER_OPEN = '018d0070-0000-0000-0000-000000000001';
    public const string COMPETITION_PARTNER_FINDER_OPEN_SLUG = 'partner-finder-open';

    public const string ROUND_PAIRS = '018d0070-0000-0000-0000-000000000101';
    /** Team round, expected team size 4 */
    public const string ROUND_TEAMS = '018d0070-0000-0000-0000-000000000102';

    public const string PARTICIPANT_REGULAR = '018d0070-0000-0000-0000-000000000011';
    public const string PARTICIPANT_WITH_FAVORITES = '018d0070-0000-0000-0000-000000000012';

    /** Open: PLAYER_REGULAR on his own, looking for a pair; Edges + Sky and the custom chip "Dark puzzles" */
    public const string LISTING_REGULAR_PAIR = '018d0070-0000-0000-0000-000000000021';
    /** Open: PLAYER_WITH_FAVORITES, a group of 2 (with PLAYER_ADMIN) looking for a team, needs 2 more */
    public const string LISTING_FAVORITES_TEAM = '018d0070-0000-0000-0000-000000000022';
    /** Closed (withdrawn): PLAYER_WITH_STRIPE's own listing for a pair - she is not going (a marketplace seller: keeps the marketplace fixtures as they are) */
    public const string LISTING_STRIPE_CLOSED = '018d0070-0000-0000-0000-000000000023';

    /** PLAYER_WITH_STRIPE → LISTING_REGULAR_PAIR, a pair, not replied */
    public const string REQUEST_STRIPE_TO_REGULAR = '018d0070-0000-0000-0000-000000000031';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $regular = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $withFavorites = $this->getReference(PlayerFixture::PLAYER_WITH_FAVORITES, Player::class);
        $withStripe = $this->getReference(PlayerFixture::PLAYER_WITH_STRIPE, Player::class);
        $admin = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);

        $now = $this->clock->now();
        $dateFrom = $now->modify('+35 days')->setTime(9, 0);

        $competition = new Competition(
            id: Uuid::fromString(self::COMPETITION_PARTNER_FINDER_OPEN),
            name: 'Partner Finder Open',
            slug: self::COMPETITION_PARTNER_FINDER_OPEN_SLUG,
            shortcut: null,
            logo: null,
            description: 'Pairs and teams puzzle day',
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: 'Pardubice',
            locationCountryCode: 'cz',
            dateFrom: $dateFrom,
            dateTo: $dateFrom->setTime(18, 0),
            tag: null,
            isOnline: false,
            approvedAt: $now->modify('-10 days'),
            createdAt: $now->modify('-10 days'),
        );
        $manager->persist($competition);
        $this->addReference(self::COMPETITION_PARTNER_FINDER_OPEN, $competition);

        $this->round($manager, self::ROUND_PAIRS, $competition, 'Pairs', $dateFrom->setTime(10, 0), RoundCategory::Duo, 'pairs', null);
        $this->round($manager, self::ROUND_TEAMS, $competition, 'Teams', $dateFrom->setTime(14, 0), RoundCategory::Team, 'teams', 4);

        $this->going($manager, self::PARTICIPANT_REGULAR, 'John Doe', 'cz', $competition, $regular);
        $this->going($manager, self::PARTICIPANT_WITH_FAVORITES, 'Michael Johnson', 'de', $competition, $withFavorites);

        $regularPair = new PartnerListing(
            id: Uuid::fromString(self::LISTING_REGULAR_PAIR),
            competition: $competition,
            player: $regular,
            groupSize: 1,
            lookingFor: [PartnerKind::Pair],
            needed: null,
            coMemberIds: [],
            goal: PartnerGoal::TopResult,
            strengths: new PartnerStrengthsNormalized([PartnerStrength::Edges, PartnerStrength::Sky], ['Dark puzzles']),
            languages: ['cs', 'en'],
            note: 'Fast on edges, happy to sort.',
            createdAt: $now->modify('-4 days'),
        );
        $manager->persist($regularPair);
        $this->addReference(self::LISTING_REGULAR_PAIR, $regularPair);

        $favoritesTeam = new PartnerListing(
            id: Uuid::fromString(self::LISTING_FAVORITES_TEAM),
            competition: $competition,
            player: $withFavorites,
            groupSize: 2,
            lookingFor: [PartnerKind::Team],
            needed: 2,
            coMemberIds: [$admin->id->toString()],
            goal: PartnerGoal::FunFirst,
            strengths: new PartnerStrengthsNormalized([PartnerStrength::SplittingWork], []),
            languages: ['de', 'en'],
            note: null,
            createdAt: $now->modify('-3 days'),
        );
        $manager->persist($favoritesTeam);
        $this->addReference(self::LISTING_FAVORITES_TEAM, $favoritesTeam);

        $stripeClosed = new PartnerListing(
            id: Uuid::fromString(self::LISTING_STRIPE_CLOSED),
            competition: $competition,
            player: $withStripe,
            groupSize: 1,
            lookingFor: [PartnerKind::Pair],
            needed: null,
            coMemberIds: [],
            goal: null,
            strengths: new PartnerStrengthsNormalized([PartnerStrength::Sorting], []),
            languages: ['en'],
            note: null,
            createdAt: $now->modify('-5 days'),
        );
        $stripeClosed->close(PartnerListingClosedReason::Withdrawn, $now->modify('-2 days'));
        $manager->persist($stripeClosed);
        $this->addReference(self::LISTING_STRIPE_CLOSED, $stripeClosed);

        $request = new PartnerRequest(
            id: Uuid::fromString(self::REQUEST_STRIPE_TO_REGULAR),
            listing: $regularPair,
            fromPlayer: $withStripe,
            kind: PartnerKind::Pair,
            note: 'Hi John, I would love to pair up!',
            createdAt: $now->modify('-1 day'),
        );
        $manager->persist($request);
        $this->addReference(self::REQUEST_STRIPE_TO_REGULAR, $request);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
        ];
    }

    private function round(
        ObjectManager $manager,
        string $id,
        Competition $competition,
        string $name,
        \DateTimeImmutable $startsAt,
        RoundCategory $category,
        string $slug,
        null|int $teamSize,
    ): void {
        $round = new CompetitionRound(
            id: Uuid::fromString($id),
            competition: $competition,
            name: $name,
            minutesLimit: 120,
            startsAt: $startsAt,
            category: $category,
            slug: $slug,
            timezone: 'Europe/Prague',
            teamSize: $teamSize,
        );
        $manager->persist($round);
        $this->addReference($id, $round);
    }

    private function going(
        ObjectManager $manager,
        string $id,
        string $name,
        string $country,
        Competition $competition,
        Player $player,
    ): void {
        $participant = new CompetitionParticipant(
            id: Uuid::fromString($id),
            name: $name,
            country: $country,
            competition: $competition,
            source: ParticipantSource::SelfJoined,
        );
        $participant->connect($player, $this->clock->now()->modify('-5 days'));
        $manager->persist($participant);
        $this->addReference($id, $participant);
    }
}

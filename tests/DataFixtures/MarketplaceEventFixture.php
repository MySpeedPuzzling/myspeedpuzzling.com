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
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Entity\SellSwapListItemEvent;
use SpeedPuzzling\Web\Value\ParticipantSource;

/**
 * Marketplace at events (docs/features/marketplace/11-events.md, .claude/fixtures.md "Marketplace at events"):
 * seller A = PLAYER_WITH_STRIPE, seller B = PLAYER_ADMIN (both members with published listings),
 * buyer C = PLAYER_REGULAR (no listings, no membership).
 */
final class MarketplaceEventFixture extends Fixture implements DependentFixtureInterface
{
    /** Qualifying standalone in-person event, +21 days, Olomouc (cz): A brings SELLSWAP_01 + 02, B goes with nothing marked, C goes */
    public const string COMPETITION_SWAP_FAIR = '018d0014-0000-0000-0000-000000000001';
    public const string COMPETITION_SWAP_FAIR_SLUG = 'puzzle-swap-fair';

    public const string PARTICIPANT_FAIR_SELLER_A = '018d0014-0000-0000-0000-000000000011';
    public const string PARTICIPANT_FAIR_SELLER_B = '018d0014-0000-0000-0000-000000000012';
    public const string PARTICIPANT_FAIR_BUYER_C = '018d0014-0000-0000-0000-000000000013';
    /** A goes to the past in-person edition EDITION_PAST_ONLY_1 and SELLSWAP_07 is still marked for it (history) */
    public const string PARTICIPANT_PAST_SELLER_A = '018d0014-0000-0000-0000-000000000014';
    /** B goes to the upcoming ONLINE edition EDITION_EJJ_69 (never a marketplace event) */
    public const string PARTICIPANT_ONLINE_SELLER_B = '018d0014-0000-0000-0000-000000000015';
    /** A goes to the qualifying in-person edition EDITION_OFFLINE_1 (+14 days), nothing marked */
    public const string PARTICIPANT_EDITION_SELLER_A = '018d0014-0000-0000-0000-000000000016';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $sellerA = $this->getReference(PlayerFixture::PLAYER_WITH_STRIPE, Player::class);
        $sellerB = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);
        $buyerC = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);

        $pastEdition = $this->getReference(CompetitionSeriesFixture::EDITION_PAST_ONLY_1, Competition::class);
        $onlineEdition = $this->getReference(CompetitionSeriesFixture::EDITION_EJJ_69, Competition::class);
        $offlineEdition = $this->getReference(CompetitionSeriesFixture::EDITION_OFFLINE_1, Competition::class);

        $dateFrom = $this->clock->now()->modify('+21 days')->setTime(10, 0);

        $swapFair = new Competition(
            id: Uuid::fromString(self::COMPETITION_SWAP_FAIR),
            name: 'Puzzle Swap Fair',
            slug: self::COMPETITION_SWAP_FAIR_SLUG,
            shortcut: null,
            logo: null,
            description: 'Puzzle swap and sale day in Olomouc',
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: 'Olomouc',
            locationCountryCode: 'cz',
            dateFrom: $dateFrom,
            dateTo: $dateFrom->setTime(18, 0),
            tag: null,
            isOnline: false,
            approvedAt: $this->clock->now(),
            createdAt: $this->clock->now(),
        );
        $manager->persist($swapFair);
        $this->addReference(self::COMPETITION_SWAP_FAIR, $swapFair);

        $this->going($manager, self::PARTICIPANT_FAIR_SELLER_A, 'Sarah Williams', 'gb', $swapFair, $sellerA);
        $this->going($manager, self::PARTICIPANT_FAIR_SELLER_B, 'Admin User', 'cz', $swapFair, $sellerB);
        $this->going($manager, self::PARTICIPANT_FAIR_BUYER_C, 'John Doe', 'cz', $swapFair, $buyerC);
        $this->going($manager, self::PARTICIPANT_PAST_SELLER_A, 'Sarah Williams', 'gb', $pastEdition, $sellerA);
        $this->going($manager, self::PARTICIPANT_ONLINE_SELLER_B, 'Admin User', 'cz', $onlineEdition, $sellerB);
        $this->going($manager, self::PARTICIPANT_EDITION_SELLER_A, 'Sarah Williams', 'gb', $offlineEdition, $sellerA);

        $this->bringing($manager, SellSwapListItemFixture::SELLSWAP_01, $swapFair);
        $this->bringing($manager, SellSwapListItemFixture::SELLSWAP_02, $swapFair);
        $this->bringing($manager, SellSwapListItemFixture::SELLSWAP_07, $pastEdition);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            SellSwapListItemFixture::class,
            CompetitionSeriesFixture::class,
        ];
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
        $participant->connect($player, $this->clock->now()->modify('-3 days'));
        $manager->persist($participant);
        $this->addReference($id, $participant);
    }

    private function bringing(ObjectManager $manager, string $listItemId, Competition $competition): void
    {
        $manager->persist(new SellSwapListItemEvent(
            sellSwapListItem: $this->getReference($listItemId, SellSwapListItem::class),
            competition: $competition,
            addedAt: $this->clock->now()->modify('-2 days'),
        ));
    }
}

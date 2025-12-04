<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\LentPuzzle;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;

final class LentPuzzleFixture extends Fixture implements DependentFixtureInterface
{
    public const string LENT_01 = '018d000c-0000-0000-0000-000000000001';
    public const string LENT_02 = '018d000c-0000-0000-0000-000000000002';
    public const string LENT_03 = '018d000c-0000-0000-0000-000000000003';
    public const string LENT_04 = '018d000c-0000-0000-0000-000000000004';
    public const string LENT_05 = '018d000c-0000-0000-0000-000000000005';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $player1 = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $player4 = $this->getReference(PlayerFixture::PLAYER_WITH_FAVORITES, Player::class);
        $player5 = $this->getReference(PlayerFixture::PLAYER_WITH_STRIPE, Player::class);

        // Puzzles from player5's collection
        $puzzle500_03 = $this->getReference(PuzzleFixture::PUZZLE_500_03, Puzzle::class);
        $puzzle1000_01 = $this->getReference(PuzzleFixture::PUZZLE_1000_01, Puzzle::class);
        $puzzle1500_01 = $this->getReference(PuzzleFixture::PUZZLE_1500_01, Puzzle::class);
        $puzzle1500_02 = $this->getReference(PuzzleFixture::PUZZLE_1500_02, Puzzle::class);
        $puzzle2000 = $this->getReference(PuzzleFixture::PUZZLE_2000, Puzzle::class);

        // Lent to registered player, with notes
        $lent01 = $this->createLentPuzzle(
            id: self::LENT_01,
            puzzle: $puzzle2000,
            ownerPlayer: $player5,
            ownerName: null,
            currentHolderPlayer: $player1,
            currentHolderName: null,
            daysAgo: 30,
            notes: 'Handle with care',
        );
        $manager->persist($lent01);
        $this->addReference(self::LENT_01, $lent01);

        // Lent to non-registered person (name only), no notes
        $lent02 = $this->createLentPuzzle(
            id: self::LENT_02,
            puzzle: $puzzle1500_01,
            ownerPlayer: $player5,
            ownerName: null,
            currentHolderPlayer: null,
            currentHolderName: 'Jane Doe',
            daysAgo: 20,
            notes: null,
        );
        $manager->persist($lent02);
        $this->addReference(self::LENT_02, $lent02);

        // Returned puzzle (currentHolder is null), with notes
        $lent03 = $this->createLentPuzzle(
            id: self::LENT_03,
            puzzle: $puzzle1000_01,
            ownerPlayer: $player5,
            ownerName: null,
            currentHolderPlayer: null,
            currentHolderName: null,
            daysAgo: 45,
            notes: 'Returned in good condition',
        );
        $manager->persist($lent03);
        $this->addReference(self::LENT_03, $lent03);

        // Lent and passed to another registered player, with notes
        $lent04 = $this->createLentPuzzle(
            id: self::LENT_04,
            puzzle: $puzzle500_03,
            ownerPlayer: $player5,
            ownerName: null,
            currentHolderPlayer: $player4,
            currentHolderName: null,
            daysAgo: 10,
            notes: 'For testing purposes',
        );
        $manager->persist($lent04);
        $this->addReference(self::LENT_04, $lent04);

        // PLAYER_REGULAR lends to PLAYER_WITH_STRIPE (for testing return/pass as borrower)
        $lent05 = $this->createLentPuzzle(
            id: self::LENT_05,
            puzzle: $puzzle1500_02,
            ownerPlayer: $player1,
            ownerName: null,
            currentHolderPlayer: $player5,
            currentHolderName: null,
            daysAgo: 15,
            notes: null,
        );
        $manager->persist($lent05);
        $this->addReference(self::LENT_05, $lent05);

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            PuzzleFixture::class,
            CollectionItemFixture::class,
            MembershipFixture::class,
        ];
    }

    private function createLentPuzzle(
        string $id,
        Puzzle $puzzle,
        null|Player $ownerPlayer,
        null|string $ownerName,
        null|Player $currentHolderPlayer,
        null|string $currentHolderName,
        int $daysAgo,
        null|string $notes,
    ): LentPuzzle {
        $lentAt = $this->clock->now()->modify("-{$daysAgo} days");

        return new LentPuzzle(
            id: Uuid::fromString($id),
            puzzle: $puzzle,
            ownerPlayer: $ownerPlayer,
            ownerName: $ownerName,
            currentHolderPlayer: $currentHolderPlayer,
            currentHolderName: $currentHolderName,
            lentAt: $lentAt,
            notes: $notes,
        );
    }
}

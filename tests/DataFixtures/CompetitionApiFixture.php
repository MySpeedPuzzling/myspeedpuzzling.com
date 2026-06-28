<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Value\PuzzleHideMode;

/**
 * Dedicated fixture for the public Competitions read API, isolated from the shared
 * competition fixtures so it can safely exercise the puzzle-reveal privacy rule.
 *
 * Unlike PuzzleFixture (every puzzle has image: null), these puzzles carry a non-null
 * image so the tests can distinguish "image hidden before reveal" (null) from
 * "image revealed" (the stored path).
 */
final class CompetitionApiFixture extends Fixture implements DependentFixtureInterface
{
    public const string COMPETITION_API = '018d0006-0000-0000-0000-000000000001';

    public const string ROUND_FUTURE = '018d0006-0000-0000-0000-000000000010';
    public const string ROUND_PAST = '018d0006-0000-0000-0000-000000000011';

    public const string PUZZLE_HIDDEN_ENTIRELY = '018d0006-0000-0000-0000-000000000020';
    public const string PUZZLE_HIDDEN_IMAGE = '018d0006-0000-0000-0000-000000000021';
    public const string PUZZLE_VISIBLE = '018d0006-0000-0000-0000-000000000022';
    public const string PUZZLE_PAST = '018d0006-0000-0000-0000-000000000023';

    public const string IMAGE_HIDDEN_ENTIRELY = 'api-hidden-entirely.jpg';
    public const string IMAGE_HIDDEN_IMAGE = 'api-hidden-image.jpg';
    public const string IMAGE_VISIBLE = 'api-visible.jpg';
    public const string IMAGE_PAST = 'api-past.jpg';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $manufacturer = $this->getReference(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, Manufacturer::class);
        $adminPlayer = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);

        $competition = new Competition(
            id: Uuid::fromString(self::COMPETITION_API),
            name: 'API Reveal Test Competition',
            slug: 'api-reveal-test-competition',
            shortcut: 'APIREV',
            logo: 'api-reveal-logo.png',
            description: 'Competition used to verify the API puzzle-reveal privacy rule.',
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: 'Online',
            locationCountryCode: 'cz',
            dateFrom: $this->clock->now()->modify('-10 days'),
            dateTo: $this->clock->now()->modify('+30 days'),
            tag: null,
            isOnline: true,
            approvedAt: $this->clock->now(),
            addedByPlayer: $adminPlayer,
            createdAt: $this->clock->now(),
        );
        $manager->persist($competition);

        $hiddenEntirely = $this->createPuzzle(self::PUZZLE_HIDDEN_ENTIRELY, 'API Hidden Entirely', 500, self::IMAGE_HIDDEN_ENTIRELY, $manufacturer, $adminPlayer);
        $hiddenImage = $this->createPuzzle(self::PUZZLE_HIDDEN_IMAGE, 'API Hidden Image', 500, self::IMAGE_HIDDEN_IMAGE, $manufacturer, $adminPlayer);
        $visible = $this->createPuzzle(self::PUZZLE_VISIBLE, 'API Visible', 1000, self::IMAGE_VISIBLE, $manufacturer, $adminPlayer);
        $past = $this->createPuzzle(self::PUZZLE_PAST, 'API Past', 1000, self::IMAGE_PAST, $manufacturer, $adminPlayer);

        foreach ([$hiddenEntirely, $hiddenImage, $visible, $past] as $puzzle) {
            $manager->persist($puzzle);
        }

        // Future round: starts in +5 days, so now < startsAt + 10min → hide rules are in effect.
        $futureRound = new CompetitionRound(
            id: Uuid::fromString(self::ROUND_FUTURE),
            competition: $competition,
            name: 'Future Round',
            minutesLimit: 60,
            startsAt: $this->clock->now()->modify('+5 days'),
        );
        $manager->persist($futureRound);

        // Entirely hidden → omitted from the response until reveal.
        $manager->persist(new CompetitionRoundPuzzle(
            id: Uuid::uuid7(),
            round: $futureRound,
            puzzle: $hiddenEntirely,
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
        ));
        // Image only hidden → returned, but with image: null until reveal.
        $manager->persist(new CompetitionRoundPuzzle(
            id: Uuid::uuid7(),
            round: $futureRound,
            puzzle: $hiddenImage,
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::ImageOnly,
        ));
        // Not hidden → fully visible control puzzle in the same future round.
        $manager->persist(new CompetitionRoundPuzzle(
            id: Uuid::uuid7(),
            round: $futureRound,
            puzzle: $visible,
            hideUntilRoundStarts: false,
            hideMode: null,
        ));

        // Past round: started 10 days ago, so now > startsAt + 10min → everything is revealed
        // even though the puzzle was originally flagged hide-until-round-starts.
        $pastRound = new CompetitionRound(
            id: Uuid::fromString(self::ROUND_PAST),
            competition: $competition,
            name: 'Past Round',
            minutesLimit: 60,
            startsAt: $this->clock->now()->modify('-10 days'),
        );
        $manager->persist($pastRound);

        $manager->persist(new CompetitionRoundPuzzle(
            id: Uuid::uuid7(),
            round: $pastRound,
            puzzle: $past,
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
        ));

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            ManufacturerFixture::class,
            PlayerFixture::class,
        ];
    }

    private function createPuzzle(
        string $id,
        string $name,
        int $piecesCount,
        string $image,
        Manufacturer $manufacturer,
        Player $addedByUser,
    ): Puzzle {
        return new Puzzle(
            id: Uuid::fromString($id),
            piecesCount: $piecesCount,
            name: $name,
            approved: true,
            image: $image,
            manufacturer: $manufacturer,
            addedByUser: $addedByUser,
            addedAt: $this->clock->now(),
            isAvailable: true,
        );
    }
}

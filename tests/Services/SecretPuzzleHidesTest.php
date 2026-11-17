<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\ChangeRoundUnboxed;
use SpeedPuzzling\Web\Message\PublishRoundResults;
use SpeedPuzzling\Web\Message\UnpublishRoundResults;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ReadsRoundAutomaticReveal;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The site-wide hide of a puzzle a round keeps hidden everywhere (SecretPuzzleHides) on an unboxed round
 * (world-series-rules.md §7): the name follows the reveal, the picture waits for the round's first publish - never yet
 * before it, then the later of the reveal and the publish; unpublishing never hides it again.
 */
final class SecretPuzzleHidesTest extends WebTestCase
{
    use ReadsRoundAutomaticReveal;

    private const string ROUND = CompetitionApiFixture::ROUND_FUTURE;
    private const string COMPETITION = CompetitionApiFixture::COMPETITION_API;
    private const string NEVER = CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED;

    public function testAnUnboxedRoundKeepsThePictureUntilItsFirstPublish(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $puzzleId = $this->secretPuzzle();
        $reveal = self::automaticRevealOf(self::ROUND)->format('Y-m-d H:i:s');

        self::assertSame([$reveal, $reveal], $this->hides($puzzleId), 'a secret puzzle of a round that is not unboxed');

        $this->dispatch(new ChangeRoundUnboxed(self::COMPETITION, self::ROUND, true));
        self::assertSame([$reveal, self::NEVER], $this->hides($puzzleId), 'unboxed: the picture waits for the publish');

        // After the reveal: the puzzle's page exists (its name is out), without its picture
        $this->moveClockTo(self::automaticRevealOf(self::ROUND)->modify('+5 minutes'));
        $browser->request('GET', '/en/puzzle/' . $puzzleId);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Unboxed Secret', (string) $browser->getResponse()->getContent());

        $publishedAt = $this->now()->format('Y-m-d H:i:s');
        $this->dispatch(new PublishRoundResults(self::COMPETITION, self::ROUND));
        self::assertSame([$reveal, $publishedAt], $this->hides($puzzleId), 'first publish after the reveal: the publish');

        $this->moveClockTo($this->now()->modify('+1 hour'));
        $this->dispatch(new UnpublishRoundResults(self::COMPETITION, self::ROUND));
        $this->dispatch(new PublishRoundResults(self::COMPETITION, self::ROUND));
        self::assertSame([$reveal, $publishedAt], $this->hides($puzzleId), 'a later publish changes nothing');
    }

    public function testAPublishBeforeTheRevealKeepsThePictureUntilTheReveal(): void
    {
        self::createClient();
        $puzzleId = $this->secretPuzzle();
        $reveal = self::automaticRevealOf(self::ROUND)->format('Y-m-d H:i:s');

        $this->dispatch(new ChangeRoundUnboxed(self::COMPETITION, self::ROUND, true));
        $this->dispatch(new PublishRoundResults(self::COMPETITION, self::ROUND));

        self::assertSame([$reveal, $reveal], $this->hides($puzzleId));
    }

    public function testSwitchingItOffGivesThePictureBackToTheReveal(): void
    {
        self::createClient();
        $puzzleId = $this->secretPuzzle();
        $reveal = self::automaticRevealOf(self::ROUND)->format('Y-m-d H:i:s');

        $this->dispatch(new ChangeRoundUnboxed(self::COMPETITION, self::ROUND, true));
        $this->dispatch(new ChangeRoundUnboxed(self::COMPETITION, self::ROUND, false));

        self::assertSame([$reveal, $reveal], $this->hides($puzzleId));
    }

    /**
     * @return array{null|string, null|string} hide_until, hide_image_until
     */
    private function hides(string $puzzleId): array
    {
        /** @var array{hide_until: null|string, hide_image_until: null|string} $row */
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT hide_until, hide_image_until FROM puzzle WHERE id = :id',
            ['id' => $puzzleId],
        );

        return [$row['hide_until'], $row['hide_image_until']];
    }

    private function secretPuzzle(): string
    {
        $roundPuzzleId = Uuid::uuid7();
        $this->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: self::ROUND,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: 'Unboxed Secret',
            piecesCount: 1000,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
            shownAutomaticRevealAt: self::automaticRevealOf(self::ROUND),
        ));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $roundPuzzle = $entityManager->find(CompetitionRoundPuzzle::class, $roundPuzzleId);
        self::assertNotNull($roundPuzzle);
        self::assertTrue($roundPuzzle->hidesEverywhere, 'A puzzle created for the round is kept hidden everywhere');

        return $roundPuzzle->puzzle->id->toString();
    }

    private function dispatch(object $message): void
    {
        $container = self::getContainer();
        $container->get(EntityManagerInterface::class)->clear();
        $container->get(MessageBusInterface::class)->dispatch($message);
        $container->get(EntityManagerInterface::class)->clear();
    }

    private function now(): DateTimeImmutable
    {
        return self::getContainer()->get(ClockInterface::class)->now();
    }

    private function moveClockTo(DateTimeImmutable $moment): void
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        $clock->sleep($moment->getTimestamp() - $clock->now()->getTimestamp());
    }
}

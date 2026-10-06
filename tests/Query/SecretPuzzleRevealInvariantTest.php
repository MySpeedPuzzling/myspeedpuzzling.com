<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Message\RevealRoundPuzzleNow;
use SpeedPuzzling\Web\Query\GetCompetitionPuzzles;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzleSummary;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One reveal moment per secret puzzle, and every surface obeys it: a puzzle created for a round is out nowhere -
 * search, the brand picker, the event pages, the competitions it is "used at", its own page - one second before the
 * moment the organiser is shown, and out everywhere at that moment.
 */
final class SecretPuzzleRevealInvariantTest extends WebTestCase
{
    private const string SECRET_NAME = 'Midnight Lighthouse Secret';
    private const string SECRET_EAN = '4005556175512';
    private const string SECRET_BRAND_CODE = 'MLS-17551';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testNoSurfaceShowsTheSecretPuzzleBeforeItsRevealMoment(): void
    {
        $revealAt = new DateTimeImmutable('@' . (intdiv(time(), 60) * 60 + 7200));
        $roundPuzzle = $this->addSecretPuzzle(PuzzleHideMode::Entirely);
        $this->dispatch(new ChangeRoundPuzzleReveal($roundPuzzle->id->toString(), PuzzleHideMode::Entirely, RoundPuzzleReveal::Scheduled, $revealAt));

        $roundPuzzle = $this->roundPuzzle($roundPuzzle->id->toString());
        $puzzleId = $roundPuzzle->puzzle->id->toString();

        // The moment the organiser is shown is the one the whole site uses
        self::assertSame($revealAt->getTimestamp(), $roundPuzzle->revealsAt()?->getTimestamp());
        self::assertSame($revealAt->getTimestamp(), $roundPuzzle->puzzle->hideUntil?->getTimestamp());
        self::assertSame($revealAt->getTimestamp(), $roundPuzzle->puzzle->hideImageUntil?->getTimestamp());

        $oneSecondBefore = new MockClock($revealAt->modify('-1 second'));
        self::assertFalse($this->foundBySearch($oneSecondBefore));
        self::assertFalse($this->foundByBrandPicker($oneSecondBefore, $puzzleId));
        self::assertFalse($this->onEventPage($oneSecondBefore, $puzzleId));
        self::assertFalse($this->amongCompetitionPuzzles($oneSecondBefore, $puzzleId));
        self::assertFalse($this->usedAtTheCompetition($oneSecondBefore, $puzzleId));

        $atTheMoment = new MockClock($revealAt);
        self::assertTrue($this->foundBySearch($atTheMoment));
        self::assertTrue($this->foundByBrandPicker($atTheMoment, $puzzleId));
        self::assertTrue($this->onEventPage($atTheMoment, $puzzleId));
        self::assertTrue($this->amongCompetitionPuzzles($atTheMoment, $puzzleId));
        self::assertTrue($this->usedAtTheCompetition($atTheMoment, $puzzleId));
    }

    public function testAutomaticRevealIsTheSameMomentEverywhere(): void
    {
        $roundPuzzle = $this->addSecretPuzzle(PuzzleHideMode::Entirely);
        $puzzleId = $roundPuzzle->puzzle->id->toString();
        $revealAt = $roundPuzzle->round->automaticRevealAt();

        self::assertSame(RoundPuzzleReveal::Automatic, $roundPuzzle->revealMode);
        self::assertSame($revealAt->getTimestamp(), $roundPuzzle->revealsAt()?->getTimestamp());
        self::assertSame($revealAt->getTimestamp(), $roundPuzzle->puzzle->hideUntil?->getTimestamp());

        $oneSecondBefore = new MockClock($revealAt->modify('-1 second'));
        self::assertFalse($this->foundBySearch($oneSecondBefore));
        self::assertFalse($this->foundByBrandPicker($oneSecondBefore, $puzzleId));
        self::assertFalse($this->onEventPage($oneSecondBefore, $puzzleId));
        self::assertFalse($this->amongCompetitionPuzzles($oneSecondBefore, $puzzleId));
        self::assertFalse($this->usedAtTheCompetition($oneSecondBefore, $puzzleId));

        $atTheMoment = new MockClock($revealAt);
        self::assertTrue($this->foundBySearch($atTheMoment));
        self::assertTrue($this->foundByBrandPicker($atTheMoment, $puzzleId));
        self::assertTrue($this->onEventPage($atTheMoment, $puzzleId));
        self::assertTrue($this->amongCompetitionPuzzles($atTheMoment, $puzzleId));
        self::assertTrue($this->usedAtTheCompetition($atTheMoment, $puzzleId));
    }

    public function testImageOnlyKeepsThePictureAndTheCodesSecretUntilTheMoment(): void
    {
        $roundPuzzle = $this->addSecretPuzzle(PuzzleHideMode::ImageOnly);
        $puzzleId = $roundPuzzle->puzzle->id->toString();
        $revealAt = $roundPuzzle->round->automaticRevealAt();

        // The name is public, the picture and the codes are not
        self::assertNull($roundPuzzle->puzzle->hideUntil);
        self::assertSame($revealAt->getTimestamp(), $roundPuzzle->puzzle->hideImageUntil?->getTimestamp());

        $oneSecondBefore = new MockClock($revealAt->modify('-1 second'));
        self::assertTrue($this->foundBySearch($oneSecondBefore));
        self::assertTrue($this->onEventPage($oneSecondBefore, $puzzleId));
        self::assertSame([null, null], $this->shownCodes($oneSecondBefore, $puzzleId));
        self::assertFalse($this->foundByCode(self::SECRET_EAN));
        self::assertFalse($this->foundByCode(self::SECRET_BRAND_CODE));

        foreach (new SearchPuzzle($this->connection(), $oneSecondBefore)->byBrandId(ManufacturerFixture::MANUFACTURER_RAVENSBURGER) as $choice) {
            if ($choice->puzzleId === $puzzleId) {
                self::assertNull($choice->puzzleEan);
                self::assertNull($choice->puzzleIdentificationNumber);
            }
        }

        $atTheMoment = new MockClock($revealAt);
        self::assertSame([self::SECRET_EAN, self::SECRET_BRAND_CODE], $this->shownCodes($atTheMoment, $puzzleId));
    }

    public function testPuzzlePageAndBrandPickerFollowRevealNow(): void
    {
        $roundPuzzle = $this->addSecretPuzzle(PuzzleHideMode::Entirely);
        $roundPuzzleId = $roundPuzzle->id->toString();
        $puzzleId = $roundPuzzle->puzzle->id->toString();

        // Before the reveal: out of the brand picker, and its own page does not exist for a visitor
        $this->browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $this->browser->getResponse()->getContent());

        $this->browser->request('GET', '/en/puzzle/' . $puzzleId);
        self::assertResponseStatusCodeSame(404);

        $this->dispatch(new RevealRoundPuzzleNow($roundPuzzleId));

        $this->browser->request('GET', '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
        self::assertStringContainsString(self::SECRET_NAME, (string) $this->browser->getResponse()->getContent());

        $crawler = $this->browser->request('GET', '/en/puzzle/' . $puzzleId);
        self::assertResponseIsSuccessful();
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testManualRevealKeepsThePuzzleSecretEverywhereForever(): void
    {
        $roundPuzzle = $this->addSecretPuzzle(PuzzleHideMode::Entirely);
        $this->dispatch(new ChangeRoundPuzzleReveal($roundPuzzle->id->toString(), PuzzleHideMode::Entirely, RoundPuzzleReveal::Manual, null));
        $puzzleId = $roundPuzzle->puzzle->id->toString();

        $longAfterTheRound = new MockClock(new DateTimeImmutable('+3 years'));
        self::assertFalse($this->foundBySearch($longAfterTheRound));
        self::assertFalse($this->foundByBrandPicker($longAfterTheRound, $puzzleId));
        self::assertFalse($this->onEventPage($longAfterTheRound, $puzzleId));
        self::assertFalse($this->amongCompetitionPuzzles($longAfterTheRound, $puzzleId));
    }

    private function addSecretPuzzle(PuzzleHideMode $hideMode): CompetitionRoundPuzzle
    {
        $roundPuzzleId = Uuid::uuid7();

        $this->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionApiFixture::ROUND_FUTURE,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: self::SECRET_NAME,
            piecesCount: 1000,
            puzzlePhoto: null,
            eans: EanList::fromStored(self::SECRET_EAN),
            brandCodes: BrandCodeList::fromStored(self::SECRET_BRAND_CODE),
            hideUntilRoundStarts: true,
            hideMode: $hideMode,
        ));

        return $this->roundPuzzle($roundPuzzleId->toString());
    }

    private function foundByCode(string $code): bool
    {
        // Code matching uses the database's own clock: the real now, before the reveal
        $search = self::getContainer()->get(SearchPuzzle::class);

        foreach ([...$search->byUserInput(null, $code, PiecesRange::any(), null), ...$search->allByEan($code)] as $puzzle) {
            if ($puzzle->puzzleName === self::SECRET_NAME) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{null|string, null|string} the EAN and brand code the site shows
     */
    private function shownCodes(MockClock $clock, string $puzzleId): array
    {
        $overview = new GetPuzzleOverview($this->connection(), $clock)->byId($puzzleId);

        return [$overview->puzzleEan, $overview->puzzleIdentificationNumber];
    }

    private function foundBySearch(MockClock $clock): bool
    {
        $search = new SearchPuzzle($this->connection(), $clock);

        foreach ($search->byUserInput(null, self::SECRET_NAME, PiecesRange::any(), null) as $puzzle) {
            if ($puzzle->puzzleName === self::SECRET_NAME) {
                return true;
            }
        }

        return false;
    }

    private function foundByBrandPicker(MockClock $clock, string $puzzleId): bool
    {
        $search = new SearchPuzzle($this->connection(), $clock);

        foreach ($search->byBrandId(ManufacturerFixture::MANUFACTURER_RAVENSBURGER) as $puzzle) {
            if ($puzzle->puzzleId === $puzzleId) {
                return true;
            }
        }

        return false;
    }

    private function onEventPage(MockClock $clock, string $puzzleId): bool
    {
        $rounds = new GetEditionRounds($this->connection(), $clock)->forCompetition(CompetitionApiFixture::COMPETITION_API);

        foreach ($rounds as $round) {
            foreach ($round->puzzles as $puzzle) {
                if ($puzzle->puzzleId === $puzzleId) {
                    return true;
                }
            }
        }

        return false;
    }

    private function amongCompetitionPuzzles(MockClock $clock, string $puzzleId): bool
    {
        $puzzles = new GetCompetitionPuzzles($this->connection(), $clock)->forCompetitions([CompetitionApiFixture::COMPETITION_API]);

        foreach ($puzzles[CompetitionApiFixture::COMPETITION_API] ?? [] as $puzzle) {
            if ($puzzle->puzzleId === $puzzleId) {
                return true;
            }
        }

        return false;
    }

    private function usedAtTheCompetition(MockClock $clock, string $puzzleId): bool
    {
        $summary = new GetPuzzleSummary($this->connection(), $clock)->forPuzzle($puzzleId);

        return $summary->usedAt !== [];
    }

    private function dispatch(object $message): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch($message);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function roundPuzzle(string $roundPuzzleId): CompetitionRoundPuzzle
    {
        $roundPuzzle = self::getContainer()->get(EntityManagerInterface::class)->find(CompetitionRoundPuzzle::class, $roundPuzzleId);
        self::assertNotNull($roundPuzzle);

        return $roundPuzzle;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}

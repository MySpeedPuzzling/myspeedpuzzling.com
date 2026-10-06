<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The organiser sees each secret puzzle's exact reveal moment in the event's zone and controls it:
 * own time, manual reveal, "Reveal now".
 */
final class ManageRoundPuzzleRevealTest extends WebTestCase
{
    public function testOrganiserSeesAndControlsTheReveal(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $round = $entityManager->find(CompetitionRound::class, CompetitionApiFixture::ROUND_FUTURE);
        self::assertNotNull($round);
        $round->timezone = 'America/Chicago';
        $entityManager->flush();

        $roundPuzzleId = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionApiFixture::ROUND_FUTURE,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: 'Tropical Vibes Test',
            piecesCount: 500,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
        ));
        $entityManager->clear();

        $card = '#round-puzzle-' . $roundPuzzleId->toString();
        $page = '/en/manage-round-puzzles/' . CompetitionApiFixture::ROUND_FUTURE;

        $crawler = $browser->request('GET', $page);
        $this->assertResponseIsSuccessful();
        $status = $crawler->filter($card . ' [data-reveal-status]')->text();
        self::assertStringStartsWith('Hidden everywhere until', $status);
        self::assertStringContainsString('(Chicago Time)', $status);

        // Manual: stays hidden until "Reveal now"
        $form = $crawler->filter($card . ' form[action*="round-puzzle-reveal"]')->form([
            'hide_mode' => 'entirely',
            'reveal_mode' => 'manual',
        ]);
        $browser->submit($form);
        $this->assertResponseRedirects($page);

        $crawler = $browser->request('GET', $page);
        self::assertSame('Hidden everywhere until you reveal it', $crawler->filter($card . ' [data-reveal-status]')->text());
        self::assertSame(RoundPuzzleReveal::Manual, $this->roundPuzzle($roundPuzzleId->toString())->revealMode);

        // Own time, typed in the event's zone
        $form = $crawler->filter($card . ' form[action*="round-puzzle-reveal"]')->form([
            'hide_mode' => 'entirely',
            'reveal_mode' => 'scheduled',
            'reveal_at' => '2030-10-26T08:15',
        ]);
        $browser->submit($form);

        $roundPuzzle = $this->roundPuzzle($roundPuzzleId->toString());
        // 8:15 in Chicago (CDT, UTC-5)
        self::assertSame('2030-10-26T13:15:00+00:00', $roundPuzzle->revealsAt()?->format('c'));
        self::assertSame('2030-10-26T13:15:00+00:00', $roundPuzzle->puzzle->hideUntil?->format('c'));

        $crawler = $browser->request('GET', $page);
        self::assertStringContainsString('October 26, 2030 at 8:15', $crawler->filter($card . ' [data-reveal-status]')->text());

        // A time already over is no reveal time - that is "Reveal now"
        $form = $crawler->filter($card . ' form[action*="round-puzzle-reveal"]')->form([
            'hide_mode' => 'entirely',
            'reveal_mode' => 'scheduled',
            'reveal_at' => '2020-01-01T08:15',
        ]);
        $browser->submit($form);
        self::assertSame('2030-10-26T13:15:00+00:00', $this->revealsAt($roundPuzzleId->toString()));

        // A time that does not exist
        $form = $crawler->filter($card . ' form[action*="round-puzzle-reveal"]')->form([
            'hide_mode' => 'entirely',
            'reveal_mode' => 'scheduled',
            'reveal_at' => '2030-02-31T25:70',
        ]);
        $browser->submit($form);
        self::assertSame('2030-10-26T13:15:00+00:00', $this->revealsAt($roundPuzzleId->toString()));

        // Reveal now
        $browser->submit($crawler->filter($card . ' form[action*="reveal-round-puzzle"]')->form());
        $crawler = $browser->request('GET', $page);
        self::assertStringStartsWith('Revealed', $crawler->filter($card . ' [data-reveal-status]')->text());
        self::assertCount(0, $crawler->filter($card . ' form[action*="reveal-round-puzzle"]'));
        // A revealed puzzle is public - nothing offers to hide it again
        self::assertCount(0, $crawler->filter($card . ' form[action*="round-puzzle-reveal"]'));
    }

    public function testAnotherEventsMaintainerCannotTouchTheReveal(): void
    {
        $browser = self::createClient();
        $roundPuzzleId = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionApiFixture::ROUND_FUTURE,
            userId: PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: 'Not Yours',
            piecesCount: 500,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
        ));

        // Maintains another event only
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        foreach (['/en/round-puzzle-reveal/', '/en/reveal-round-puzzle/', '/en/remove-puzzle-from-round/'] as $endpoint) {
            $browser->request('POST', $endpoint . $roundPuzzleId->toString(), ['reveal_mode' => 'automatic', 'hide_mode' => 'entirely']);
            self::assertResponseStatusCodeSame(403, $endpoint);
        }

        $browser->request('POST', '/en/reveal-round-puzzle/' . Uuid::uuid7()->toString());
        self::assertResponseStatusCodeSame(404);

        self::assertTrue($this->roundPuzzle($roundPuzzleId->toString())->isHiddenAt(new \DateTimeImmutable()));
    }

    private function revealsAt(string $roundPuzzleId): null|string
    {
        return $this->roundPuzzle($roundPuzzleId)->revealsAt()?->format('c');
    }

    private function roundPuzzle(string $roundPuzzleId): CompetitionRoundPuzzle
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $roundPuzzle = $entityManager->find(CompetitionRoundPuzzle::class, $roundPuzzleId);
        self::assertNotNull($roundPuzzle);

        return $roundPuzzle;
    }
}

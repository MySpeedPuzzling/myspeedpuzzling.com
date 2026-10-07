<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ReadsRoundAutomaticReveal;
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
    use ReadsRoundAutomaticReveal;

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
            puzzle: 'Secret Puzzle Delta',
            piecesCount: 500,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
            shownAutomaticRevealAt: self::automaticRevealOf(CompetitionApiFixture::ROUND_FUTURE),
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
        $refused = $browser->submit($form);
        self::assertResponseStatusCodeSame(422);
        // The card opens again with the error and what was typed
        self::assertCount(1, $refused->filter($card . ' [data-reveal-error]'));
        self::assertSame('2020-01-01T08:15', $refused->filter($card . ' input[name="reveal_at"]')->attr('value'));
        self::assertSame('2030-10-26T13:15:00+00:00', $this->revealsAt($roundPuzzleId->toString()));

        // A time that does not exist
        $form = $crawler->filter($card . ' form[action*="round-puzzle-reveal"]')->form([
            'hide_mode' => 'entirely',
            'reveal_mode' => 'scheduled',
            'reveal_at' => '2030-02-31T25:70',
        ]);
        $refused = $browser->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('2030-02-31T25:70', $refused->filter($card . ' input[name="reveal_at"]')->attr('value'));
        self::assertSame('2030-10-26T13:15:00+00:00', $this->revealsAt($roundPuzzleId->toString()));

        // Reveal now
        $browser->submit($crawler->filter($card . ' form[action*="reveal-round-puzzle"]')->form());
        $crawler = $browser->request('GET', $page);
        self::assertStringStartsWith('Revealed', $crawler->filter($card . ' [data-reveal-status]')->text());
        self::assertCount(0, $crawler->filter($card . ' form[action*="reveal-round-puzzle"]'));
        // A revealed puzzle is public - nothing offers to hide it again
        self::assertCount(0, $crawler->filter($card . ' form[action*="round-puzzle-reveal"]'));
    }

    public function testRemovingThePuzzleFromTheRoundThatHoldsItAsksFirstAndSaysWhatHappened(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $bus = self::getContainer()->get(MessageBusInterface::class);

        // One secret puzzle in two rounds - Team Relay (5 days ahead) and a round 60 days ahead
        $teamRelay = Uuid::uuid7();
        $bus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $teamRelay,
            roundId: CompetitionApiFixture::ROUND_FUTURE,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: 'Held Twice',
            piecesCount: 500,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            shownAutomaticRevealAt: self::automaticRevealOf(CompetitionApiFixture::ROUND_FUTURE),
        ));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $puzzleId = $this->roundPuzzle($teamRelay->toString())->puzzle->id->toString();
        $later = Uuid::uuid7();
        $bus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $later,
            roundId: CompetitionRoundFixture::ROUND_CZECH_FINAL,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: $puzzleId,
            piecesCount: null,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            shownAutomaticRevealAt: self::automaticRevealOf(CompetitionRoundFixture::ROUND_CZECH_FINAL),
        ));

        // Reveal now on Team Relay: the flash says the other round still holds it
        $crawler = $browser->request('GET', '/en/manage-round-puzzles/' . CompetitionApiFixture::ROUND_FUTURE);
        $browser->submit($crawler->filter('#round-puzzle-' . $teamRelay->toString() . ' form[action*="reveal-round-puzzle"]')->form());
        $crawler = $browser->followRedirect();
        self::assertStringContainsString('is revealed on this round – another round keeps it hidden everywhere until', $crawler->text());

        // Removing it from the round that holds it would reveal it - asked first, for exactly this list
        $removal = '/en/manage-round-puzzles/' . CompetitionRoundFixture::ROUND_CZECH_FINAL;
        $crawler = $browser->request('GET', $removal);
        $confirmation = $browser->submit($crawler->filter('#round-puzzle-' . $later->toString() . ' form[action*="remove-puzzle-from-round"]')->form());
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Held Twice', $confirmation->filter('[data-confirm-reveal]')->text());
        self::assertNotNull($this->roundPuzzleOrNull($later->toString()));

        // A yes for another list does not count
        $form = $confirmation->filter('form')->last()->form(['confirm_reveal' => '1']);
        $browser->submit($form, ['confirm_reveal_hash' => 'tampered']);
        self::assertResponseStatusCodeSame(422);
        self::assertNotNull($this->roundPuzzleOrNull($later->toString()));

        $browser->submit($confirmation->filter('form')->last()->form(['confirm_reveal' => '1']));
        $crawler = $browser->followRedirect();
        self::assertStringContainsString('Revealed now: Held Twice', $crawler->text());
        self::assertNull($this->roundPuzzleOrNull($later->toString()));

        $entityManager->clear();
        $puzzle = $entityManager->find(\SpeedPuzzling\Web\Entity\Puzzle::class, $puzzleId);
        self::assertNotNull($puzzle);
        self::assertFalse($puzzle->isImageHiddenAt(new \DateTimeImmutable()));
    }

    public function testDeletingTheRoundThatHoldsASecretAsksFirstForExactlyThatList(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $bus = self::getContainer()->get(MessageBusInterface::class);

        // One secret puzzle in two rounds: revealed already on Team Relay, still secret on the Czech final
        $teamRelay = Uuid::uuid7();
        $bus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $teamRelay,
            roundId: CompetitionApiFixture::ROUND_FUTURE,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: 'Held By The Final',
            piecesCount: 500,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            shownAutomaticRevealAt: self::automaticRevealOf(CompetitionApiFixture::ROUND_FUTURE),
        ));
        $puzzleId = $this->roundPuzzle($teamRelay->toString())->puzzle->id->toString();
        $bus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: Uuid::uuid7(),
            roundId: CompetitionRoundFixture::ROUND_CZECH_FINAL,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: $puzzleId,
            piecesCount: null,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            shownAutomaticRevealAt: self::automaticRevealOf(CompetitionRoundFixture::ROUND_CZECH_FINAL),
        ));
        $bus->dispatch(new \SpeedPuzzling\Web\Message\RevealRoundPuzzleNow($teamRelay->toString()));

        $deletion = '/en/delete-event-round/' . CompetitionRoundFixture::ROUND_CZECH_FINAL;

        // Asked first, the puzzle listed
        $confirmation = $browser->request('POST', $deletion);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Held By The Final', $confirmation->filter('[data-confirm-reveal]')->text());
        self::assertNotNull(self::getContainer()->get(EntityManagerInterface::class)->find(CompetitionRound::class, CompetitionRoundFixture::ROUND_CZECH_FINAL));

        // A yes without the list's hash, or for another list, does not count
        $browser->request('POST', $deletion, ['confirm_reveal' => '1']);
        self::assertResponseStatusCodeSame(422);
        $browser->request('POST', $deletion, ['confirm_reveal' => '1', 'confirm_reveal_hash' => 'tampered']);
        self::assertResponseStatusCodeSame(422);

        // The hash shown with the list
        $hash = $confirmation->filter('input[name="confirm_reveal_hash"]')->attr('value');
        self::assertIsString($hash);
        $browser->request('POST', $deletion, ['confirm_reveal' => '1', 'confirm_reveal_hash' => $hash]);
        $crawler = $browser->followRedirect();
        self::assertStringContainsString('Round deleted. Revealed now: Held By The Final', $crawler->text());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertNull($entityManager->find(CompetitionRound::class, CompetitionRoundFixture::ROUND_CZECH_FINAL));
        $puzzle = $entityManager->find(\SpeedPuzzling\Web\Entity\Puzzle::class, $puzzleId);
        self::assertNotNull($puzzle);
        self::assertFalse($puzzle->isImageHiddenAt(new \DateTimeImmutable()));
    }

    public function testAConfirmationCountsOnlyForHowFarEachPuzzleComesOut(): void
    {
        $everywhere = [['id' => 'a', 'name' => 'A', 'revealsAt' => null, 'previousRevealsAt' => null, 'scope' => 'everywhere', 'everywhere' => true, 'hiddenElsewhereUntil' => null]];
        $onThisEvent = [['id' => 'a', 'name' => 'A', 'revealsAt' => null, 'previousRevealsAt' => null, 'scope' => 'event', 'everywhere' => false, 'hiddenElsewhereUntil' => new \DateTimeImmutable('2030-01-01 10:00:00')]];
        $onThisEventLonger = [['id' => 'a', 'name' => 'A', 'revealsAt' => null, 'previousRevealsAt' => null, 'scope' => 'event', 'everywhere' => false, 'hiddenElsewhereUntil' => new \DateTimeImmutable('2030-01-02 10:00:00')]];
        $nameEverywhere = [['id' => 'a', 'name' => 'A', 'revealsAt' => null, 'previousRevealsAt' => null, 'scope' => 'name_everywhere', 'everywhere' => false, 'hiddenElsewhereUntil' => new \DateTimeImmutable('2030-01-01 10:00:00')]];

        self::assertNotSame(\SpeedPuzzling\Web\Services\SecretRevealPreview::hash($everywhere), \SpeedPuzzling\Web\Services\SecretRevealPreview::hash($onThisEvent));
        self::assertNotSame(\SpeedPuzzling\Web\Services\SecretRevealPreview::hash($onThisEvent), \SpeedPuzzling\Web\Services\SecretRevealPreview::hash($onThisEventLonger));
        // Only its name everywhere is another yes than only on this event, until the same moment
        self::assertNotSame(\SpeedPuzzling\Web\Services\SecretRevealPreview::hash($onThisEvent), \SpeedPuzzling\Web\Services\SecretRevealPreview::hash($nameEverywhere));
        // The order of the list does not matter
        $two = [...$everywhere, ['id' => 'b', 'name' => 'B', 'revealsAt' => null, 'previousRevealsAt' => null, 'scope' => 'everywhere', 'everywhere' => true, 'hiddenElsewhereUntil' => null]];
        self::assertSame(\SpeedPuzzling\Web\Services\SecretRevealPreview::hash($two), \SpeedPuzzling\Web\Services\SecretRevealPreview::hash(array_reverse($two)));
    }

    private function roundPuzzleOrNull(string $roundPuzzleId): null|CompetitionRoundPuzzle
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(CompetitionRoundPuzzle::class, $roundPuzzleId);
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
            shownAutomaticRevealAt: self::automaticRevealOf(CompetitionApiFixture::ROUND_FUTURE),
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

<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ReadsRoundAutomaticReveal;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Reveal the puzzles" at the stopwatch start, seen from the organiser's pages (docs/features/competitions-management/
 * toolkit/A-round-formats.md §4.3): the checkbox exists only for a round with puzzles waiting for the start and is never
 * ticked; ticked it reveals the list confirmed and tells the round's results pages (the reveal push); the public
 * stopwatch topic and state never carry anything of the puzzles; the option on the round puzzles page is the toolkit's.
 */
final class StopwatchRevealPagesTest extends WebTestCase
{
    use ReadsRoundAutomaticReveal;

    private const string ROUND = CompetitionApiFixture::ROUND_FUTURE;
    private const string SECRET_NAME = 'Stopwatch Secret Lighthouse';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
    }

    public function testAnUntouchedRoundHasNoCheckbox(): void
    {
        $page = $this->browser->request('GET', '/en/manage-round-stopwatch/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('[data-stopwatch-reveal]'));
        self::assertCount(0, $page->filter('[data-stopwatch-reveal-start]'));
        self::assertStringNotContainsString('round-stopwatch-reveal', (string) $this->browser->getResponse()->getContent());
    }

    public function testTheCheckboxIsNeverTickedAndAStartWithoutItRevealsNothing(): void
    {
        $roundPuzzleId = $this->stopwatchSecret();
        $page = $this->browser->request('GET', '/en/manage-round-stopwatch/' . self::ROUND);

        self::assertResponseIsSuccessful();
        $checkbox = $page->filter('[data-stopwatch-reveal] input[type="checkbox"]');
        self::assertCount(1, $checkbox);
        self::assertNull($checkbox->attr('checked'));
        self::assertStringContainsString(self::SECRET_NAME, $page->filter('[data-stopwatch-reveal]')->text());
        self::assertCount(3, $page->filter('form[data-stopwatch-reveal-start]'), 'Start, Start in 10 s, Start at');

        $this->post('/en/start-round-stopwatch/' . self::ROUND, ['_token' => 'csrf-token']);

        self::assertResponseRedirects('/en/round-stopwatch/' . self::ROUND);
        self::assertNull($this->revealAt($roundPuzzleId));
        // Only the stopwatch's own public update - no reveal push
        self::assertCount(1, self::hub()->getPublishedUpdates());

        $page = $this->browser->request('GET', '/en/manage-round-stopwatch/' . self::ROUND);
        self::assertStringContainsString(self::SECRET_NAME, $page->filter('[data-stopwatch-reveal-still-hidden]')->text());
    }

    public function testATickedStartRevealsAndPushesWhileThePublicStopwatchCarriesNoPuzzle(): void
    {
        $roundPuzzleId = $this->stopwatchSecret();
        $hash = $this->shownHash();

        $this->post('/en/start-round-stopwatch/' . self::ROUND, ['_token' => 'csrf-token', 'reveal' => '1', 'reveal_hash' => $hash]);

        self::assertResponseRedirects('/en/round-stopwatch/' . self::ROUND);
        self::assertNotNull($this->revealAt($roundPuzzleId));

        $updates = self::hub()->getPublishedUpdates();
        self::assertCount(2, $updates);
        // The public stopwatch topic: the same keys as ever, nothing of the puzzles
        self::assertSame(['/round-stopwatch/' . self::ROUND], $updates[0]->getTopics());
        self::assertFalse($updates[0]->isPrivate());
        $stopwatch = json_decode($updates[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($stopwatch);
        self::assertSame(['roundId', 'status', 'startedAt', 'stoppedAt', 'minutesLimit', 'startedAtMs', 'stoppedAtMs', 'asOfMs', 'stateHash'], array_keys($stopwatch));
        self::assertStringNotContainsString(self::SECRET_NAME, $updates[0]->getData());
        // The reveal push: the round's private results topics fetch the round again
        self::assertSame([OfficialResultsLiveUpdates::topic(self::ROUND), OfficialResultsLiveUpdates::refereesTopic(self::ROUND)], $updates[1]->getTopics());
        self::assertTrue($updates[1]->isPrivate());
        self::assertSame(['type' => 'official_results.refresh', 'roundId' => self::ROUND], json_decode($updates[1]->getData(), true, flags: JSON_THROW_ON_ERROR));

        // The public state endpoint: the same keys, no puzzle
        $this->browser->request('GET', '/round-stopwatch-state/' . self::ROUND);
        $state = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertSame(['roundId', 'status', 'startedAt', 'stoppedAt', 'minutesLimit', 'startedAtMs', 'stoppedAtMs', 'asOfMs', 'stateHash', 'serverNow', 'serverNowMs'], array_keys($state));
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $this->browser->getResponse()->getContent());

        // Nothing waits any more: no checkbox
        $page = $this->browser->request('GET', '/en/manage-round-stopwatch/' . self::ROUND);
        self::assertCount(0, $page->filter('[data-stopwatch-reveal]'));
    }

    public function testTheControlRoomsStaleListIsAConflict(): void
    {
        $roundPuzzleId = $this->stopwatchSecret();

        $this->post('/en/round-stopwatch/' . self::ROUND . '/schedule', ['_token' => 'csrf-token', 'countdown' => '10', 'reveal' => '1', 'reveal_hash' => str_repeat('0', 64)], json: true);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('{"error":"reveal_changed_meanwhile"}', $this->browser->getResponse()->getContent());
        self::assertSame([], self::hub()->getPublishedUpdates());
        self::assertNull($this->revealAt($roundPuzzleId));
        self::assertNull(self::getContainer()->get(Connection::class)->fetchOne('SELECT stopwatch_status FROM competition_round WHERE id = :id', ['id' => self::ROUND]));

        // The confirmed list: scheduled, revealed at the countdown's end (hidden until then)
        $this->post('/en/round-stopwatch/' . self::ROUND . '/schedule', ['_token' => 'csrf-token', 'countdown' => '10', 'reveal' => '1', 'reveal_hash' => $this->shownHash()], json: true);

        self::assertResponseIsSuccessful();
        $answer = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        self::assertIsArray($answer['stopwatch']);
        self::assertSame('scheduled', $answer['stopwatch']['status']);
        self::assertSame($answer['stopwatch']['startedAt'], (string) new \DateTimeImmutable((string) $this->revealAt($roundPuzzleId), new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM));
        self::assertCount(2, self::hub()->getPublishedUpdates());

        $page = $this->browser->request('GET', '/en/manage-round-stopwatch/' . self::ROUND);
        self::assertStringContainsString(self::SECRET_NAME, $page->filter('[data-stopwatch-reveal-countdown]')->text());
    }

    /**
     * The option is the competition toolkit's (COMPETITION_TOOLKIT_MANAGE: admins, organisers while the flag is on) - a
     * row using it keeps working for every organiser
     */
    public function testTheOptionIsOfferedToTheToolkitOnly(): void
    {
        $roundPuzzleId = $this->secret();
        $this->addMaintainer();

        $page = $this->browser->request('GET', '/en/manage-round-puzzles/' . self::ROUND);
        self::assertCount(1, $page->filter('#reveal-stopwatch-' . $roundPuzzleId));

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $page = $this->browser->request('GET', '/en/manage-round-puzzles/' . self::ROUND);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('#reveal-stopwatch-' . $roundPuzzleId));

        $this->post('/en/round-puzzle-reveal/' . $roundPuzzleId, $this->revealForm($page, $roundPuzzleId, 'stopwatch'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('automatic', $this->revealMode($roundPuzzleId));

        // An admin chose it: the organiser keeps it, and gets the checkbox at the start
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $page = $this->browser->request('GET', '/en/manage-round-puzzles/' . self::ROUND);
        $this->post('/en/round-puzzle-reveal/' . $roundPuzzleId, $this->revealForm($page, $roundPuzzleId, 'stopwatch'));
        self::assertResponseRedirects('/en/manage-round-puzzles/' . self::ROUND);
        self::assertSame('stopwatch', $this->revealMode($roundPuzzleId));
        // The reveal push of a reveal setting saved
        self::assertCount(1, self::hub()->getPublishedUpdates());

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $page = $this->browser->request('GET', '/en/manage-round-puzzles/' . self::ROUND);
        self::assertCount(1, $page->filter('#reveal-stopwatch-' . $roundPuzzleId));
        self::assertNotNull($page->filter('#reveal-stopwatch-' . $roundPuzzleId)->attr('checked'));
        self::assertStringContainsString('until the stopwatch starts', $page->filter('#round-puzzle-' . $roundPuzzleId . ' [data-reveal-status]')->text());
        $stopwatchPage = $this->browser->request('GET', '/en/manage-round-stopwatch/' . self::ROUND);
        self::assertCount(1, $stopwatchPage->filter('[data-stopwatch-reveal] input[type="checkbox"]'));
    }

    private function secret(): string
    {
        $roundPuzzleId = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: self::ROUND,
            userId: PlayerFixture::PLAYER_ADMIN,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: self::SECRET_NAME,
            piecesCount: 1000,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
            shownAutomaticRevealAt: self::automaticRevealOf(self::ROUND),
        ));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $roundPuzzleId->toString();
    }

    private function stopwatchSecret(): string
    {
        $roundPuzzleId = $this->secret();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ChangeRoundPuzzleReveal($roundPuzzleId, PuzzleHideMode::Entirely, RoundPuzzleReveal::Stopwatch));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $roundPuzzleId;
    }

    private function addMaintainer(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $competition = $entityManager->find(Competition::class, CompetitionApiFixture::COMPETITION_API);
        $maintainer = $entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertNotNull($competition);
        self::assertNotNull($maintainer);
        $competition->maintainers->add($maintainer);
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * The hash the control page renders for the tick
     */
    private function shownHash(): string
    {
        $page = $this->browser->request('GET', '/en/manage-round-stopwatch/' . self::ROUND);
        $hash = $page->filter('[data-round-stopwatch-reveal-hash-value]')->attr('data-round-stopwatch-reveal-hash-value');
        self::assertIsString($hash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);

        return $hash;
    }

    /**
     * @return array<string, string>
     */
    private function revealForm(Crawler $page, string $roundPuzzleId, string $mode): array
    {
        $form = $page->filter('form[action$="/en/round-puzzle-reveal/' . $roundPuzzleId . '"]');

        return [
            '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
            'automatic_reveal_at' => (string) $form->filter('input[name="automatic_reveal_at"]')->attr('value'),
            'hide_mode' => 'entirely',
            'reveal_mode' => $mode,
        ];
    }

    private function revealAt(string $roundPuzzleId): null|string
    {
        $revealAt = self::getContainer()->get(Connection::class)->fetchOne('SELECT reveal_at FROM competition_round_puzzle WHERE id = :id', ['id' => $roundPuzzleId]);

        return is_string($revealAt) ? $revealAt : null;
    }

    private function revealMode(string $roundPuzzleId): string
    {
        $mode = self::getContainer()->get(Connection::class)->fetchOne('SELECT reveal_mode FROM competition_round_puzzle WHERE id = :id', ['id' => $roundPuzzleId]);
        self::assertIsString($mode);

        return $mode;
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(string $url, array $fields, bool $json = false): void
    {
        self::hub()->reset();
        $server = ['HTTP_ORIGIN' => 'http://localhost'];

        if ($json) {
            $server['HTTP_ACCEPT'] = 'application/json';
        }

        $this->browser->request('POST', $url, $fields, [], $server);
    }

    private static function hub(): NullMercureHub
    {
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);

        return $hub;
    }
}

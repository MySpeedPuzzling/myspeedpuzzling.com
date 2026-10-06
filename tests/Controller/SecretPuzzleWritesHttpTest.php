<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\Stopwatch;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Nothing personal on a secret puzzle before its reveal, seen from the outside: the APIs answer 404 / 409, the pages
 * never print the puzzle to whoever may not see it, and its organisers are told up front and keep what they typed.
 */
final class SecretPuzzleWritesHttpTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string SECRET_NAME = 'Lighthouse Secret';

    public function testTheApiCreatesNoTimeOnASecretPuzzle(): void
    {
        $browser = self::createClient();
        $puzzleId = $this->secretPuzzle(PlayerFixture::PLAYER_REGULAR_USER_ID);

        $create = function (string $playerId) use ($browser, $puzzleId): void {
            PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, $playerId));
            $browser->request('POST', '/api/v1/me/solving-times', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
                'puzzle_id' => $puzzleId,
                'time' => '45:00',
            ]));
        };

        $create(PlayerFixture::PLAYER_PRIVATE);
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $browser->getResponse()->getContent());

        $create(PlayerFixture::PLAYER_REGULAR);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('still secret until', (string) $browser->getResponse()->getContent());

        self::assertFalse($this->database()->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE puzzle_id = :id', ['id' => $puzzleId]));
    }

    public function testTheApiAddsNoCollectionItemOfASecretPuzzle(): void
    {
        $browser = self::createClient();
        // Its adder is the member owning COLLECTION_PUBLIC
        $puzzleId = $this->secretPuzzle(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID);

        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_PRIVATE));
        $browser->request('POST', '/api/v1/me/collections/default/items', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['puzzle_id' => $puzzleId]));
        self::assertResponseStatusCodeSame(404);

        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_WITH_STRIPE));
        $browser->request('POST', '/api/v1/me/collections/' . CollectionFixture::COLLECTION_PUBLIC . '/items', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['puzzle_id' => $puzzleId]));
        self::assertResponseStatusCodeSame(409);

        self::assertFalse($this->database()->fetchOne('SELECT 1 FROM collection_item WHERE puzzle_id = :id', ['id' => $puzzleId]));
    }

    public function testASavedStopwatchNeverShowsSomebodyElsesSecret(): void
    {
        $browser = self::createClient();
        $puzzleId = $this->secretPuzzle(PlayerFixture::PLAYER_REGULAR_USER_ID);
        // As if started before the puzzle became secret
        $stopwatchId = $this->stopwatch(PlayerFixture::PLAYER_PRIVATE, $puzzleId);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        foreach (['/en/stopwatch/' . $stopwatchId, '/en/save-stopwatch/' . $stopwatchId, '/en/stopwatch'] as $url) {
            $browser->request('GET', $url);
            self::assertLessThan(400, $browser->getResponse()->getStatusCode(), $url);
            self::assertStringNotContainsString(self::SECRET_NAME, (string) $browser->getResponse()->getContent(), $url);
        }
    }

    public function testTheOrganiserIsToldBeforeTimingAndKeepsWhatTheyTyped(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $puzzleId = $this->secretPuzzle(PlayerFixture::PLAYER_REGULAR_USER_ID);
        $stopwatchId = $this->stopwatch(PlayerFixture::PLAYER_REGULAR, $puzzleId);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Up front: on the stopwatch, and on the add form
        foreach (['/en/stopwatch/' . $stopwatchId, '/en/save-stopwatch/' . $stopwatchId, '/en/puzzle-add/' . $puzzleId] as $url) {
            $crawler = $browser->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('you can save times after the reveal', $crawler->filter('[data-secret-puzzle-notice]')->text(), $url);
        }

        // A submit anyway: refused on the form, everything typed kept, nothing saved
        $crawler = $browser->request('GET', '/en/puzzle-add/' . $puzzleId);
        $crawler = $browser->request('POST', '/en/puzzle-add/' . $puzzleId, [
            'puzzle_add_form' => [
                '_token' => (string) $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value'),
                'mode' => 'speed_puzzling',
                'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                'puzzle' => $puzzleId,
                'timeHours' => '0',
                'timeMinutes' => '41',
                'timeSeconds' => '17',
                'finishedAt' => new DateTimeImmutable()->format('d.m.Y'),
                'comment' => 'Typed before the reveal',
                'collection' => '__system_collection__',
            ],
            'time_id' => (string) $crawler->filter('input[name="time_id"]')->attr('value'),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This puzzle is still secret until', $crawler->text());
        self::assertStringContainsString('Typed before the reveal', (string) $browser->getResponse()->getContent());
        self::assertFalse($this->database()->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE puzzle_id = :id', ['id' => $puzzleId]));
    }

    public function testTheEditFormNeverMovesATimeOntoASecretPuzzle(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $puzzleId = $this->secretPuzzle(PlayerFixture::PLAYER_REGULAR_USER_ID);

        // TIME_02 is PLAYER_PRIVATE's: the puzzle does not exist for them - and its name is not printed back
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $crawler = $this->submitEdit($browser, PuzzleSolvingTimeFixture::TIME_02, $puzzleId);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choose a puzzle from the list.', $crawler->text());
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $browser->getResponse()->getContent());

        // TIME_01 is its organiser's: told when it opens
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->submitEdit($browser, PuzzleSolvingTimeFixture::TIME_01, $puzzleId);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This puzzle is still secret until', $crawler->text());

        self::assertFalse($this->database()->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE puzzle_id = :id', ['id' => $puzzleId]));
    }

    public function testMultiscanSaysWhyInsteadOfFailing(): void
    {
        $browser = self::createClient();

        // Somebody else's secret (a row only the server writes - rows is not a writable prop - holding a puzzle that
        // became secret meanwhile): the tray does not fail, it says nothing was changed
        $hidden = $this->secretPuzzle(PlayerFixture::PLAYER_REGULAR_USER_ID);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $tray = $this->tray($browser, $hidden);
        $tray->call('apply');
        self::assertStringContainsString('Something went wrong and nothing was changed.', $tray->render()->toString());

        // The member's own secret: told when it opens
        $own = $this->secretPuzzle(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, 'Own Lighthouse');
        $tray = $this->tray($browser, $own);
        $tray->call('apply');
        self::assertStringContainsString('This puzzle is still secret until', $tray->render()->toString());

        // Linking a code to somebody else's puzzle whose picture is secret
        $pictureSecret = $this->secretPuzzle(PlayerFixture::PLAYER_REGULAR_USER_ID, 'Picture Lighthouse', PuzzleHideMode::ImageOnly);
        $tray = $this->createLiveComponent('MultiscanTray', ['resolvingEan' => '4005556175512', 'rows' => [
            ['key' => 'r1', 'ean' => '4005556175512', 'puzzleId' => null, 'state' => 'unknown', 'candidateIds' => []],
        ]], $browser);
        $tray->setRouteLocale('en');
        $tray->call('link', ['puzzleId' => $pictureSecret]);
        self::assertStringContainsString('Linking failed. Please try again.', $tray->render()->toString());

        self::assertFalse($this->database()->fetchOne('SELECT 1 FROM collection_item WHERE puzzle_id IN (:a, :b)', ['a' => $hidden, 'b' => $own]));
    }

    private function tray(KernelBrowser $browser, string $puzzleId): \Symfony\UX\LiveComponent\Test\TestLiveComponent
    {
        $tray = $this->createLiveComponent('MultiscanTray', [
            'action' => 'add_to_library',
            'rows' => [['key' => 'r1', 'ean' => '4005556175512', 'puzzleId' => $puzzleId, 'state' => 'resolved', 'candidateIds' => []]],
        ], $browser);
        $tray->setRouteLocale('en');

        return $tray;
    }

    private function submitEdit(KernelBrowser $browser, string $timeId, string $puzzleId): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $browser->request('GET', '/en/edit-time/' . $timeId);
        self::assertResponseIsSuccessful();

        return $browser->request('POST', '/en/edit-time/' . $timeId, [
            'edit_puzzle_solving_time_form' => [
                '_token' => (string) $crawler->filter('input[name="edit_puzzle_solving_time_form[_token]"]')->attr('value'),
                'mode' => 'speed_puzzling',
                'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                'puzzle' => $puzzleId,
                'timeHours' => '0',
                'timeMinutes' => '30',
                'timeSeconds' => '0',
                'finishedAt' => new DateTimeImmutable()->format('d.m.Y'),
            ],
        ]);
    }

    private function stopwatch(string $playerId, string $puzzleId): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $player = $entityManager->find(Player::class, $playerId);
        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        self::assertNotNull($player);
        self::assertNotNull($puzzle);

        $stopwatch = new Stopwatch(Uuid::uuid7(), $player, $puzzle);
        $stopwatch->start(new DateTimeImmutable('-20 minutes'));
        $entityManager->persist($stopwatch);
        $entityManager->flush();
        $entityManager->clear();

        return $stopwatch->id->toString();
    }

    private function secretPuzzle(string $adderUserId, string $name = self::SECRET_NAME, PuzzleHideMode $hideMode = PuzzleHideMode::Entirely): string
    {
        $roundPuzzleId = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionApiFixture::ROUND_FUTURE,
            userId: $adderUserId,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: $name,
            piecesCount: 1000,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: $hideMode,
        ));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $roundPuzzle = $entityManager->find(CompetitionRoundPuzzle::class, $roundPuzzleId->toString());
        self::assertNotNull($roundPuzzle);

        return $roundPuzzle->puzzle->id->toString();
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}

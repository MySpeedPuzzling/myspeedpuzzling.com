<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A round revealed its puzzle, but another round keeps it secret on the whole site longer: the round's page leaves it
 * out and says when it opens (nobody can log a time before), and the organiser is told on the round's puzzles page.
 */
final class RoundResultsSecretPuzzleTest extends WebTestCase
{
    private const string NAME = 'Held Elsewhere Secret';
    private const string RESULTS = '/en/events/wjpc-2024/results/qualification-round';

    public function testParticipantsAreToldWhenTheyCanLogTheirTimes(): void
    {
        $browser = self::createClient();
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // Created secret for the API competition's round in 5 days, by PLAYER_REGULAR
        $futureRow = Uuid::uuid7();
        $bus->dispatch($this->add($futureRow->toString(), CompetitionApiFixture::ROUND_FUTURE, self::NAME, 1000));
        $entityManager->clear();
        $puzzleRow = $entityManager->find(CompetitionRoundPuzzle::class, $futureRow->toString());
        self::assertNotNull($puzzleRow);
        $puzzleId = $puzzleRow->puzzle->id->toString();

        // ... also secret in the WJPC Qualification Round, which started an hour ago - so revealed there already
        $qualification = $entityManager->find(CompetitionRound::class, CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        self::assertNotNull($qualification);
        $qualification->startsAt = new DateTimeImmutable('-1 hour');
        $entityManager->flush();
        $qualificationRow = Uuid::uuid7();
        $bus->dispatch($this->add($qualificationRow->toString(), CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, $puzzleId, null));

        // Everybody, organisers included: the puzzle stays off the round's page - no name, no link, no "Add my
        // time" - and the page says when it opens
        foreach ([PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_REGULAR] as $playerId) {
            TestingLogin::asPlayer($browser, $playerId);
            $crawler = $browser->request('GET', self::RESULTS);
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString(self::NAME, (string) $browser->getResponse()->getContent());
            self::assertCount(0, $crawler->filter('a[href*="' . $puzzleId . '"]'));
            self::assertStringContainsString('One more puzzle of this round is still secret until', $crawler->filter('[data-still-secret]')->text());
        }

        // The organiser's page: the round is on, the puzzle still hidden - participants wait for the reveal
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', '/en/manage-round-puzzles/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('participants can log their times once it is revealed', $crawler->filter('#round-puzzle-' . $qualificationRow->toString() . ' [data-participants-wait]')->text());
    }

    private function add(string $roundPuzzleId, string $roundId, string $puzzle, null|int $piecesCount): AddPuzzleToCompetitionRound
    {
        return new AddPuzzleToCompetitionRound(
            roundPuzzleId: Uuid::fromString($roundPuzzleId),
            roundId: $roundId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: $puzzle,
            piecesCount: $piecesCount,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
        );
    }
}

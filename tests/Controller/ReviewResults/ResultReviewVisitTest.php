<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ReviewResults;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ResultReviewContact;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\ResultReviewContactType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The review page opened from a "Your results" e-mail (`?from=rc-<contactId>`) - the first visit of the player's
 * own e-mail is recorded for the admin funnel.
 */
final class ResultReviewVisitTest extends WebTestCase
{
    private const string DANA = DuplicateResultsFixture::PLAYER_TWINS;
    private const string TOM = DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE;

    public function testTheFirstVisitFromTheEmailIsRecorded(): void
    {
        $browser = self::createClient();
        $contactId = $this->sentContactOf($browser, self::DANA);
        TestingLogin::asPlayer($browser, self::DANA);

        $browser->request('GET', '/en/review-results?from=rc-' . $contactId);

        $this->assertResponseIsSuccessful();
        $visitedAt = $this->visitedAt($browser, $contactId);
        self::assertNotNull($visitedAt);

        $this->database($browser)->executeStatement(
            "UPDATE result_review_contact SET page_visited_at = page_visited_at - INTERVAL '1 hour' WHERE id = :id",
            ['id' => $contactId],
        );
        $firstVisit = $this->visitedAt($browser, $contactId);

        $browser->request('GET', '/en/review-results?from=rc-' . $contactId);

        self::assertSame($firstVisit, $this->visitedAt($browser, $contactId), 'Only the first visit counts');
    }

    public function testSomebodyElsesEmailOrAGarbledLinkRecordsNothing(): void
    {
        $browser = self::createClient();
        $contactId = $this->sentContactOf($browser, self::DANA);
        TestingLogin::asPlayer($browser, self::TOM);

        $browser->request('GET', '/en/review-results?from=rc-' . $contactId);
        $this->assertResponseIsSuccessful();
        self::assertNull($this->visitedAt($browser, $contactId));

        $browser->request('GET', '/en/review-results?from=rc-nonsense');
        $this->assertResponseIsSuccessful();
    }

    private function sentContactOf(KernelBrowser $browser, string $playerId): string
    {
        $container = $browser->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $contact = new ResultReviewContact(
            id: Uuid::uuid7(),
            player: $container->get(PlayerRepository::class)->get($playerId),
            type: ResultReviewContactType::First,
            priority: 1,
            lastActiveOn: null,
            caseIds: [],
            removalIds: [],
            plannedAt: new DateTimeImmutable('-1 day'),
        );
        $contact->sent([], [], new DateTimeImmutable('-1 day'));
        $entityManager->persist($contact);
        $entityManager->flush();

        return $contact->id->toString();
    }

    private function visitedAt(KernelBrowser $browser, string $contactId): null|string
    {
        $visitedAt = $this->database($browser)->fetchOne('SELECT page_visited_at FROM result_review_contact WHERE id = :id', ['id' => $contactId]);
        assert($visitedAt === null || is_string($visitedAt));

        return $visitedAt;
    }

    private function database(KernelBrowser $browser): Connection
    {
        return $browser->getContainer()->get(Connection::class);
    }
}

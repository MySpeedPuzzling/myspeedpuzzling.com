<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\BorrowRequest;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\BorrowRequest\BorrowRequestEmailsUnsubscribeUrl;
use SpeedPuzzling\Web\Tests\BorrowSeeding;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Ask to borrow" over the web (docs/features/wishlist-availability.md): the wishlist shows offers and favorites who
 * own a puzzle, the sheet asks them, the owner answers on the request page, only the two sides see it.
 */
final class BorrowRequestControllersTest extends WebTestCase
{
    use BorrowSeeding;

    private const string SAME_ORIGIN = 'http://localhost';

    private KernelBrowser $browser;
    private string $asker;
    private string $owner;
    private string $lighthouse;

    protected function setUp(): void
    {
        $this->browser = self::createClient();

        $this->asker = $this->seedPlayer('Jana', country: 'cz', userId: 'test|borrow-asker');
        $this->owner = $this->seedPlayer('Anna', userId: 'test|borrow-owner');
        $this->lighthouse = $this->seedPuzzle(1000, 'Lighthouse at Dusk');

        $this->seedFavorite($this->asker, $this->owner);
        $this->seedCollectionVisibility($this->owner, true);
        $this->seedOwned($this->owner, $this->lighthouse);
        $this->seedWished($this->asker, $this->lighthouse);
        $this->seedListing($this->seedPlayer('Seller'), $this->lighthouse, shipsTo: ['cz']);
        $this->borrowDatabase()->executeStatement("UPDATE player SET wish_list_visibility = 'public' WHERE id = :id", ['id' => $this->asker]);
    }

    public function testTheOwnerOfTheWishlistSeesOffersAndWhoCouldLendIt(): void
    {
        TestingLogin::asPlayer($this->browser, $this->asker);
        $crawler = $this->browser->request('GET', '/en/wish-list/' . $this->asker);

        $this->assertResponseIsSuccessful();
        $content = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('1 offer', $content);
        self::assertStringContainsString('Ships to you', $content);
        self::assertStringContainsString('Anna has it', $content);
        self::assertStringContainsString('Ask the fewest people for the most puzzles', $content);
        // Asking is free: a player without a membership gets the selection with "Ask to borrow"
        self::assertCount(1, $crawler->filter('button[formaction="/en/borrow-requests/ask"]'));
    }

    public function testAVisitorSeesTheOffersOnly(): void
    {
        TestingLogin::asPlayer($this->browser, $this->owner);
        $this->browser->request('GET', '/en/wish-list/' . $this->asker);

        $this->assertResponseIsSuccessful();
        $content = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('1 offer', $content);
        self::assertStringNotContainsString('has it', $content);
        self::assertStringNotContainsString('/en/borrow-requests/ask', $content);
    }

    public function testTheSheetAsksTheOwnerAndTheOwnerAnswersOnTheRequestPage(): void
    {
        TestingLogin::asPlayer($this->browser, $this->asker);

        $crawler = $this->browser->request('POST', '/en/borrow-requests/ask', [
            '_token' => 'csrf-token',
            'puzzleIds' => [$this->lighthouse],
        ], server: ['HTTP_ORIGIN' => self::SAME_ORIGIN, 'HTTP_TURBO_FRAME' => 'modal-frame']);
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-borrow-ask-sheet-target="chips"] input[name="assignments[' . $this->owner . '][]"][value="' . $this->lighthouse . '"]'));

        $this->browser->request('POST', '/en/borrow-requests/send', [
            '_token' => 'csrf-token',
            'sendId' => Uuid::uuid7()->toString(),
            'assignments' => [$this->owner => [$this->lighthouse]],
            'notes' => [$this->owner => 'Saturday?'],
        ], server: ['HTTP_ORIGIN' => self::SAME_ORIGIN]);
        $this->assertResponseRedirects('/en/wish-list/' . $this->asker);

        $requestId = $this->connection()->fetchOne('SELECT id FROM borrow_request WHERE asker_id = :asker', ['asker' => $this->asker]);
        self::assertIsString($requestId);
        $url = '/en/borrow-requests/' . $requestId;

        // The asker's wishlist now says it is waiting
        $this->browser->request('GET', '/en/wish-list/' . $this->asker);
        self::assertStringContainsString('Asked Anna · waiting', (string) $this->browser->getResponse()->getContent());

        // Nobody else sees the request
        TestingLogin::asPlayer($this->browser, $this->seedPlayer('Stranger', userId: 'test|borrow-stranger'));
        $this->browser->request('GET', $url);
        $this->assertResponseStatusCodeSame(404);

        TestingLogin::asPlayer($this->browser, $this->owner);
        $crawler = $this->browser->request('GET', $url);
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="borrow-request-answer-form"]'));
        self::assertStringContainsString('Saturday?', (string) $this->browser->getResponse()->getContent());

        $itemId = $this->connection()->fetchOne('SELECT id FROM borrow_request_item WHERE request_id = :id', ['id' => $requestId]);
        self::assertIsString($itemId);
        $this->browser->request('POST', $url, [
            '_token' => 'csrf-token',
            'answers' => [$itemId => 'yes'],
        ], server: ['HTTP_ORIGIN' => self::SAME_ORIGIN]);

        // A yes leads into the chat with the asker
        $conversationId = $this->connection()->fetchOne('SELECT id FROM conversation WHERE initiator_id = :owner AND recipient_id = :asker', ['owner' => $this->owner, 'asker' => $this->asker]);
        self::assertIsString($conversationId);
        $this->assertResponseRedirects();
        self::assertStringContainsString($conversationId, (string) $this->browser->getResponse()->headers->get('Location'));
        self::assertSame('yes', $this->connection()->fetchOne('SELECT status FROM borrow_request_item WHERE id = :id', ['id' => $itemId]));

        // Both tabs of the list render
        $this->browser->request('GET', '/en/borrow-requests?tab=received');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('From Jana', (string) $this->browser->getResponse()->getContent());

        TestingLogin::asPlayer($this->browser, $this->asker);
        $this->browser->request('GET', '/en/borrow-requests');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('To Anna', (string) $this->browser->getResponse()->getContent());
    }

    public function testTheSignedLinkUnsubscribesOnlyOnPost(): void
    {
        $url = $this->browser->getContainer()->get(BorrowRequestEmailsUnsubscribeUrl::class)->forPlayer($this->owner, 'en');

        $this->browser->request('GET', $url);
        $this->assertResponseIsSuccessful();
        self::assertTrue($this->browser->getContainer()->get(PlayerRepository::class)->get($this->owner)->borrowRequestEmails);

        $this->browser->request('POST', $url, ['List-Unsubscribe' => 'One-Click']);
        $this->assertResponseIsSuccessful();
        self::assertFalse($this->connection()->fetchOne('SELECT borrow_request_emails FROM player WHERE id = :id', ['id' => $this->owner]));

        $this->browser->request('POST', '/en/borrow-request-emails/unsubscribe/' . $this->owner);
        $this->assertResponseStatusCodeSame(404);
    }

    private function connection(): Connection
    {
        return $this->browser->getContainer()->get(Connection::class);
    }
}

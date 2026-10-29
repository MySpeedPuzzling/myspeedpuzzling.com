<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/features/internal-api.md, "E-mail blocks and messages to players": a chat message from the reviewer player
 * (PlayerFixture::PLAYER_ADMIN) reaches the player in the app
 */
final class TeamChatMessageInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    public function testContinuesTheAcceptedConversation(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'POST', $this->uri(PlayerFixture::PLAYER_REGULAR), ['text' => 'Hi John, e-mails to you keep bouncing.']);
        self::assertResponseStatusCodeSame(204);

        self::assertSame([ConversationFixture::CONVERSATION_ACCEPTED], $this->conversationsWithLastMessage(
            PlayerFixture::PLAYER_REGULAR,
            'Hi John, e-mails to you keep bouncing.',
        ));
    }

    public function testAcceptsAnIgnoredRequestAndStartsAnAcceptedOneWithSomebodyNew(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'POST', $this->uri(PlayerFixture::PLAYER_WITH_FAVORITES), ['text' => 'Hello again']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('accepted', $this->database()->fetchOne(
            'SELECT status FROM conversation WHERE id = :id',
            ['id' => ConversationFixture::CONVERSATION_IGNORED],
        ));

        self::callInternalApi($browser, 'POST', $this->uri(PlayerFixture::PLAYER_WITH_STRIPE), ['text' => 'Hello Sarah']);
        self::assertResponseStatusCodeSame(204);
        $conversations = $this->conversationsWithLastMessage(PlayerFixture::PLAYER_WITH_STRIPE, 'Hello Sarah');
        self::assertCount(1, $conversations);
        self::assertSame('accepted', $this->database()->fetchOne('SELECT status FROM conversation WHERE id = :id', ['id' => $conversations[0]]));
    }

    public function testNeedsText(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'POST', $this->uri(PlayerFixture::PLAYER_REGULAR), ['text' => '']);
        self::assertResponseStatusCodeSame(400);
    }

    private function uri(string $playerId): string
    {
        return '/internal-api/players/' . $playerId . '/messages';
    }

    /**
     * @return list<string> ids of the conversations between the reviewer and the player whose newest message is $content
     */
    private function conversationsWithLastMessage(string $playerId, string $content): array
    {
        /** @var list<string> $ids */
        $ids = $this->database()->fetchFirstColumn(<<<SQL
SELECT conversation.id
FROM conversation
INNER JOIN chat_message ON chat_message.conversation_id = conversation.id
WHERE ((conversation.initiator_id = :reviewer AND conversation.recipient_id = :player)
    OR (conversation.initiator_id = :player AND conversation.recipient_id = :reviewer))
  AND chat_message.sender_id = :reviewer
  AND chat_message.content = :content
SQL, ['reviewer' => PlayerFixture::PLAYER_ADMIN, 'player' => $playerId, 'content' => $content]);

        return $ids;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}

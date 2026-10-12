<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Inbox (docs/features/navigation INB-01, README §4.4 / D3): no route of its own (R13) - For you is `notifications`,
 * Messages is `conversations_list` - and both pages open with one Inbox header holding the two tabs, each with its own
 * unread count. The Inbox link opens the tab holding the unread items, decided from the badge statement's parts alone
 * (InboxBadgeParts::target()): Messages when only messages or message requests are unread - its Requests sub-tab when
 * nothing but requests is - For you otherwise, also when nothing is unread.
 *
 * PLAYER_REGULAR (ConversationFixture, ChatMessageFixture): an accepted conversation with an unread message and a pending
 * request to them - Messages 2, For you 0. Each case sets the rest in SQL.
 */
final class InboxTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;
    private const string TABS = 'main nav.inbox-tabs a.inbox-tab';
    // Today's Inbox link: the old header's bell (INB-02, until NAV-03/MENU-01 add the top-bar tool and the Menu row)
    private const string INBOX_LINK = 'header a[data-inbox-link]';

    /**
     * @return iterable<string, array{string, string, non-empty-string}>
     */
    public static function inboxTabs(): iterable
    {
        yield 'For you' => ['/en/notifications', 'for_you', 'For you · Inbox'];
        yield 'Messages' => ['/en/messages', 'messages', 'Messages · Inbox'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function inboxPages(): iterable
    {
        yield 'For you' => ['/en/notifications'];
        yield 'Messages' => ['/en/messages'];
    }

    /**
     * @param non-empty-string $title
     */
    #[DataProvider('inboxTabs')]
    public function testBothTabsRenderUnderOneInboxHeaderAndLinkEachOther(string $url, string $open, string $title): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        // One heading for both pages: "Inbox", the open tab named in the title first
        self::assertCount(1, $crawler->filter('main h1'));
        self::assertSame('Inbox', trim($crawler->filter('main h1')->text()));
        self::assertStringStartsWith($title, trim($crawler->filter('title')->text()));
        self::assertSame('Inbox', $crawler->filter('main nav.inbox-tabs')->attr('aria-label'));

        $tabs = $crawler->filter(self::TABS);
        self::assertSame(['/en/notifications', '/en/messages'], $tabs->each(static fn (Crawler $tab): string => (string) $tab->attr('href')));
        self::assertSame(['inbox/none/for_you', 'inbox/none/messages'], $tabs->each(static fn (Crawler $tab): string => (string) $tab->attr('data-nav')));
        self::assertSame(['For you', 'Messages'], $tabs->each(static fn (Crawler $tab): string => trim($tab->filter('.inbox-tab-label')->text())));

        // The open tab is the page; the other one links to its page
        $current = $crawler->filter(self::TABS . '[aria-current="page"]');
        self::assertCount(1, $current);
        self::assertSame('inbox/none/' . $open, $current->attr('data-nav'));

        // Each tab carries its own part of the one badge statement, read out with the tab's name
        foreach (['for_you', 'messages'] as $part) {
            $badge = $crawler->filter(self::TABS . '[data-nav="inbox/none/' . $part . '"] .inbox-badge[data-inbox-part="' . $part . '"]');
            self::assertCount(1, $badge, $part);
            self::assertNull($badge->attr('aria-hidden'), $part);
        }
    }

    public function testTheMessagesTabKeepsItsSubTabsAndTheBlockedPlayersButton(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        $crawler = $browser->request('GET', '/en/messages?tab=requests');
        self::assertResponseIsSuccessful();

        self::assertSame('inbox/none/messages', $crawler->filter(self::TABS . '[aria-current="page"]')->attr('data-nav'));
        self::assertCount(1, $crawler->filter('main .inbox-header-heading a[href="/en/blocked-users"]'));
        self::assertSame(
            ['/en/messages', '/en/messages?tab=requests', '/en/messages?tab=ignored'],
            $crawler->filter('main .nav-tabs .nav-link')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
        );
        self::assertSame('/en/messages?tab=requests', $crawler->filter('main .nav-tabs .nav-link.active')->attr('href'));
    }

    /**
     * Each tab shows its own count and the counts are the badge's parts: a message request counts once, under Messages.
     * The notifications page marks what it loaded read before the layout reads the badge, so For you is 0 there while
     * Messages keeps its number (decision 48).
     */
    public function testEachTabShowsItsOwnPartOfTheBadge(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $this->notifyGroupEdit($browser);

        $crawler = $browser->request('GET', '/en/messages');
        self::assertResponseIsSuccessful();
        self::assertSame(['1', '2'], $this->tabCounts($crawler));
        self::assertSame([false, false], $this->tabCountsHidden($crawler));

        $crawler = $browser->request('GET', '/en/notifications');
        self::assertResponseIsSuccessful();
        self::assertSame(['0', '2'], $this->tabCounts($crawler));
        self::assertSame([true, false], $this->tabCountsHidden($crawler));
    }

    /**
     * The header costs no statement: its two counts, the old header's bell and Messages tool read the one memoised badge
     * statement - no notification or conversation count of their own on either page.
     */
    #[DataProvider('inboxPages')]
    public function testTheHeaderReadsTheOneBadgeStatement(string $url): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $this->notifyGroupEdit($browser);

        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        $badges = 0;
        $otherCounts = [];

        foreach ($this->executedSql($browser) as $sql) {
            if (str_contains($sql, 'AS for_you') && str_contains($sql, 'AS pending_requests')) {
                $badges++;

                continue;
            }

            if (preg_match('/COUNT\((\*|DISTINCT c\.id)\)\s+FROM (notification|conversation)\b/', $sql) === 1) {
                $otherCounts[] = $sql;
            }
        }

        self::assertSame(1, $badges);
        self::assertSame([], $otherCounts, 'An unread count outside the badge statement');
    }

    /**
     * @return iterable<string, array{list<string>, string, string, array{for_you: string, messages: string}}>
     */
    public static function unreadStates(): iterable
    {
        // [setup steps, the Inbox link's href, the tab it opens, the badge parts]
        yield 'a notification unread (messages too)' => [['group_edit'], '/en/notifications', 'for_you', ['for_you' => '1', 'messages' => '2']];
        yield 'only a notification unread' => [['group_edit', 'read_messages', 'no_request'], '/en/notifications', 'for_you', ['for_you' => '1', 'messages' => '0']];
        yield 'only a message unread' => [['no_request'], '/en/messages', 'messages', ['for_you' => '0', 'messages' => '1']];
        yield 'a message and a request unread' => [[], '/en/messages', 'messages', ['for_you' => '0', 'messages' => '2']];
        yield 'only a request unread' => [['read_messages'], '/en/messages?tab=requests', 'messages', ['for_you' => '0', 'messages' => '1']];
        yield 'nothing unread' => [['read_messages', 'no_request'], '/en/notifications', 'for_you', ['for_you' => '0', 'messages' => '0']];
    }

    /**
     * The Inbox link's target as one function of the badge parts, on the rendered link (today the old header's bell;
     * NAV-03/MENU-01 add the same cases for the top-bar tool and the Menu row), and the tab the link then opens.
     *
     * @param list<string> $setup
     * @param array{for_you: string, messages: string} $parts
     */
    #[DataProvider('unreadStates')]
    public function testTheInboxLinkOpensTheTabHoldingTheUnreadItems(array $setup, string $href, string $tab, array $parts): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $connection = $this->connection($browser);

        foreach ($setup as $step) {
            if ($step === 'group_edit') {
                $this->notifyGroupEdit($browser);
            } elseif ($step === 'read_messages') {
                // Every message of the accepted conversation read: no unread conversation left
                $connection->executeStatement(
                    'UPDATE chat_message SET read_at = NOW() WHERE conversation_id = :id AND read_at IS NULL',
                    ['id' => ConversationFixture::CONVERSATION_ACCEPTED],
                );
            } elseif ($step === 'no_request') {
                // The request declined (ignored): no pending request left
                $connection->executeStatement(
                    "UPDATE conversation SET status = 'ignored', responded_at = NOW() WHERE id = :id",
                    ['id' => ConversationFixture::CONVERSATION_PENDING],
                );
            } else {
                self::fail('Unknown setup step ' . $step);
            }
        }

        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();

        $link = $crawler->filter(self::INBOX_LINK);
        self::assertCount(1, $link);
        self::assertSame($href, $link->attr('href'));
        self::assertSame($parts['for_you'], $crawler->filter('header .inbox-badge[data-inbox-part="for_you"]')->text());
        self::assertSame($parts['messages'], $crawler->filter('header .inbox-badge[data-inbox-part="messages"]')->text());

        // The link opens the Inbox on that tab
        $crawler = $browser->request('GET', $href);
        self::assertResponseIsSuccessful();
        self::assertSame('inbox/none/' . $tab, $crawler->filter(self::TABS . '[aria-current="page"]')->attr('data-nav'));

        if ($href === '/en/messages?tab=requests') {
            self::assertSame($href, $crawler->filter('main .nav-tabs .nav-link.active')->attr('href'));
        }
    }

    /**
     * Both pages in every locale: the H1 is the Inbox's name, the tabs link the locale's pages.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function locales(): iterable
    {
        yield 'cs' => ['/notifikace', '/cs/messages', 'Schránka'];
        yield 'de' => ['/de/benachrichtigungen', '/de/messages', 'Posteingang'];
        yield 'es' => ['/es/notificaciones', '/es/messages', 'Bandeja de entrada'];
        yield 'fr' => ['/fr/notifications', '/fr/messages', 'Boîte de réception'];
        yield 'ja' => ['/ja/' . rawurlencode('通知'), '/ja/messages', '受信箱'];
    }

    #[DataProvider('locales')]
    public function testTheInboxHeaderInEveryLocale(string $forYou, string $messages, string $inbox): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        foreach ([$forYou, $messages] as $url) {
            $crawler = $browser->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSame($inbox, trim($crawler->filter('main h1')->text()), $url);
            self::assertSame([$forYou, $messages], $crawler->filter(self::TABS)->each(
                static fn (Crawler $tab): string => (string) $tab->attr('href'),
            ), $url);
        }
    }

    private function connection(KernelBrowser $browser): Connection
    {
        return $browser->getContainer()->get(Connection::class);
    }

    private function notifyGroupEdit(KernelBrowser $browser): void
    {
        // An unread For you row by PLAYER_ADMIN, whom nobody blocks
        $this->connection($browser)->executeStatement(
            "INSERT INTO notification (id, player_id, type, notified_at, target_solving_time_id, actor_player_id) VALUES (:id, :player, 'GroupSolvingTimeEdited', NOW(), :time, :actor)",
            ['id' => Uuid::uuid7()->toString(), 'player' => self::PLAYER, 'time' => PuzzleSolvingTimeFixture::TIME_03, 'actor' => PlayerFixture::PLAYER_ADMIN],
        );
    }

    /**
     * @return list<string>
     */
    private function tabCounts(Crawler $crawler): array
    {
        return $crawler->filter(self::TABS . ' .inbox-badge')->each(static fn (Crawler $badge): string => trim($badge->text()));
    }

    /**
     * @return list<bool>
     */
    private function tabCountsHidden(Crawler $crawler): array
    {
        return $crawler->filter(self::TABS . ' .inbox-badge')->each(static fn (Crawler $badge): bool => $badge->attr('hidden') !== null);
    }
}

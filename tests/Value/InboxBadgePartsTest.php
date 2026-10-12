<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\InboxBadgeParts;

/**
 * The Inbox badge's composition (docs/features/navigation INB-02, README §4.4 / D3): two parts, their sum, "99+", the
 * tab the Inbox link opens and its accessible name.
 */
final class InboxBadgePartsTest extends TestCase
{
    public function testAMessageRequestCountsOnceUnderMessages(): void
    {
        // The request's NewConversationRequest notification is not in For you (GetInboxBadge), the request is here
        $parts = new InboxBadgeParts(forYou: 2, unreadConversations: 1, pendingRequests: 1);

        self::assertSame(2, $parts->messages());
        self::assertSame(4, $parts->total());
        self::assertSame(4, $parts->part(InboxBadgeParts::PART_TOTAL));
        self::assertSame(2, $parts->part(InboxBadgeParts::PART_FOR_YOU));
        self::assertSame(2, $parts->part(InboxBadgeParts::PART_MESSAGES));
    }

    public function testDisplayCapsAt99(): void
    {
        self::assertSame('0', InboxBadgeParts::none()->display());
        self::assertSame('99', new InboxBadgeParts(99, 0, 0)->display());
        self::assertSame('99+', new InboxBadgeParts(99, 1, 0)->display());
        self::assertSame('99', new InboxBadgeParts(99, 1, 0)->display(InboxBadgeParts::PART_FOR_YOU));
        self::assertSame('1', new InboxBadgeParts(99, 1, 0)->display(InboxBadgeParts::PART_MESSAGES));
        self::assertSame('99+', new InboxBadgeParts(0, 150, 3)->display(InboxBadgeParts::PART_MESSAGES));
    }

    public function testAnUnknownPartIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InboxBadgeParts::none()->part('bell');
    }

    /**
     * @return iterable<string, array{InboxBadgeParts, array{route: string, params: array<string, string>}, string, int}>
     */
    public static function provideTargets(): iterable
    {
        $forYou = ['route' => 'notifications', 'params' => []];
        $messages = ['route' => 'conversations_list', 'params' => []];
        $requests = ['route' => 'conversations_list', 'params' => ['tab' => 'requests']];

        yield 'nothing unread → For you' => [InboxBadgeParts::none(), $forYou, 'none', 0];
        yield 'only a message → Messages' => [new InboxBadgeParts(0, 1, 0), $messages, 'messages', 1];
        yield 'only a request → its Requests tab' => [new InboxBadgeParts(0, 0, 1), $requests, 'messages', 1];
        yield 'a message and a request → Messages' => [new InboxBadgeParts(0, 2, 1), $messages, 'messages', 3];
        yield 'a notification → For you' => [new InboxBadgeParts(1, 0, 0), $forYou, 'for_you', 1];
        yield 'a notification and messages → For you' => [new InboxBadgeParts(3, 2, 1), $forYou, 'mixed', 6];
    }

    /**
     * @param array{route: string, params: array<string, string>} $target
     */
    #[DataProvider('provideTargets')]
    public function testTheInboxOpensOnTheTabHoldingTheUnreadItems(InboxBadgeParts $parts, array $target, string $labelKind, int $labelCount): void
    {
        self::assertSame($target, $parts->target());
        self::assertSame($labelKind, $parts->labelKind());
        self::assertSame($labelCount, $parts->labelCount());
    }
}

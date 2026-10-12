<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use InvalidArgumentException;

/**
 * The Inbox badge (docs/features/navigation README §4.4 / D3) in its two parts:
 * - For you = unread notifications except SubscribedPlayerAddedTime and NewConversationRequest;
 * - Messages = unread accepted conversations + pending message requests to the player.
 * The badge is their sum and each Inbox tab shows its own part, so a message request (which also has a
 * NewConversationRequest notification) counts once - under Messages.
 *
 * assets/inbox_badge.js is the browser's twin of total/target/labelKind (realtime pushes carry the numbers only) -
 * pinned by tests/Value/InboxBadgeScriptTest.php.
 */
readonly final class InboxBadgeParts
{
    public const string PART_TOTAL = 'total';
    public const string PART_FOR_YOU = 'for_you';
    public const string PART_MESSAGES = 'messages';

    // Shown as "99+" above it
    public const int DISPLAY_CAP = 99;

    public function __construct(
        public int $forYou,
        public int $unreadConversations,
        public int $pendingRequests,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0, 0);
    }

    public function messages(): int
    {
        return $this->unreadConversations + $this->pendingRequests;
    }

    public function total(): int
    {
        return $this->forYou + $this->messages();
    }

    /**
     * The number of one badge instance (`data-inbox-part`).
     */
    public function part(string $part): int
    {
        return match ($part) {
            self::PART_TOTAL => $this->total(),
            self::PART_FOR_YOU => $this->forYou,
            self::PART_MESSAGES => $this->messages(),
            default => throw new InvalidArgumentException(sprintf('Unknown Inbox badge part "%s"', $part)),
        };
    }

    /**
     * What a badge instance shows: the number, "99+" above 99.
     */
    public function display(string $part = self::PART_TOTAL): string
    {
        $count = $this->part($part);

        return $count > self::DISPLAY_CAP ? self::DISPLAY_CAP . '+' : (string) $count;
    }

    /**
     * The tab the Inbox link opens (no inbox route of its own, R13): Messages when only messages or message requests
     * are unread - its Requests sub-tab when nothing but requests is - For you otherwise, also when nothing is unread.
     *
     * @return array{route: string, params: array<string, string>}
     */
    public function target(): array
    {
        if ($this->forYou === 0 && $this->messages() > 0) {
            if ($this->unreadConversations === 0) {
                return ['route' => 'conversations_list', 'params' => ['tab' => 'requests']];
            }

            return ['route' => 'conversations_list', 'params' => []];
        }

        return ['route' => 'notifications', 'params' => []];
    }

    /**
     * Which accessible name the Inbox link gets: inbox.badge.name.<kind>.
     *
     * @return 'none'|'messages'|'for_you'|'mixed'
     */
    public function labelKind(): string
    {
        if ($this->total() === 0) {
            return 'none';
        }

        if ($this->forYou === 0) {
            return 'messages';
        }

        if ($this->messages() === 0) {
            return 'for_you';
        }

        return 'mixed';
    }

    /**
     * The %count% of the accessible name.
     */
    public function labelCount(): int
    {
        return match ($this->labelKind()) {
            'messages' => $this->messages(),
            'for_you' => $this->forYou,
            default => $this->total(),
        };
    }
}

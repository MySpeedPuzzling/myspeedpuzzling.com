<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\NewsletterRecipient;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use SpeedPuzzling\Web\Value\NewsletterSubscriberStatus;

/**
 * The MySpeedPuzzling side of the newsletter audience: every player with an
 * e-mail (subscribed or not - unsubscribed players stay in Listmonk as
 * suppressions) plus every non-pending guest subscriber. Exactly one recipient
 * per e-mail; players win over guests, a subscribed player wins over an
 * unsubscribed duplicate.
 */
readonly final class GetNewsletterRecipients
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<NewsletterRecipient>
     */
    public function all(): array
    {
        return array_values($this->fetch(email: null));
    }

    public function byEmail(string $email): null|NewsletterRecipient
    {
        $normalized = mb_strtolower(trim($email));

        if ($normalized === '') {
            return null;
        }

        $recipients = $this->fetch(email: $normalized);

        return $recipients[$normalized] ?? null;
    }

    /**
     * @return array<string, NewsletterRecipient> keyed by e-mail
     */
    private function fetch(null|string $email): array
    {
        // The address is the account's (user_account.email is the single source of truth);
        // a player without an account row has no address and is not a recipient
        $playersQuery = <<<SQL
SELECT player.id, LOWER(TRIM(user_account.email)) AS email, player.name, player.locale, player.newsletter_enabled
FROM player
INNER JOIN user_account ON user_account.user_id = player.user_id
WHERE TRIM(user_account.email) != ''
SQL;

        $guestsQuery = <<<SQL
SELECT id, email, locale, status
FROM newsletter_subscriber
WHERE status != :pendingStatus
SQL;

        $parameters = ['pendingStatus' => NewsletterSubscriberStatus::Pending->value];

        if ($email !== null) {
            $playersQuery .= ' AND LOWER(TRIM(user_account.email)) = :email';
            $guestsQuery .= ' AND email = :email';
            $parameters['email'] = $email;
        }

        $recipients = [];

        /** @var array<array{id: string, email: string, name: null|string, locale: null|string, newsletter_enabled: bool}> $playerRows */
        $playerRows = $this->database->executeQuery($playersQuery, array_intersect_key($parameters, ['email' => true]))->fetchAllAssociative();

        foreach ($playerRows as $row) {
            $recipient = new NewsletterRecipient(
                audience: NewsletterAudience::Player,
                id: $row['id'],
                email: $row['email'],
                name: $row['name'] ?? '',
                locale: $row['locale'],
                subscribed: $row['newsletter_enabled'],
            );

            $existing = $recipients[$recipient->email] ?? null;

            // user_account.email is unique, so this only guards against case/whitespace twins
            if ($existing === null || ($existing->subscribed === false && $recipient->subscribed === true)) {
                $recipients[$recipient->email] = $recipient;
            }
        }

        /** @var array<array{id: string, email: string, locale: string, status: string}> $guestRows */
        $guestRows = $this->database->executeQuery($guestsQuery, $parameters)->fetchAllAssociative();

        foreach ($guestRows as $row) {
            if (isset($recipients[$row['email']])) {
                continue;
            }

            $recipients[$row['email']] = new NewsletterRecipient(
                audience: NewsletterAudience::Guest,
                id: $row['id'],
                email: $row['email'],
                name: '',
                locale: $row['locale'],
                subscribed: $row['status'] === NewsletterSubscriberStatus::Confirmed->value,
            );
        }

        return $recipients;
    }
}

<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DigestEmailLog;
use SpeedPuzzling\Web\Message\PrepareDigestEmailForPlayer;
use SpeedPuzzling\Web\Query\GetPlayersWithUnreadMessages;
use SpeedPuzzling\Web\Repository\DigestEmailLogRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class PrepareDigestEmailForPlayerHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private GetPlayersWithUnreadMessages $getPlayersWithUnreadMessages,
        private DigestEmailLogRepository $digestEmailLogRepository,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PrepareDigestEmailForPlayer $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        if ($player->email === null || !$player->emailNotificationsEnabled) {
            return;
        }

        if ($this->wasRecentlyNotified($message->playerId, $player->emailNotificationFrequency->value)) {
            $this->logger->notice('Player {playerId} was already notified recently, skipping.', [
                'playerId' => $message->playerId,
            ]);
            return;
        }

        $summaries = $this->getPlayersWithUnreadMessages->getUnreadSummaryForPlayer($message->playerId);
        $pendingRequestCount = $this->getPlayersWithUnreadMessages->countPendingRequestsForPlayer($message->playerId);

        if (count($summaries) === 0 && $pendingRequestCount === 0) {
            return;
        }

        $oldestUnreadMessageAt = count($summaries) > 0
            ? $this->getPlayersWithUnreadMessages->getOldestUnreadMessageAtForPlayer($message->playerId)
            : null;

        $oldestPendingRequestAt = $pendingRequestCount > 0
            ? $this->getPlayersWithUnreadMessages->getOldestPendingRequestAtForPlayer($message->playerId)
            : null;

        $subject = $this->translator->trans(
            'unread_messages.subject',
            domain: 'emails',
            locale: $player->locale,
        );

        $email = (new TemplatedEmail())
            ->from(new Address('notify@notify.myspeedpuzzling.com', 'MySpeedPuzzling'))
            ->to($player->email)
            ->locale($player->locale ?? 'en')
            ->subject($subject)
            ->htmlTemplate('emails/unread_digest.html.twig')
            ->context([
                'playerName' => $player->name,
                'summaries' => $summaries,
                'pendingRequestCount' => $pendingRequestCount,
                'locale' => $player->locale ?? 'en',
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'notifications');

        $this->mailer->send($email);

        $log = new DigestEmailLog(
            id: Uuid::uuid7(),
            player: $player,
            sentAt: $this->clock->now(),
            oldestUnreadMessageAt: $oldestUnreadMessageAt,
            oldestPendingRequestAt: $oldestPendingRequestAt,
        );

        $this->digestEmailLogRepository->save($log);
    }

    private function wasRecentlyNotified(string $playerId, string $frequency): bool
    {
        $frequencyInterval = match ($frequency) {
            '6_hours' => '6 hours',
            '12_hours' => '12 hours',
            '24_hours' => '24 hours',
            '48_hours' => '48 hours',
            '1_week' => '7 days',
            default => '24 hours',
        };

        $count = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM digest_email_log WHERE player_id = :playerId AND sent_at > NOW() - INTERVAL '{$frequencyInterval}'",
            ['playerId' => $playerId],
        );
        assert(is_int($count));

        return $count > 0;
    }
}

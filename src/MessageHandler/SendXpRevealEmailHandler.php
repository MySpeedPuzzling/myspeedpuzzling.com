<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateInterval;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ContentDigestLog;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\SendXpRevealEmail;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetXpProfile;
use SpeedPuzzling\Web\Repository\ContentDigestLogRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One-time launch reveal email (P7.T3): per-player level + XP + waiting achievements,
 * hero illustration embedded inline, transactional transport, one-per-player forever
 * (idempotency anchor = content_digest_log rows of type "xp_reveal").
 *
 * Refuses to send while the xp-system flag is active — launch order is: remove flag,
 * deploy, THEN run the send command.
 */
#[AsMessageHandler]
readonly final class SendXpRevealEmailHandler
{
    private const string LOG_TYPE = 'xp_reveal';
    private const string LOG_PERIOD = 'launch';

    public function __construct(
        private PlayerRepository $playerRepository,
        private GetPlayerProfile $getPlayerProfile,
        private GetXpProfile $getXpProfile,
        private GetBadges $getBadges,
        private ContentDigestLogRepository $contentDigestLogRepository,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private UriSigner $uriSigner,
        private UrlGeneratorInterface $urlGenerator,
        private Connection $database,
        private ClockInterface $clock,
        private XpFeatureGate $xpFeatureGate,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendXpRevealEmail $message): void
    {
        if ($this->xpFeatureGate->isEmailSendingEnabled() === false) {
            $this->logger->notice('XP reveal email suppressed — xp-system flag still active', [
                'playerId' => $message->playerId,
            ]);

            return;
        }

        try {
            $player = $this->playerRepository->get($message->playerId);
        } catch (PlayerNotFound) {
            return;
        }

        if (
            $player->email === null
            || $player->emailNotificationsEnabled === false
            || $player->experienceSystemOptedOut
            || $this->alreadySent($message->playerId)
        ) {
            return;
        }

        $profile = $this->getPlayerProfile->byId($message->playerId);
        $xpProfile = $this->getXpProfile->byPlayerId($message->playerId);
        $badgesCount = count($this->getBadges->forPlayer($message->playerId));
        $locale = $player->locale ?? 'en';

        $unsubscribeUrl = $this->uriSigner->sign(
            $this->urlGenerator->generate(
                'unsubscribe_content_digest',
                ['playerId' => $message->playerId],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            new DateInterval('P30D'),
        );

        $subject = $this->translator->trans('xp_reveal.subject', domain: 'emails', locale: $locale);

        $email = (new TemplatedEmail())
            ->from(new Address('notify@notify.myspeedpuzzling.com', 'MySpeedPuzzling'))
            ->to($player->email)
            ->locale($locale)
            ->subject($subject)
            ->htmlTemplate('emails/xp_reveal.html.twig')
            ->context([
                'playerName' => $player->name,
                'level' => $xpProfile->level,
                'xpTotal' => $xpProfile->xpTotal,
                'badgesCount' => $badgesCount,
                'isMember' => $profile->activeMembership,
                'locale' => $locale,
            ])
            ->addPart((new DataPart(new File(__DIR__ . '/../../public/img/xp/xp-hero-1200.png'), 'xp-hero', 'image/png'))->asInline());

        $headers = $email->getHeaders();
        $headers->addTextHeader('X-Transport', 'transactional');
        $headers->addTextHeader('List-Unsubscribe', sprintf('<%s>', $unsubscribeUrl));
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $this->mailer->send($email);

        $this->contentDigestLogRepository->save(new ContentDigestLog(
            id: Uuid::uuid7(),
            player: $player,
            digestType: self::LOG_TYPE,
            periodKey: self::LOG_PERIOD,
            sentAt: $this->clock->now(),
            hadActivity: true,
            status: ContentDigestLog::STATUS_SENT,
        ));
    }

    private function alreadySent(string $playerId): bool
    {
        $value = $this->database->fetchOne(
            "SELECT 1 FROM content_digest_log WHERE player_id = :playerId AND digest_type = 'xp_reveal' LIMIT 1",
            ['playerId' => $playerId],
        );

        return $value !== false;
    }
}

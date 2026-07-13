<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateInterval;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ContentDigestLog;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\SendPlayerContentDigest;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Repository\ContentDigestLogRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Digest\WeeklyDigestDataProvider;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use SpeedPuzzling\Web\Value\DigestPeriod;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends ONE weekly digest to ONE player. SMTP happens here via direct
 * TransportInterface->send() (README D1) so pacing, retry policy and failure
 * classification live exactly where the sending does — the shared async mailer
 * path stays untouched for transactional email.
 *
 * Failure model (README §6, doctrine_transaction middleware rolls back on ANY throw):
 * transient (connection, 4xx, sender-stage 5xx) → rethrow, Messenger retries with
 * backoff and the retry re-renders fresh content; permanent recipient-stage 55x →
 * catch, persist a failed_permanent log row, return normally so the message is acked.
 * Never throw after persisting.
 */
#[AsMessageHandler]
readonly final class SendPlayerContentDigestHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private GetPlayerProfile $getPlayerProfile,
        private ContentDigestLogRepository $contentDigestLogRepository,
        private WeeklyDigestDataProvider $weeklyDigestDataProvider,
        private TransportInterface $transport,
        private TranslatorInterface $translator,
        private UriSigner $uriSigner,
        private UrlGeneratorInterface $urlGenerator,
        private Connection $database,
        private ClockInterface $clock,
        private XpFeatureGate $xpFeatureGate,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendPlayerContentDigest $message): void
    {
        if ($message->digestType !== 'weekly') {
            return;
        }

        // No digest leaves the system while the xp-system flag is active (§1.10).
        if ($this->xpFeatureGate->isEmailSendingEnabled() === false) {
            $this->logger->notice('Digest suppressed by xp-system feature flag', ['playerId' => $message->playerId]);

            return;
        }

        $period = DigestPeriod::fromKey($message->periodKey);
        $now = $this->clock->now();

        // Staleness guard: nobody wants last week's digest delivered days late.
        if ($period->isStaleAt($now)) {
            $this->logger->notice('Skipping stale digest', ['playerId' => $message->playerId, 'period' => $period->key]);

            return;
        }

        // Eligibility re-check — hours pass between dispatch and consume, preferences change.
        try {
            $player = $this->playerRepository->get($message->playerId);
        } catch (PlayerNotFound) {
            return;
        }

        if (
            $player->email === null
            || $player->emailNotificationsEnabled === false
            || $player->experienceSystemOptedOut
            || in_array($player->contentDigestFrequency, [ContentDigestFrequency::Daily, ContentDigestFrequency::Weekly], true) === false
            || $this->alreadyLogged($message->playerId, $period->key)
        ) {
            return;
        }

        $profile = $this->getPlayerProfile->byId($message->playerId);
        $data = $this->weeklyDigestDataProvider->forPlayer($player, $period);
        $email = $player->email;
        $locale = $player->locale ?? 'en';

        $unsubscribeUrl = $this->uriSigner->sign(
            $this->urlGenerator->generate(
                'unsubscribe_content_digest',
                ['playerId' => $message->playerId],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            new DateInterval('P30D'),
        );

        $subject = $this->translator->trans(
            $data->hadActivity() ? 'content_digest.weekly.subject' : 'content_digest.weekly.subject_quiet',
            domain: 'emails',
            locale: $locale,
        );

        $templatedEmail = (new TemplatedEmail())
            ->from(new Address('notify@notify.myspeedpuzzling.com', 'MySpeedPuzzling'))
            ->to($email)
            ->locale($locale)
            ->subject($subject)
            ->htmlTemplate('emails/content_digest_weekly.html.twig')
            ->context([
                'playerName' => $player->name,
                'data' => $data,
                'isMember' => $profile->activeMembership,
                'hadActivity' => $data->hadActivity(),
                'unsubscribeUrl' => $unsubscribeUrl,
                'locale' => $locale,
            ]);

        $headers = $templatedEmail->getHeaders();
        $headers->addTextHeader('X-Transport', 'notifications');
        $headers->addTextHeader('List-Unsubscribe', sprintf('<%s>', $unsubscribeUrl));
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        $headers->addTextHeader('Precedence', 'bulk');

        try {
            $this->transport->send($templatedEmail);
        } catch (UnexpectedResponseException $exception) {
            // 550–553 are unambiguously recipient-stage (mailbox unknown/full/denied):
            // log the permanent failure and ack. 554 also fires at MAIL FROM (relay
            // denied) and 535 at auth — those are sender-side, so they bubble and retry
            // where Sentry can see them.
            if (in_array($exception->getCode(), [550, 551, 552, 553], true)) {
                $this->logger->warning('Digest permanently rejected for recipient', [
                    'playerId' => $message->playerId,
                    'code' => $exception->getCode(),
                    'exception' => $exception,
                ]);

                $this->contentDigestLogRepository->save(new ContentDigestLog(
                    id: Uuid::uuid7(),
                    player: $player,
                    digestType: 'weekly',
                    periodKey: $period->key,
                    sentAt: $now,
                    hadActivity: $data->hadActivity(),
                    status: ContentDigestLog::STATUS_FAILED_PERMANENT,
                ));

                return;
            }

            throw $exception;
        }

        $this->contentDigestLogRepository->save(new ContentDigestLog(
            id: Uuid::uuid7(),
            player: $player,
            digestType: 'weekly',
            periodKey: $period->key,
            sentAt: $now,
            hadActivity: $data->hadActivity(),
            status: ContentDigestLog::STATUS_SENT,
        ));
    }

    private function alreadyLogged(string $playerId, string $periodKey): bool
    {
        $value = $this->database->fetchOne(
            "SELECT 1 FROM content_digest_log WHERE player_id = :playerId AND digest_type = 'weekly' AND period_key = :periodKey LIMIT 1",
            ['playerId' => $playerId, 'periodKey' => $periodKey],
        );

        return $value !== false;
    }
}

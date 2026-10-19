<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\NoActivityInDigestPeriod;
use SpeedPuzzling\Web\Message\SendContentDigestPreview;
use SpeedPuzzling\Web\Results\PlayerPeriodActivity;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Digest\ContentDigestEmailFactory;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use SpeedPuzzling\Web\Value\ContentDigestKind;
use SpeedPuzzling\Web\Value\DigestPeriod;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The e-mail the player gets (or would get) for the period, through the same transport as the real send
 * (docs/features/content-digest/README.md "Sending"). Writes nothing: no log row, no run counters, the player's
 * switches are not consulted - a transport failure is thrown to the command. An activity digest of a period without
 * activity is refused (NoActivityInDigestPeriod): the real send never mails one.
 */
#[AsMessageHandler]
readonly final class SendContentDigestPreviewHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private ContentDigestEmailFactory $emailFactory,
        private TransportInterface $transport,
    ) {
    }

    /**
     * @throws NoActivityInDigestPeriod
     */
    public function __invoke(SendContentDigestPreview $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $period = DigestPeriod::fromKey($message->periodKey);

        $email = $this->emailFactory->create(
            $player,
            $message->emailAddress,
            $message->kind,
            $period,
            ContentDigestFrequency::forPeriodLength($period->length),
        );

        $activity = $email->getContext()['activity'] ?? null;

        if ($message->kind === ContentDigestKind::Activity && $activity instanceof PlayerPeriodActivity && $activity->hadActivity() === false) {
            throw new NoActivityInDigestPeriod();
        }

        $this->transport->send($email);
    }
}

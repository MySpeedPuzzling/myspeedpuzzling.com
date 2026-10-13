<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\ParticipantIsWaitlisted;
use SpeedPuzzling\Web\Message\RegenerateOnlineToken;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * "New link" (docs/features/online-competitions/README.md §5.4): the participant gets a new personal link and the old
 * one stops working at once - also for a participant who had none yet (their first link). Only for a participant who
 * is going: a removed one is not found, a waitlisted one holds no spot (ParticipantIsWaitlisted).
 *
 * The participant API keeps a short-lived token lookup for heartbeats in the shared cache pool `cache.app`
 * (`online_access_light.<token>`, stream A): the old token's entry is deleted here, so a heartbeat on the old link
 * finds nothing. (A heartbeat landing between this delete and the commit could cache the old lookup again - for the
 * entry's short lifetime, heartbeats only; every other call of the API reads the database.)
 */
#[AsMessageHandler]
readonly final class RegenerateOnlineTokenHandler
{
    // The participant API's heartbeat lookup per token - exactly the key stream A writes into `cache.app`
    private const string HEARTBEAT_LOOKUP_KEY_PREFIX = 'online_access_light.';

    public function __construct(
        private CompetitionParticipantRepository $competitionParticipantRepository,
        private ClockInterface $clock,
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws CompetitionParticipantNotFound
     * @throws ParticipantIsWaitlisted
     */
    public function __invoke(RegenerateOnlineToken $message): void
    {
        $participant = $this->competitionParticipantRepository->getActiveOfCompetition($message->competitionId, $message->participantId);

        if ($participant->isGoing() === false) {
            throw new ParticipantIsWaitlisted();
        }

        $oldToken = $participant->onlineToken;
        $participant->assignOnlineToken(OnlineToken::generate(), $this->clock->now());

        if ($oldToken !== null) {
            $this->cache->delete(self::HEARTBEAT_LOOKUP_KEY_PREFIX . $oldToken);
        }
    }
}

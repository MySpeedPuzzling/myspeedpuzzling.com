<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use SpeedPuzzling\Web\Value\OnlineParticipantSettings;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells the open participant pages of a round that its online timing settings changed (docs/features/
 * online-competitions/README.md §5.3) - so a phone picks up live video, "see each other", the studio, finish detection
 * or the time's-up question within a second, not at its next state fetch:
 * - the heartbeat's cached hashes of the round go first (OnlineAccessLight::forgetRound()): the next beat of every
 *   phone names the new settings hash - the way a change reaches a phone when the push does not;
 * - then a public Mercure update on the round's stopwatch topic - the one the phones follow anyway - carrying
 *   `{type: "online.settings", roundId, settingsHash}` (OnlineParticipantSettings): a phone holding another hash
 *   fetches its state (spread over up to a second). A message with a `type` is never a stopwatch state - the
 *   stopwatch pages, the projector, the live entry and the watch page let it pass, the control room polls once. It has
 *   no `asOfMs` on purpose: a page loaded before this message existed fetches its state for a push it can not order.
 *
 * Called by the controller after the dispatch returned, i.e. after the commit - never from a handler: a change
 * announced from inside the transaction would send every phone to read the settings before they are there, and a
 * refused or failed save never gets here. The hash is of the online round as committed. A Mercure failure never fails
 * the save: it is logged (warning) and the phones catch up on their next heartbeat.
 */
final readonly class OnlineRoundSettingsLiveUpdates
{
    public const string MESSAGE_TYPE = 'online.settings';

    public function __construct(
        private HubInterface $hub,
        private OnlineRoundRepository $onlineRoundRepository,
        private OnlineAccessLight $onlineAccessLight,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return null|string the settings hash published - null when nothing could be (logged)
     */
    public function settingsChanged(string $roundId): null|string
    {
        try {
            // A cache that fails never fails the save either - the hashes then turn over within ROUND_HASH_TTL_SECONDS
            $this->onlineAccessLight->forgetRound($roundId);
            $onlineRound = $this->onlineRoundRepository->ofRound($roundId);

            if ($onlineRound === null) {
                return null;
            }

            $settingsHash = OnlineParticipantSettings::of($onlineRound)->hash();
            $roundId = $onlineRound->round->id->toString();

            $this->hub->publish(new Update(
                OfficialResultsSubscription::stopwatchTopic($roundId),
                json_encode([
                    'type' => self::MESSAGE_TYPE,
                    'roundId' => $roundId,
                    'settingsHash' => $settingsHash,
                ], JSON_THROW_ON_ERROR),
                private: false,
            ));

            return $settingsHash;
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not publish an online round settings update', [
                'round_id' => $roundId,
                'exception' => $exception,
            ]);

            return null;
        }
    }
}

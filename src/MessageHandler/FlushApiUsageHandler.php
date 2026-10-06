<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\FlushApiUsage;
use SpeedPuzzling\Web\Repository\ApiUsageRepository;
use SpeedPuzzling\Web\Repository\OAuth2UserConsentRepository;
use SpeedPuzzling\Web\Repository\PersonalAccessTokenRepository;
use SpeedPuzzling\Web\Services\ApiUsage\ApiUsageCounter;
use SpeedPuzzling\Web\Value\ApiCallerKind;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Copies the API usage counters into the daily tables and stamps "last used" on
 * the tokens and consents behind them (docs/features/api/usage-statistics.md).
 * Idempotent: the counters hold totals, the tables keep the greater number, and
 * last_used_at never moves back - running it twice, or two runs at once, changes
 * nothing.
 */
#[AsMessageHandler]
readonly final class FlushApiUsageHandler
{
    public function __construct(
        private ApiUsageCounter $apiUsageCounter,
        private ApiUsageRepository $apiUsageRepository,
        private PersonalAccessTokenRepository $personalAccessTokenRepository,
        private OAuth2UserConsentRepository $oAuth2UserConsentRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int stored snapshot rows (counts + callers), for the console output
     */
    public function __invoke(FlushApiUsage $message): int
    {
        $now = $this->clock->now();
        $stored = 0;
        /** @var array<string, DateTimeImmutable> $tokensUsedAt */
        $tokensUsedAt = [];
        /** @var array<string, DateTimeImmutable> $consentsUsedAt keyed "{playerId} {clientId}" */
        $consentsUsedAt = [];

        for ($daysAgo = $message->days - 1; $daysAgo >= 0; $daysAgo--) {
            $snapshot = $this->apiUsageCounter->snapshot($now->modify("-{$daysAgo} days"));

            if ($snapshot->isEmpty()) {
                continue;
            }

            $this->apiUsageRepository->storeSnapshot($snapshot);
            $stored += count($snapshot->counts) + count($snapshot->callers);

            foreach ($snapshot->callers as $activity) {
                $caller = $activity->caller;

                if ($caller->kind === ApiCallerKind::PersonalAccessToken && $caller->personalAccessTokenId !== null) {
                    $tokensUsedAt[$caller->personalAccessTokenId] = max($tokensUsedAt[$caller->personalAccessTokenId] ?? $activity->lastRequestAt, $activity->lastRequestAt);
                }

                if ($caller->kind === ApiCallerKind::OAuth2User && $caller->playerId !== null && $caller->oauth2ClientIdentifier !== null) {
                    $key = $caller->playerId . ' ' . $caller->oauth2ClientIdentifier;
                    $consentsUsedAt[$key] = max($consentsUsedAt[$key] ?? $activity->lastRequestAt, $activity->lastRequestAt);
                }
            }
        }

        foreach ($this->personalAccessTokenRepository->findByIds(array_map('strval', array_keys($tokensUsedAt))) as $token) {
            $token->markUsedAt($tokensUsedAt[$token->id->toString()]);
        }

        $playerIds = array_values(array_unique(array_map(
            static fn (string $key): string => explode(' ', $key, 2)[0],
            array_keys($consentsUsedAt),
        )));

        foreach ($this->oAuth2UserConsentRepository->findByPlayers($playerIds) as $consent) {
            $usedAt = $consentsUsedAt[$consent->player->id->toString() . ' ' . $consent->clientIdentifier] ?? null;

            if ($usedAt !== null) {
                $consent->markUsedAt($usedAt);
            }
        }

        return $stored;
    }
}

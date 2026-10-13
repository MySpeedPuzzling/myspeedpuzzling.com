<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\LiveKit;

use Psr\Log\LoggerInterface;
use Redis;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * StudioInviteSignal in the `redis-state` instance (the online presence's connection): one key per round and
 * participant, `{prefix}{roundId}:{participantId}` = the invitation's id, expiring with the invitation - redis-state is
 * capped at 64 MB with noeviction, so nothing lives there without a TTL. Clearing is one atomic compare-and-delete, so
 * the end of an old invitation never clears a newer one.
 *
 * Fails open (StudioInviteSignal): a Redis error is logged at info - the data message and the state carry the
 * invitation anyway. Stateless between requests apart from the shared connection (FrankenPHP worker mode).
 */
final readonly class RedisStudioInviteSignal implements StudioInviteSignal
{
    private const string CLEAR_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

    public function __construct(
        #[Autowire(service: 'api_usage.redis')]
        private Redis $redis,
        private LoggerInterface $logger,
        private string $keyPrefix = 'online:studio-invite:',
    ) {
    }

    public function raise(string $roundId, string $participantId, string $inviteId, int $ttlSeconds): void
    {
        try {
            if ($this->redis->set($this->key($roundId, $participantId), strtolower($inviteId), ['ex' => max(1, $ttlSeconds)]) === false) {
                throw new \RuntimeException((string) $this->redis->getLastError());
            }
        } catch (\Throwable $e) {
            $this->logger->info('Raising the studio invitation signal failed - the phone learns it from the data message or the state', [
                'round_id' => $roundId,
                'exception' => $e,
            ]);
        }
    }

    public function open(string $roundId, string $participantId): null|string
    {
        try {
            $inviteId = $this->redis->get($this->key($roundId, $participantId));
        } catch (\Throwable $e) {
            $this->logger->info('Reading the studio invitation signal failed - the phone learns it from the data message or the state', [
                'round_id' => $roundId,
                'exception' => $e,
            ]);

            return null;
        }

        return is_string($inviteId) && $inviteId !== '' ? $inviteId : null;
    }

    public function clear(string $roundId, string $participantId, string $inviteId): void
    {
        $arguments = [$this->key($roundId, $participantId), strtolower($inviteId)];

        try {
            $result = $this->redis->evalSha(sha1(self::CLEAR_SCRIPT), $arguments, 1);

            if ($result === false && str_starts_with((string) $this->redis->getLastError(), 'NOSCRIPT')) {
                // Redis restarted (or was never asked before) - EVAL loads the script into its cache
                $this->redis->clearLastError();
                $result = $this->redis->eval(self::CLEAR_SCRIPT, $arguments, 1);
            }

            if ($result === false) {
                $error = $this->redis->getLastError();
                $this->redis->clearLastError();

                throw new \RuntimeException((string) $error);
            }
        } catch (\Throwable $e) {
            // Left behind, it runs out with the invitation - a heartbeat in between names an answered invitation, the
            // state tells the phone it is answered
            $this->logger->info('Clearing the studio invitation signal failed - it runs out on its own', [
                'round_id' => $roundId,
                'exception' => $e,
            ]);
        }
    }

    private function key(string $roundId, string $participantId): string
    {
        return $this->keyPrefix . strtolower($roundId) . ':' . strtolower($participantId);
    }
}

<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\HubRegistry;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * The organiser pages' subscriber token (docs/features/competitions-management/official-results.md "Live updates"):
 * exactly the listed topics, subscribe only, an hour long, signed with the hub's key.
 */
final class OfficialResultsSubscriptionTest extends TestCase
{
    private const string SECRET = 'the-hubs-subscriber-key-for-this-test-only';
    private const string ROUND = '018D0020-0000-0000-0000-000000000101';
    private const string OTHER_ROUND = '018d0020-0000-0000-0000-000000000102';

    public function testARoundsSubscriptionCoversItsPrivateTopicAndItsStopwatchForAnHour(): void
    {
        $subscription = $this->subscription(new LcobucciFactory(self::SECRET))->forRound(self::ROUND);

        self::assertNotNull($subscription);
        self::assertSame('https://myspeedpuzzling.test/.well-known/mercure', $subscription['url']);
        self::assertSame(['/round-results/' . strtolower(self::ROUND), '/round-stopwatch/' . strtolower(self::ROUND)], $subscription['topics']);
        self::assertSame('2026-10-07T11:30:00+00:00', $subscription['expiresAt']);
        self::assertSame(3600, $subscription['expiresIn']);

        $token = self::verified($subscription['token']);
        self::assertSame(['publish' => [], 'subscribe' => $subscription['topics']], $token->claims()->get('mercure'));
        self::assertEquals(new \DateTimeImmutable('2026-10-07 11:30:00 UTC'), $token->claims()->get('exp'));
        self::assertStringNotContainsString('{', implode(' ', $subscription['topics']), 'never a URI template granting every round');
    }

    public function testTheOverviewsSubscriptionListsEveryRoundOnce(): void
    {
        $subscription = $this->subscription(new LcobucciFactory(self::SECRET))->forRounds([self::OTHER_ROUND, strtolower(self::ROUND), self::OTHER_ROUND]);

        self::assertNotNull($subscription);
        self::assertSame(['/round-results/' . self::OTHER_ROUND, '/round-results/' . strtolower(self::ROUND)], $subscription['topics']);
        self::assertSame(['publish' => [], 'subscribe' => $subscription['topics']], self::verified($subscription['token'])->claims()->get('mercure'));
    }

    public function testNoRoundsNoSubscription(): void
    {
        self::assertNull($this->subscription(new LcobucciFactory(self::SECRET))->forRounds([]));
    }

    public function testATokenSignedWithAnotherKeyDoesNotVerify(): void
    {
        $subscription = $this->subscription(new LcobucciFactory('some-other-key-the-hub-does-not-know'))->forRound(self::ROUND);

        self::assertNotNull($subscription);

        $configuration = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(self::SECRET));
        self::assertNotSame('', $subscription['token']);
        self::assertFalse($configuration->validator()->validate(
            $configuration->parser()->parse($subscription['token']),
            new SignedWith($configuration->signer(), $configuration->verificationKey()),
        ));
    }

    public function testNoTokenWhenTheHubCanNotMakeOne(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = (is_string($level) ? $level : '?') . ': ' . $message;
            }
        };

        $withoutFactory = new OfficialResultsSubscription(self::registry(null), new MockClock('2026-10-07 10:30:00 UTC'), $logger);
        self::assertNull($withoutFactory->forRound(self::ROUND));

        $failing = new OfficialResultsSubscription(self::registry(new class () implements TokenFactoryInterface {
            public function create(null|array $subscribe = [], null|array $publish = [], array $additionalClaims = []): string
            {
                throw new \RuntimeException('no key');
            }
        }), new MockClock('2026-10-07 10:30:00 UTC'), $logger);
        self::assertNull($failing->forRound(self::ROUND));

        self::assertCount(2, $logger->records);
        self::assertStringStartsWith('warning: ', $logger->records[0]);
        self::assertStringStartsWith('warning: ', $logger->records[1]);
    }

    private function subscription(TokenFactoryInterface $factory): OfficialResultsSubscription
    {
        return new OfficialResultsSubscription(self::registry($factory), new MockClock('2026-10-07 10:30:00.123456 UTC'), new \Psr\Log\NullLogger());
    }

    private static function registry(null|TokenFactoryInterface $factory): HubRegistry
    {
        return new HubRegistry(new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('publisher'),
            static fn (Update $update): string => 'id',
            $factory,
            'https://myspeedpuzzling.test/.well-known/mercure',
        ));
    }

    private static function verified(string $jwt): UnencryptedToken
    {
        self::assertNotSame('', $jwt);
        $configuration = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(self::SECRET));
        $token = $configuration->parser()->parse($jwt);

        self::assertInstanceOf(UnencryptedToken::class, $token);
        self::assertTrue($configuration->validator()->validate($token, new SignedWith($configuration->signer(), $configuration->verificationKey())), 'signed with the hub key');

        return $token;
    }
}

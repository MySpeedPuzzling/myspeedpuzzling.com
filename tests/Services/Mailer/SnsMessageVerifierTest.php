<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Mailer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\InvalidSnsMessage;
use SpeedPuzzling\Web\Services\Mailer\Sns\SnsMessageVerifier;
use SpeedPuzzling\Web\Tests\SignsSnsMessages;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SnsMessageVerifierTest extends TestCase
{
    use SignsSnsMessages;

    /** @var list<string> */
    private array $fetchedUrls = [];

    public function testASignedNotificationOfOurTopicPasses(): void
    {
        foreach (['1', '2'] as $signatureVersion) {
            $message = self::signedSnsMessage(['Type' => 'Notification', 'Message' => '{"eventType":"Delivery"}', 'Subject' => 'Amazon SES Email Event Notification'], $signatureVersion);

            self::assertSame('{"eventType":"Delivery"}', $this->verifier()->verify(json_encode($message, JSON_THROW_ON_ERROR))['Message']);
        }

        self::assertSame([self::TEST_CERTIFICATE_URL, self::TEST_CERTIFICATE_URL], $this->fetchedUrls);
    }

    public function testASubscriptionConfirmationPasses(): void
    {
        $message = self::signedSnsMessage([
            'Type' => 'SubscriptionConfirmation',
            'Message' => 'You have chosen to subscribe to the topic',
            'Token' => 'abc123',
            'SubscribeURL' => 'https://sns.eu-central-1.amazonaws.com/?Action=ConfirmSubscription&Token=abc123',
        ]);

        self::assertSame('abc123', $this->verifier()->verify(json_encode($message, JSON_THROW_ON_ERROR))['Token']);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $tamper
     */
    #[DataProvider('refusedMessages')]
    public function testRefused(callable $tamper): void
    {
        $message = $tamper(self::signedSnsMessage(['Type' => 'Notification', 'Message' => '{"eventType":"Bounce"}']));

        $this->expectException(InvalidSnsMessage::class);

        $this->verifier()->verify(json_encode($message, JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function refusedMessages(): iterable
    {
        yield 'message changed after signing' => [static fn (array $m): array => ['Message' => '{"eventType":"Complaint"}'] + $m];
        yield 'another topic, even when signed' => [static fn (array $m): array => self::signedSnsMessage(['Type' => 'Notification', 'Message' => '{}', 'TopicArn' => 'arn:aws:sns:eu-central-1:111111111111:someone-else'])];
        yield 'certificate from elsewhere' => [static fn (array $m): array => self::signedSnsMessage(['Type' => 'Notification', 'Message' => '{}', 'SigningCertURL' => 'https://sns.eu-central-1.amazonaws.com.attacker.example/cert.pem'])];
        yield 'certificate over http' => [static fn (array $m): array => self::signedSnsMessage(['Type' => 'Notification', 'Message' => '{}', 'SigningCertURL' => 'http://sns.eu-central-1.amazonaws.com/cert.pem'])];
        yield 'unknown signature version' => [static fn (array $m): array => ['SignatureVersion' => '3'] + $m];
        yield 'no signature' => [static fn (array $m): array => ['Signature' => ''] + $m];
    }

    public function testNotJsonIsRefused(): void
    {
        $this->expectException(InvalidSnsMessage::class);

        $this->verifier()->verify('not json');
    }

    public function testWithoutTopicsTheEndpointIsOff(): void
    {
        self::assertFalse(new SnsMessageVerifier(new MockHttpClient(), new ArrayAdapter(), '')->isEnabled());
        self::assertTrue($this->verifier()->isEnabled());
    }

    public function testOnlySnsUrlsAreSnsUrls(): void
    {
        self::assertTrue(SnsMessageVerifier::isSnsUrl('https://sns.eu-central-1.amazonaws.com/?Action=ConfirmSubscription&Token=x'));
        self::assertFalse(SnsMessageVerifier::isSnsUrl('https://sns.eu-central-1.amazonaws.com.evil.example/'));
        self::assertFalse(SnsMessageVerifier::isSnsUrl('https://user:pass@sns.eu-central-1.amazonaws.com/'));
        self::assertFalse(SnsMessageVerifier::isSnsUrl('https://sns.eu-central-1.amazonaws.com:8443/'));
        self::assertFalse(SnsMessageVerifier::isSnsUrl('http://sns.eu-central-1.amazonaws.com/'));
        self::assertFalse(SnsMessageVerifier::isSnsUrl('https://example.com/'));
    }

    private function verifier(): SnsMessageVerifier
    {
        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->fetchedUrls[] = $url;

            return new MockResponse(self::snsTestCertificate());
        });

        return new SnsMessageVerifier($httpClient, new ArrayAdapter(), self::TEST_TOPIC_ARN . ', arn:aws:sns:eu-central-1:000000000000:other');
    }
}

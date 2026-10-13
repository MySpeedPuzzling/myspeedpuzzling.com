<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Message\ProcessSesEvent;
use SpeedPuzzling\Web\Tests\SignsSnsMessages;
use SpeedPuzzling\Web\Tests\TestDouble\SnsHttpMock;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * POST /-/ses-events: Amazon SNS delivers SES events here (docs/features/mass-mailing/README.md)
 */
final class SesEventsControllerTest extends WebTestCase
{
    use SignsSnsMessages;

    protected function setUp(): void
    {
        SnsHttpMock::useCertificate(self::snsTestCertificate());
    }

    public function testASignedNotificationIsQueuedForTheHandler(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();

        $this->post($browser, self::signedSesNotification(['eventType' => 'Delivery', 'mail' => ['destination' => ['a@example.com']]]));

        self::assertResponseStatusCodeSame(204);
        $queued = $this->queued($browser);
        self::assertCount(1, $queued);
        self::assertStringContainsString('"eventType":"Delivery"', $queued[0]->eventJson);
    }

    public function testASubscriptionIsConfirmedAtItsSnsUrl(): void
    {
        $browser = self::createClient();
        $subscribeUrl = 'https://sns.eu-central-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=x&Token=t0k3n';

        $this->post($browser, self::signedSnsMessage([
            'Type' => 'SubscriptionConfirmation',
            'Message' => 'You have chosen to subscribe to the topic',
            'Token' => 't0k3n',
            'SubscribeURL' => $subscribeUrl,
        ]));

        self::assertResponseStatusCodeSame(204);
        self::assertContains($subscribeUrl, SnsHttpMock::requestedUrls());
    }

    public function testAForgedOrForeignMessageIsRefusedAndNothingIsQueued(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();

        $forged = self::signedSesNotification(['eventType' => 'Delivery']);
        $forged['Message'] = '{"eventType":"Complaint"}';
        $this->post($browser, $forged);
        self::assertResponseStatusCodeSame(403);

        $this->post($browser, self::signedSnsMessage(['Type' => 'Notification', 'Message' => '{}', 'TopicArn' => 'arn:aws:sns:eu-central-1:111111111111:not-ours']));
        self::assertResponseStatusCodeSame(403);

        $browser->request('POST', '/-/ses-events', server: ['CONTENT_TYPE' => 'text/plain'], content: 'hello');
        self::assertResponseStatusCodeSame(403);

        self::assertSame([], $this->queued($browser));
    }

    public function testOnlyPost(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/-/ses-events');

        self::assertResponseStatusCodeSame(405);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function post(KernelBrowser $browser, array $message): void
    {
        // SNS posts JSON as text/plain
        $browser->request('POST', '/-/ses-events', server: ['CONTENT_TYPE' => 'text/plain; charset=UTF-8'], content: json_encode($message, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<ProcessSesEvent>
     */
    private function queued(KernelBrowser $browser): array
    {
        $async = $browser->getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);

        return array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $async->getSent()),
            static fn (object $message): bool => $message instanceof ProcessSesEvent,
        ));
    }
}

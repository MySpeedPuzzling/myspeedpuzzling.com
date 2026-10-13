<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Logging;

use Monolog\Handler\TestHandler;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\State\HubInterface;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The redaction is wired in, not only written: what the application logs goes through RedactOnlineTokensProcessor,
 * and the Sentry client sends through OnlineTokenSentryScrubber.
 */
final class OnlineTokenRedactionWiringTest extends KernelTestCase
{
    public function testWhatIsLoggedIsRedacted(): void
    {
        self::bootKernel();
        $logger = self::getContainer()->get('monolog.logger');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $logger->warning('Something on /api/compete/{token}/state', ['token' => OnlineRoundsFixture::TOKEN_CONNECTED, 'uri' => '/api/compete/' . OnlineRoundsFixture::TOKEN_CONNECTED . '/state']);

        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame(['token' => '[redacted]', 'uri' => '/api/compete/[redacted]/state'], $records[0]->context);
    }

    public function testSentryGetsScrubbedEvents(): void
    {
        self::bootKernel();
        $options = self::getContainer()->get(HubInterface::class)->getClient()?->getOptions();
        self::assertNotNull($options);

        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://myspeedpuzzling.com/en/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS]);
        $sent = ($options->getBeforeSendCallback())($event, null);
        self::assertInstanceOf(Event::class, $sent);
        self::assertSame(['url' => 'https://myspeedpuzzling.com/en/compete/[redacted]'], $sent->getRequest());

        $transaction = Event::createTransaction();
        $transaction->setTransaction('GET /api/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS . '/state');
        $sentTransaction = ($options->getBeforeSendTransactionCallback())($transaction, null);
        self::assertInstanceOf(Event::class, $sentTransaction);
        self::assertSame('GET /api/compete/[redacted]/state', $sentTransaction->getTransaction());

        $breadcrumb = ($options->getBeforeBreadcrumbCallback())(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', '/soutezit/' . OnlineRoundsFixture::TOKEN_ANONYMOUS));
        self::assertInstanceOf(Breadcrumb::class, $breadcrumb);
        self::assertSame('/soutezit/[redacted]', $breadcrumb->getMessage());
    }
}

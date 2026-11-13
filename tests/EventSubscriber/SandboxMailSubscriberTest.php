<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\EventSubscriber\SandboxMailSubscriber;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class SandboxMailSubscriberTest extends TestCase
{
    public function testAMailToMadeUpPlayersOnlyIsNeverSent(): void
    {
        $event = self::event((new Email())->to('test-user+7@myspeedpuzzling.com')->subject('Hi'));

        self::subscriber(true)->dropMadeUpRecipients($event);

        self::assertTrue($event->isRejected());
    }

    public function testMadeUpPlayersAreTakenOffAMailToRealPeopleToo(): void
    {
        $email = (new Email())->to('test-user+7@myspeedpuzzling.com', 'organiser@example.com')->bcc('Test-User+free@MySpeedPuzzling.com');
        $event = self::event($email);

        self::subscriber(true)->dropMadeUpRecipients($event);

        self::assertFalse($event->isRejected());
        self::assertSame(['organiser@example.com'], array_map(static fn (Address $a): string => $a->getAddress(), $email->getTo()));
        self::assertSame([], $email->getBcc());
        self::assertSame(['organiser@example.com'], array_map(static fn (Address $a): string => $a->getAddress(), $event->getEnvelope()->getRecipients()));
    }

    public function testEverySandboxMailIsMarkedAndSentFromTheSandboxDomain(): void
    {
        $email = (new Email())
            ->from(new Address('notify@notify.myspeedpuzzling.com', 'MySpeedPuzzling'))
            ->to('organiser@example.com')
            ->subject('Your sign-in link');
        $email->getHeaders()->addTextHeader('X-SES-MESSAGE-TAGS', 'audit_id=abc, email_type=login_link');
        $event = self::event($email);

        $subscriber = self::subscriber(true);
        $subscriber->markAsSandbox($event);
        $subscriber->markAsSandbox($event);

        self::assertSame('[Sandbox] Your sign-in link', $email->getSubject(), 'Prefixed once');
        self::assertSame('notify@sandbox.myspeedpuzzling.com', $email->getFrom()[0]->getAddress());
        self::assertSame('MySpeedPuzzling', $email->getFrom()[0]->getName());
        self::assertSame('robot@sandbox.myspeedpuzzling.com', $event->getEnvelope()->getSender()->getAddress());
        self::assertSame('audit_id=abc, email_type=login_link, sandbox=1', $email->getHeaders()->get('X-SES-MESSAGE-TAGS')?->getBodyAsString());
    }

    public function testNothingChangesAnywhereElse(): void
    {
        $email = (new Email())->from('notify@notify.myspeedpuzzling.com')->to('test-user+7@myspeedpuzzling.com')->subject('Hi');
        $event = self::event($email);

        $subscriber = self::subscriber(false);
        $subscriber->dropMadeUpRecipients($event);
        $subscriber->markAsSandbox($event);

        self::assertFalse($event->isRejected());
        self::assertSame('Hi', $email->getSubject());
        self::assertSame('notify@notify.myspeedpuzzling.com', $email->getFrom()[0]->getAddress());
        self::assertFalse($email->getHeaders()->has('X-SES-MESSAGE-TAGS'));
    }

    private static function subscriber(bool $sandbox): SandboxMailSubscriber
    {
        return new SandboxMailSubscriber(new SandboxMode($sandbox), new NullLogger());
    }

    private static function event(Email $email): MessageEvent
    {
        $envelope = new Envelope(new Address('robot@mail.myspeedpuzzling.com'), array_merge($email->getTo(), $email->getBcc()));

        return new MessageEvent($email, $envelope, 'ses+https://default');
    }
}

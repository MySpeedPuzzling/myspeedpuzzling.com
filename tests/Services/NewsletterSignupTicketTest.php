<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\NewsletterSignupTicket;
use SpeedPuzzling\Web\Value\NewsletterSignupTicketCheck;
use Symfony\Component\Clock\MockClock;

final class NewsletterSignupTicketTest extends TestCase
{
    public function testTicketIsValidAfterTheMinimumTime(): void
    {
        $clock = new MockClock('2026-10-17 12:00:00');
        $ticket = new NewsletterSignupTicket('secret', $clock);
        $issued = $ticket->issue();

        $clock->sleep(NewsletterSignupTicket::MINIMUM_SECONDS);

        self::assertSame(NewsletterSignupTicketCheck::Valid, $ticket->check($issued));
    }

    public function testTicketSentAtOnceIsTooFast(): void
    {
        $clock = new MockClock('2026-10-17 12:00:00');
        $ticket = new NewsletterSignupTicket('secret', $clock);
        $issued = $ticket->issue();

        $clock->sleep(NewsletterSignupTicket::MINIMUM_SECONDS - 1);

        self::assertSame(NewsletterSignupTicketCheck::TooFast, $ticket->check($issued));
    }

    public function testTicketExpires(): void
    {
        $clock = new MockClock('2026-10-17 12:00:00');
        $ticket = new NewsletterSignupTicket('secret', $clock);
        $issued = $ticket->issue();

        $clock->sleep(NewsletterSignupTicket::LIFETIME_SECONDS + 1);

        self::assertSame(NewsletterSignupTicketCheck::Expired, $ticket->check($issued));
    }

    public function testBackdatedOrForgedTicketIsInvalid(): void
    {
        $clock = new MockClock('2026-10-17 12:00:00');
        $ticket = new NewsletterSignupTicket('secret', $clock);
        [$issuedAt, $signature] = explode('.', $ticket->issue());

        // A bot moving the time back to skip the wait breaks the signature
        self::assertSame(NewsletterSignupTicketCheck::Invalid, $ticket->check(((int) $issuedAt - 60) . '.' . $signature));
        self::assertSame(NewsletterSignupTicketCheck::Invalid, (new NewsletterSignupTicket('other-secret', $clock))->check($issuedAt . '.' . $signature));
        self::assertSame(NewsletterSignupTicketCheck::Invalid, $ticket->check(''));
        self::assertSame(NewsletterSignupTicketCheck::Invalid, $ticket->check('garbage'));
    }
}

<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\ClaimInvitationLocale;
use SpeedPuzzling\Web\Value\ParticipantEmail;

final class ParticipantEmailTest extends TestCase
{
    public function testAnAddressIsReadCanonical(): void
    {
        self::assertSame('jane@example.com', ParticipantEmail::read('  Jane@Example.com '));
    }

    public function testAnEmptyCellIsNoAddress(): void
    {
        self::assertNull(ParticipantEmail::read(null));
        self::assertNull(ParticipantEmail::read('   '));
    }

    public function testSomethingElseIsRefused(): void
    {
        self::assertFalse(ParticipantEmail::read('not an address'));
        self::assertFalse(ParticipantEmail::read('jane@'));
        self::assertFalse(ParticipantEmail::read('jane@example.com, joe@example.com'));
        self::assertFalse(ParticipantEmail::read(str_repeat('a', 250) . '@example.com'));
    }

    public function testTheInvitationsLanguageFollowsTheCountry(): void
    {
        self::assertSame('cs', ClaimInvitationLocale::forCountry('sk'));
        self::assertSame('de', ClaimInvitationLocale::forCountry('AT'));
        self::assertSame('es', ClaimInvitationLocale::forCountry('mx'));
        self::assertSame('fr', ClaimInvitationLocale::forCountry('be'));
        self::assertSame('ja', ClaimInvitationLocale::forCountry('jp'));
        self::assertSame('en', ClaimInvitationLocale::forCountry('us'));
        self::assertSame('en', ClaimInvitationLocale::forCountry(null));
    }
}

<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\MistypedEmailDomain;

final class MistypedEmailDomainTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mistyped(): iterable
    {
        yield 'gmail.coma' => ['someone@gmail.coma', 'someone@gmail.com'];
        yield 'hotmail.con' => ['someone@hotmail.con', 'someone@hotmail.com'];
        yield 'icloud.con' => ['someone@icloud.con', 'someone@icloud.com'];
        yield 'gmail.comm' => ['someone@gmail.comm', 'someone@gmail.com'];
        yield 'gnail.com' => ['someone@gnail.com', 'someone@gmail.com'];
        yield 'hotmatil.com' => ['someone@hotmatil.com', 'someone@hotmail.com'];
        yield 'a letter missing' => ['x@gmal.com', 'x@gmail.com'];
        yield 'two letters swapped' => ['x@gmial.com', 'x@gmail.com'];
        yield 'both typos' => ['x@gnail.con', 'x@gmail.com'];
        yield 'any domain with .con' => ['x@example.con', 'x@example.com'];
        yield 'upper case' => ['Someone@GMAIL.COMA', 'Someone@gmail.com'];
        yield 'seznam' => ['x@sezam.cz', 'x@seznam.cz'];
        yield 'outlook missing a letter' => ['x@outlok.com', 'x@outlook.com'];
    }

    #[DataProvider('mistyped')]
    public function testAMistypedDomainGetsTheCorrectedAddress(string $email, string $suggestion): void
    {
        self::assertSame($suggestion, MistypedEmailDomain::suggestionFor($email));
        self::assertTrue(MistypedEmailDomain::isMistyped($email));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function real(): iterable
    {
        yield 'gmail.com' => ['x@gmail.com'];
        yield 'ymail.com (Yahoo)' => ['x@ymail.com'];
        yield 'mail.com' => ['x@mail.com'];
        yield 'email.com' => ['x@email.com'];
        yield 'email.cz' => ['x@email.cz'];
        yield 'me.com' => ['x@me.com'];
        yield 'mac.com' => ['x@mac.com'];
        yield 'aol.com' => ['x@aol.com'];
        yield 'web.de' => ['x@web.de'];
        yield 'gmx.de' => ['x@gmx.de'];
        yield 'hotmail.de' => ['x@hotmail.de'];
        yield 'hotmail.fr' => ['x@hotmail.fr'];
        yield 'yahoo.co.uk' => ['x@yahoo.co.uk'];
        yield 'yahoo.ca' => ['x@yahoo.ca'];
        yield 'outlook.cz' => ['x@outlook.cz'];
        yield 'seznam.sk' => ['x@seznam.sk'];
        yield 'a company domain' => ['x@assemblepuzzlery.com'];
        yield 'Cameroon' => ['x@example.cm'];
        yield 'myspeedpuzzling' => ['someone@myspeedpuzzling.com'];
        yield 'no domain' => ['x@'];
        yield 'not an address' => ['hello'];
    }

    #[DataProvider('real')]
    public function testARealDomainIsNeverRefused(string $email): void
    {
        self::assertNull(MistypedEmailDomain::suggestionFor($email));
    }
}

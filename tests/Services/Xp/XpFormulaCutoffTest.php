<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Xp;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\Xp\XpFormulaCutoff;
use SpeedPuzzling\Web\Tests\DataFixtures\XpSettingsFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class XpFormulaCutoffTest extends KernelTestCase
{
    public function testReadsTheStoredCutoff(): void
    {
        self::bootKernel();
        $cutoff = self::getContainer()->get(XpFormulaCutoff::class);

        self::assertEquals(XpSettingsFixture::fullFormulaFrom(), $cutoff->fullFormulaFrom());
        self::assertTrue($cutoff->isHistory(new DateTimeImmutable('2026-07-31 23:59:59')));
        self::assertFalse($cutoff->isHistory(new DateTimeImmutable(XpSettingsFixture::FULL_FORMULA_FROM)));
    }
}

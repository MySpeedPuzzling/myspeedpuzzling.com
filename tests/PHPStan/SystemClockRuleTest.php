<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\PHPStan;

use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<SystemClockRule>
 */
final class SystemClockRuleTest extends RuleTestCase
{
    private const string FIXTURE = __DIR__ . '/Fixture/SystemClockReads.php';

    protected function getRule(): Rule
    {
        return new SystemClockRule(self::getContainer()->getByType(ReflectionProvider::class));
    }

    public function testReportsEveryReadOfTheSystemClockAndNothingElse(): void
    {
        $expected = [];

        foreach (file(self::FIXTURE) ?: [] as $index => $line) {
            if (preg_match('~// reported: (.+)$~', rtrim($line), $match) === 1) {
                $expected[] = [sprintf(SystemClockRule::MESSAGE, $match[1]), $index + 1];
            }
        }

        self::assertCount(45, $expected, 'The fixture lost its "// reported:" markers.');

        $this->analyse([self::FIXTURE], $expected);
    }

    public function testAMomentIsRelativeWhenItIsCountedFromNow(): void
    {
        foreach (['now', 'today', 'tomorrow', 'yesterday', 'midnight', 'noon', '+1 day', '-3 days', 'next monday', 'last friday', 'first day of this month', 'last day of next month', '12:00', 'today 08:00', 'october 14', '', ' '] as $moment) {
            self::assertTrue(SystemClockRule::isRelative($moment), $moment);
        }

        foreach (['2026-10-14 12:00:00', '2026-10-14', '2026-10-14T12:00:00+02:00', '@1700000000', 'first monday of october 2026', '14 October 2026 08:00', 'not a date at all'] as $moment) {
            self::assertFalse(SystemClockRule::isRelative($moment), $moment);
        }
    }
}

<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Rfc4122\FieldsInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The browser's id generator (assets/uuid_v7.js - newClientId() of the official results tools, the referees' outbox,
 * the participant phone's events) run under node by tests/uuid-v7-harness.mjs: version 7 and the RFC variant whatever
 * the random bytes, the Unix milliseconds in the first 48 bits, the other 74 bits random, ids sorted by their moment,
 * 100,000 ids in one millisecond all different, never the secure-context-only crypto.randomUUID. Every scenario must
 * report "ok", and the ids it made must read back in PHP as what the server stores: version 7, RFC variant, the moment.
 */
final class UuidV7ScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPassesAndItsIdsReadBackAsVersion7(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/uuid-v7-harness.mjs']);
        $process->mustRun();

        /** @var array{scenarios: array<string, string>, samples: list<array{ms: null|int, id: string}>} $output */
        $output = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(8, count($output['scenarios']));

        foreach ($output['scenarios'] as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }

        self::assertCount(5, $output['samples']);

        foreach ($output['samples'] as $sample) {
            self::assertTrue(Uuid::isValid($sample['id']), $sample['id']);
            $fields = Uuid::fromString($sample['id'])->getFields();
            self::assertInstanceOf(FieldsInterface::class, $fields);
            self::assertSame(7, $fields->getVersion(), $sample['id']);
            self::assertSame(Uuid::RFC_4122, $fields->getVariant(), $sample['id']);

            if ($sample['ms'] !== null) {
                self::assertSame($sample['ms'], (int) hexdec(substr(str_replace('-', '', $sample['id']), 0, 12)), $sample['id']);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddEditions;
use SpeedPuzzling\Web\Message\PublishEventWizardSeries;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Value\NewEdition;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Publish" on the series path's Review (W3): the series and its draft dates - PublishCompetitionSeries alone keeps the
 * editions' own draft flags (P8).
 */
final class PublishEventWizardSeriesHandlerTest extends KernelTestCase
{
    public function testTheSeriesAndItsDraftDatesArePublished(): void
    {
        self::bootKernel();
        $messageBus = self::getContainer()->get(MessageBusInterface::class);
        $database = self::getContainer()->get(Connection::class);
        $series = OrganizationFixture::SERIES_QUIET_PINES_DRAFT;
        self::assertTrue($database->fetchOne('SELECT is_draft FROM competition_series WHERE id = :id', ['id' => $series]));

        $first = Uuid::uuid7();
        $second = Uuid::uuid7();
        $messageBus->dispatch(new AddEditions($series, [
            new NewEdition($first, 'Quiet Pines 1', new DateTimeImmutable('2031-09-06')),
            new NewEdition($second, 'Quiet Pines 2', new DateTimeImmutable('2031-10-04')),
        ], isDraft: true));

        $messageBus->dispatch(new PublishEventWizardSeries($series, notifyAdmin: false));

        self::assertFalse($database->fetchOne('SELECT is_draft FROM competition_series WHERE id = :id', ['id' => $series]));
        self::assertSame(0, (int) self::numeric($database->fetchOne('SELECT COUNT(*) FROM competition WHERE series_id = :id AND is_draft', ['id' => $series])));
        self::assertFalse($database->fetchOne('SELECT is_draft FROM competition WHERE id = :id', ['id' => $first->toString()]));
        self::assertFalse($database->fetchOne('SELECT is_draft FROM competition WHERE id = :id', ['id' => $second->toString()]));
    }

    private static function numeric(mixed $value): int|string
    {
        self::assertTrue(is_int($value) || is_string($value));

        return $value;
    }
}

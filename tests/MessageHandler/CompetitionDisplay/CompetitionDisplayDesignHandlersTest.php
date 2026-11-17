<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\CompetitionDisplay;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionDisplayNotFound;
use SpeedPuzzling\Web\Exceptions\DisplayDesignConflict;
use SpeedPuzzling\Web\Exceptions\InvalidDisplayDesign;
use SpeedPuzzling\Web\Message\AddCompetitionDisplay;
use SpeedPuzzling\Web\Message\SaveCompetitionDisplayDesign;
use SpeedPuzzling\Web\Results\DisplayDesignError;
use SpeedPuzzling\Web\Results\SavedCompetitionDisplayDesign;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayPresets;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\DisplayPreset;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Presets on add and the design save (D-displays-overlays.md §3.3, §6; R §4.2 D2) through the real bus, read back
 * from the table.
 */
final class CompetitionDisplayDesignHandlersTest extends KernelTestCase
{
    private const string EVENT = OfficialResultsFixture::COMPETITION_RESULTS_CUP;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testEveryPresetStartsADisplayWithItsDesignAndDerivedFlags(): void
    {
        $presets = self::getContainer()->get(DisplayPresets::class);

        foreach (DisplayPreset::cases() as $preset) {
            foreach (DisplayKind::cases() as $kind) {
                if (!$preset->fits($kind)) {
                    continue;
                }

                $displayId = $this->add($kind, $preset);
                $row = $this->row($displayId);
                $label = $preset->value . ' / ' . $kind->value;

                self::assertEquals($presets->design($preset, $kind, self::EVENT), json_decode($row['design'], true), $label);
                self::assertSame($preset->showsLiveResults(), $row['uses_live_results'], $label);
                self::assertSame($preset->showsLiveResults(), $row['uses_results'], $label);
                self::assertSame(1, $row['design_version'], $label);
            }
        }
    }

    public function testAPresetOfTheOtherKindIsRefused(): void
    {
        try {
            $this->add(DisplayKind::Screen, DisplayPreset::ObsCornerTimer);
            self::fail('An OBS preset is not for a screen');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
        }

        self::assertFalse($this->database->fetchOne('SELECT id FROM competition_display WHERE competition_id = :id', ['id' => self::EVENT]));
    }

    public function testASaveStoresTheNormalisedDesignAndBumpsTheVersion(): void
    {
        $displayId = $this->add(DisplayKind::Overlay, DisplayPreset::Blank);

        $saved = $this->save($displayId, self::design('#FF0000'), 1);

        self::assertSame(2, $saved->designVersion);
        self::assertTrue($saved->changed);
        $row = $this->row($displayId);
        self::assertSame(2, $row['design_version']);
        self::assertEquals($saved->design, json_decode($row['design'], true));
        self::assertSame('#ff0000', self::at($saved->design, 'theme', 'color'));
    }

    public function testTheSameDesignReadBackFromJsonbIsNoChange(): void
    {
        $displayId = $this->add(DisplayKind::Screen, DisplayPreset::ClassicTimer);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        // jsonb hands the keys back in its own order - still the same design
        $saved = $this->save($displayId, json_decode($this->row($displayId)['design'], false), 1);

        self::assertFalse($saved->changed);
        self::assertSame(1, $saved->designVersion);
    }

    public function testAStaleVersionIsAConflictBeforeAnythingElse(): void
    {
        $displayId = $this->add(DisplayKind::Screen, DisplayPreset::Blank);
        $this->save($displayId, self::design('#00ff00'), 1);

        try {
            // Even an invalid design: the editor first learns that it is out of date
            $this->save($displayId, (object) ['schema' => 7], 1);
            self::fail('A stale version must be refused');
        } catch (DisplayDesignConflict $conflict) {
            self::assertSame(2, $conflict->currentVersion);
        }
    }

    public function testAnInvalidDesignChangesNothing(): void
    {
        $displayId = $this->add(DisplayKind::Screen, DisplayPreset::Blank);
        $before = $this->row($displayId);

        try {
            $this->save($displayId, (object) ['schema' => 1, 'elements' => [(object) ['id' => 'spot0001', 'type' => 'spotlight', 'frame' => new stdClass()]]], 1);
            self::fail('A cut element type must be refused');
        } catch (InvalidDisplayDesign $invalid) {
            self::assertSame([['path' => '/elements/0/type', 'code' => 'unknown_type']], array_map(static fn (DisplayDesignError $error): array => $error->toArray(), $invalid->errors));
        }

        self::assertSame($before, $this->row($displayId));
    }

    public function testAnUnknownDisplayIsNotFound(): void
    {
        $this->expectException(CompetitionDisplayNotFound::class);

        $this->save(Uuid::uuid7()->toString(), self::design('#ffffff'), 1);
    }

    private static function design(string $color): object
    {
        return (object) ['schema' => 1, 'theme' => (object) ['color' => $color]];
    }

    private function save(string $displayId, mixed $design, int $baseVersion, bool $liveConfirmed = false): SavedCompetitionDisplayDesign
    {
        $saved = $this->messageBus
            ->dispatch(new SaveCompetitionDisplayDesign($displayId, $design, $baseVersion, $liveConfirmed))
            ->last(HandledStamp::class)
            ?->getResult();
        assert($saved instanceof SavedCompetitionDisplayDesign);

        return $saved;
    }

    private function add(DisplayKind $kind, DisplayPreset $preset): string
    {
        $displayId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddCompetitionDisplay($displayId, self::EVENT, $kind, 'Hall A', 'en', PlayerFixture::PLAYER_ADMIN, $preset));

        return $displayId->toString();
    }

    /**
     * @return array{design: string, design_version: int, uses_results: bool, uses_live_results: bool}
     */
    private function row(string $displayId): array
    {
        /** @var array{design: string, design_version: int, uses_results: bool, uses_live_results: bool} $row */
        $row = $this->database->fetchAssociative(
            'SELECT design, design_version, uses_results, uses_live_results FROM competition_display WHERE id = :id',
            ['id' => $displayId],
        );

        return $row;
    }

    /**
     * The value at a path of a decoded JSON answer (null when it is not there).
     */
    private static function at(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }
}

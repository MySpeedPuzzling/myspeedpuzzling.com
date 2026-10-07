<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\ParticipantImport;

use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Exceptions\ParticipantFileUnreadable;
use SpeedPuzzling\Web\Exceptions\StashedParticipantImportNotFound;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantFileReader;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportStash;
use SpeedPuzzling\Web\Value\ParticipantFileFormat;
use SpeedPuzzling\Web\Value\ParticipantFileOptions;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ParticipantImportStashTest extends TestCase
{
    private const string EVENT = '018d0004-0000-0000-0000-0000000000aa';
    private const string OTHER_EVENT = '018d0004-0000-0000-0000-0000000000bb';
    private const string PLAYER = '018d0000-0000-0000-0000-000000000001';

    private Filesystem $filesystem;
    private MockClock $clock;
    private ParticipantImportStash $stash;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem(new InMemoryFilesystemAdapter());
        $this->clock = new MockClock(date('Y-m-d H:i:s'));
        $this->stash = $this->newStash();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testAKeptCsvIsDescribedAndRead(): void
    {
        $stashed = $this->stash->keep($this->csv(), self::EVENT, self::PLAYER);

        self::assertNotNull($stashed);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $stashed->token);

        // Another request (another container): nothing but object storage
        $stash = $this->newStash();
        $described = $stash->describe($stashed->token, self::EVENT);

        self::assertNotNull($described);
        self::assertSame('participants.csv', $described->fileName);
        self::assertSame(ParticipantFileFormat::Csv, $described->format);
        self::assertSame(self::PLAYER, $described->uploadedByPlayerId);
        self::assertNull($described->appliedAt);
        self::assertEquals($this->clock->now(), $described->storedAt);
        self::assertEquals(new ParticipantFileOptions('UTF-8', 'semicolon'), $described->detectedOptions);

        $sheets = $stash->sheets($stashed->token, self::EVENT);
        self::assertCount(1, $sheets);
        self::assertSame(3, $sheets[0]->rows);

        $sheet = $stash->sheet($stashed->token, self::EVENT, 0, new ParticipantFileOptions());
        self::assertSame(['name', 'round_names'], $sheet->headers);
        self::assertSame([2 => ['Alex Example', 'Solo, Pair'], 3 => ['Bea Sample', 'Team']], $sheet->rows);

        $overridden = $stash->sheet($stashed->token, self::EVENT, 0, new ParticipantFileOptions(separator: 'comma'));
        // Split by commas, the first row with 2 values is taken for the header
        self::assertSame(['Alex Example;Solo', 'Pair'], $overridden->headers);
    }

    public function testParsedSheetsAreCachedNextToTheFile(): void
    {
        $stashed = $this->stash->keep($this->xlsx(), self::EVENT, self::PLAYER);
        self::assertNotNull($stashed);

        $first = $this->stash->sheet($stashed->token, self::EVENT, 1, new ParticipantFileOptions());
        $sheets = $this->stash->sheets($stashed->token, self::EVENT);

        self::assertSame(['Notes', 'Participants'], array_map(static fn($sheet): string => $sheet->name, $sheets));
        self::assertContains('tmp-imports/' . self::EVENT . '/' . $stashed->token . '.sheets.json', $this->paths());
        self::assertContains('tmp-imports/' . self::EVENT . '/' . $stashed->token . '.sheet-1.auto-auto.json', $this->paths());

        // The file itself is gone: only the caches can answer now
        $this->filesystem->write('tmp-imports/' . self::EVENT . '/' . $stashed->token, 'not a workbook any more');
        $stash = $this->newStash();

        self::assertEquals($first, $stash->sheet($stashed->token, self::EVENT, 1, new ParticipantFileOptions()));
        self::assertEquals($sheets, $stash->sheets($stashed->token, self::EVENT));
        self::assertSame([2 => ['Alex Example', 'Corner Crew'], 3 => ['Bea Sample', 'Edge Lords']], $first->rows);

        // Not cached yet → parsed from the (now broken) file
        $this->expectException(ParticipantFileUnreadable::class);
        $stash->sheet($stashed->token, self::EVENT, 0, new ParticipantFileOptions());
    }

    public function testATokenOnlyWorksForItsEvent(): void
    {
        $stashed = $this->stash->keep($this->csv(), self::EVENT, self::PLAYER);
        self::assertNotNull($stashed);

        self::assertNull($this->stash->describe($stashed->token, self::OTHER_EVENT));

        $this->expectException(StashedParticipantImportNotFound::class);
        $this->stash->sheets($stashed->token, self::OTHER_EVENT);
    }

    public function testAnythingButATokenIsRefused(): void
    {
        $stashed = $this->stash->keep($this->csv(), self::EVENT, self::PLAYER);
        self::assertNotNull($stashed);

        self::assertNull($this->stash->describe('../' . self::EVENT . '/' . $stashed->token, self::EVENT));
        self::assertNull($this->stash->describe(strtoupper($stashed->token), self::EVENT));
        self::assertNull($this->stash->describe($stashed->token, '../' . self::EVENT));

        $this->expectException(StashedParticipantImportNotFound::class);
        $this->stash->sheet('nope', self::EVENT, 0, new ParticipantFileOptions());
    }

    public function testOnlyListsWithAKnownExtensionAreKept(): void
    {
        self::assertNull($this->stash->keep($this->upload("name\nAlex\n", 'participants.pdf'), self::EVENT, self::PLAYER));
        self::assertNull($this->stash->keep($this->csv(), 'not-an-event', self::PLAYER));
    }

    public function testMarkAppliedAndDiscardKeepOnlyTheMeta(): void
    {
        $stashed = $this->stash->keep($this->csv(), self::EVENT, self::PLAYER);
        self::assertNotNull($stashed);
        $this->stash->sheet($stashed->token, self::EVENT, 0, new ParticipantFileOptions());

        $this->clock->modify('+5 minutes');
        $this->stash->markApplied($stashed->token, self::EVENT);
        $this->stash->discard($stashed->token, self::EVENT);

        self::assertSame(['tmp-imports/' . self::EVENT . '/' . $stashed->token . '.json'], $this->paths(), 'No personal data stays');

        $described = $this->stash->describe($stashed->token, self::EVENT);
        self::assertNotNull($described);
        self::assertEquals($this->clock->now(), $described->appliedAt);
        self::assertTrue($described->isApplied());

        $this->expectException(StashedParticipantImportNotFound::class);
        $this->stash->sheets($stashed->token, self::EVENT);
    }

    public function testDiscardRemovesEverythingOfAnImportNotApplied(): void
    {
        $stashed = $this->stash->keep($this->csv(), self::EVENT, self::PLAYER);
        self::assertNotNull($stashed);
        $this->stash->sheets($stashed->token, self::EVENT);
        $this->stash->sheet($stashed->token, self::EVENT, 0, new ParticipantFileOptions());

        $this->stash->discard($stashed->token, self::EVENT);

        self::assertSame([], $this->paths());
        self::assertNull($this->stash->describe($stashed->token, self::EVENT));
    }

    public function testAKeptListExpires(): void
    {
        $stashed = $this->stash->keep($this->csv(), self::EVENT, self::PLAYER);
        self::assertNotNull($stashed);

        $this->clock->modify('+' . (ParticipantImportStash::KEEP_HOURS + 1) . ' hours');

        self::assertNull($this->stash->describe($stashed->token, self::EVENT));
    }

    public function testOnlyAFewListsPerEventAreKept(): void
    {
        $tokens = [];

        for ($i = 0; $i < 4; $i++) {
            $this->clock->modify('+1 second');
            $stashed = $this->stash->keep($this->csv(), self::EVENT, self::PLAYER);
            self::assertNotNull($stashed);
            $tokens[] = $stashed->token;
        }

        self::assertNull($this->stash->describe($tokens[0], self::EVENT), 'The oldest made way');
        self::assertNotNull($this->stash->describe($tokens[1], self::EVENT));
        self::assertNotNull($this->stash->describe($tokens[3], self::EVENT));
        self::assertCount(ParticipantImportStash::MAX_PER_EVENT, array_filter($this->paths(), static fn(string $path): bool => str_ends_with($path, '.json')));

        self::assertNotNull($this->stash->keep($this->csv(), self::OTHER_EVENT, self::PLAYER), 'Another event has its own room');
        self::assertNotNull($this->stash->describe($tokens[1], self::EVENT));
    }

    public function testPruneRemovesWhatNobodyConfirmed(): void
    {
        $stashed = $this->stash->keep($this->csv(), self::EVENT, self::PLAYER);
        self::assertNotNull($stashed);
        $this->stash->sheets($stashed->token, self::EVENT);

        self::assertSame(0, $this->stash->prune());

        $this->clock->modify('+2 days');

        self::assertSame(3, $this->stash->prune());
        self::assertSame([], $this->paths());
    }

    private function newStash(): ParticipantImportStash
    {
        return new ParticipantImportStash($this->filesystem, $this->clock, new NullLogger(), new ParticipantFileReader());
    }

    private function csv(): UploadedFile
    {
        return $this->upload("name;round_names\nAlex Example;Solo, Pair\nBea Sample;Team\n", 'participants.csv');
    }

    private function xlsx(): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle('Notes');
        $spreadsheet->getActiveSheet()->fromArray([['about', 'this file']]);
        $participants = $spreadsheet->createSheet();
        $participants->setTitle('Participants');
        $participants->fromArray([['name', 'team_name'], ['Alex Example', 'Corner Crew'], ['Bea Sample', 'Edge Lords']]);

        $path = $this->file('');
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'participants.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function upload(string $content, string $name): UploadedFile
    {
        return new UploadedFile($this->file($content), $name, 'text/csv', null, true);
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'participant-stash-');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    /**
     * @return list<string>
     */
    private function paths(): array
    {
        $paths = [];

        foreach ($this->filesystem->listContents('tmp-imports', true) as $item) {
            if ($item->isFile()) {
                $paths[] = $item->path();
            }
        }

        sort($paths);

        return $paths;
    }
}

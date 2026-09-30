<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\FirstTry;

use Doctrine\DBAL\Connection;
use Imagick;
use League\Flysystem\Filesystem;
use SpeedPuzzling\Web\Query\GetFirstTryTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The add-time form and the first-try rules (docs/features/first-try-integrity.md): a refused submit
 * explains itself and keeps every field - the photo included.
 *
 * PLAYER_REGULAR already has first tries of PUZZLE_500_01 (TIME_01, TIME_09, ...), and so has PLAYER_WITH_STRIPE.
 */
final class AddTimeFirstTryTest extends WebTestCase
{
    public function testARefusedFirstTryKeepsEveryFieldAndThePhoto(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->submit($browser, $this->fields($browser), groupPlayers: ['#player4'], teamName: 'Tuesday Club', photo: $this->photo());

        $this->assertResponseStatusCodeSame(422);

        // Why, in words
        self::assertStringContainsString('Not saved yet', $crawler->filter('form')->text());
        $notice = $crawler->filter('[data-first-try-check-target="notice"]')->text();
        self::assertStringContainsString('Somebody in your group already has a first try', $notice);
        self::assertStringContainsString('Sarah Williams already has a first try', $notice);

        // Every field as it was
        self::assertSame('1', $crawler->filter('#puzzle_add_form_timeHours')->attr('value'));
        self::assertSame('7', $crawler->filter('#puzzle_add_form_timeMinutes')->attr('value'));
        self::assertSame('12', $crawler->filter('#puzzle_add_form_timeSeconds')->attr('value'));
        self::assertSame('12.07.2026', $crawler->filter('#puzzle_add_form_finishedAt')->attr('value'));
        self::assertSame('Keep me', $crawler->filter('#puzzle_add_form_comment')->text());
        self::assertSame('checked', $crawler->filter('#puzzle_add_form_firstAttempt')->attr('checked'));
        self::assertSame('checked', $crawler->filter('#puzzle_add_form_unboxed')->attr('checked'));
        self::assertSame(PuzzleFixture::PUZZLE_500_01, $crawler->filter('#puzzle_add_form_puzzle')->attr('value'));
        self::assertStringContainsStringIgnoringCase('#player4', implode(' ', $crawler->filter('input[name="group_players[]"]')->each(static fn(Crawler $input): string => (string) $input->attr('value'))));
        self::assertSame('Tuesday Club', $crawler->filter('input[name="team_name"]')->attr('value'));

        // ... and the photo, shown as itself
        $token = $crawler->filter('input[name="photo_stash[finishedPuzzlesPhoto]"]')->attr('value');
        self::assertIsString($token);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        self::assertCount(1, $crawler->filter('img[src="/en/photo-stash/' . $token . '"]'));

        $browser->request('GET', '/en/photo-stash/' . $token);
        $this->assertResponseIsSuccessful();
        self::assertSame('image/jpeg', $browser->getResponse()->headers->get('Content-Type'));
    }

    public function testNobodyElseSeesAKeptPhoto(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->submit($browser, $this->fields($browser), photo: $this->photo());
        $token = (string) $crawler->filter('input[name="photo_stash[finishedPuzzlesPhoto]"]')->attr('value');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/photo-stash/' . $token);
        $this->assertResponseStatusCodeSame(404);

        // Nor can they submit it as theirs: the token does not work under their account
        $crawler = $this->submit($browser, [...$this->fields($browser), 'firstAttempt' => null], photoToken: $token);

        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('could not be kept', $crawler->filter('form')->text());
    }

    public function testMovingTheFirstTryHereSavesWithTheKeptPhoto(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->submit($browser, $this->fields($browser), photo: $this->photo());

        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('You already have a first try', $crawler->filter('[data-first-try-check-target="notice"]')->text());
        $token = (string) $crawler->filter('input[name="photo_stash[finishedPuzzlesPhoto]"]')->attr('value');

        // The player picks "Make this result my first try" and saves again - no file this time, only the token
        $this->submit($browser, $this->fields($browser), photoToken: $token, resolution: 'move');

        $this->assertResponseRedirects();

        $timeId = $this->lastTimeIdOf(PlayerFixture::PLAYER_REGULAR);
        $container = $browser->getContainer();

        self::assertSame(
            [$timeId],
            $container->get(GetFirstTryTimes::class)->markedTimeIdsOf(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_01),
            'The new result is the only first try now',
        );

        $photo = $container->get(Connection::class)->fetchOne('SELECT finished_puzzle_photo FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertIsString($photo);
        self::assertTrue($container->get(Filesystem::class)->fileExists($photo));

        // Saved - the kept photo is gone
        $browser->request('GET', '/en/photo-stash/' . $token);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testANewlyChosenPhotoWinsOverTheKeptOne(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->submit($browser, $this->fields($browser), photo: $this->photo());
        $token = (string) $crawler->filter('input[name="photo_stash[finishedPuzzlesPhoto]"]')->attr('value');

        $this->submit($browser, $this->fields($browser), photo: $this->photo(120, 90), photoToken: $token, resolution: 'move');

        $this->assertResponseRedirects();

        $container = $browser->getContainer();
        $photo = $container->get(Connection::class)->fetchOne('SELECT finished_puzzle_photo FROM puzzle_solving_time WHERE id = :id', ['id' => $this->lastTimeIdOf(PlayerFixture::PLAYER_REGULAR)]);
        self::assertIsString($photo);

        $image = new Imagick();
        $image->readImageBlob($container->get(Filesystem::class)->read($photo));
        self::assertSame(120, $image->getImageWidth(), 'The newly chosen photo is saved, not the kept one');
    }

    public function testATeammatesFirstTryCannotBeMovedAway(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $this->submit($browser, $this->fields($browser), groupPlayers: ['#player4'], resolution: 'move');

        $this->assertResponseStatusCodeSame(422);
    }

    public function testWithoutTheTagNothingIsInTheWay(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $this->submit($browser, [...$this->fields($browser), 'firstAttempt' => null]);

        $this->assertResponseRedirects();
    }

    /**
     * Kept photos live in the in-memory storage of the test kernel - it must survive between requests
     */
    private function browser(): KernelBrowser
    {
        $browser = self::createClient();
        $browser->disableReboot();

        return $browser;
    }

    /**
     * @return array<string, null|string>
     */
    private function fields(KernelBrowser $browser): array
    {
        $crawler = $browser->request('GET', '/en/puzzle-add');
        $this->assertResponseIsSuccessful();

        return [
            '_token' => $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value'),
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'puzzle' => PuzzleFixture::PUZZLE_500_01,
            'timeHours' => '1',
            'timeMinutes' => '7',
            'timeSeconds' => '12',
            'finishedAt' => '12.07.2026',
            'firstAttempt' => '1',
            'unboxed' => '1',
            'comment' => 'Keep me',
            'collection' => '__system_collection__',
        ];
    }

    /**
     * @param array<string, null|string> $fields
     * @param list<string> $groupPlayers
     */
    private function submit(
        KernelBrowser $browser,
        array $fields,
        array $groupPlayers = [],
        string $teamName = '',
        null|UploadedFile $photo = null,
        null|string $photoToken = null,
        string $resolution = '',
    ): Crawler {
        $parameters = [
            'puzzle_add_form' => array_filter($fields, static fn(null|string $value): bool => $value !== null),
            'team_name' => $teamName,
            'first_try_resolution' => $resolution,
        ];

        if ($groupPlayers !== []) {
            $parameters['group_players'] = $groupPlayers;
        }

        if ($photoToken !== null) {
            $parameters['photo_stash'] = ['finishedPuzzlesPhoto' => $photoToken];
        }

        return $browser->request(
            'POST',
            '/en/puzzle-add',
            $parameters,
            $photo !== null ? ['puzzle_add_form' => ['finishedPuzzlesPhoto' => $photo]] : [],
        );
    }

    private function photo(int $width = 64, int $height = 48): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'first-try-photo-');
        assert(is_string($path));

        $image = new Imagick();
        $image->newImage($width, $height, 'orange');
        $image->setImageFormat('jpeg');
        $image->writeImage($path);
        $image->destroy();

        return new UploadedFile($path, 'finished.jpg', 'image/jpeg', null, true);
    }

    private function lastTimeIdOf(string $playerId): string
    {
        $timeId = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT id FROM puzzle_solving_time WHERE player_id = :playerId ORDER BY tracked_at DESC, id DESC LIMIT 1',
            ['playerId' => $playerId],
        );
        assert(is_string($timeId));

        return $timeId;
    }
}

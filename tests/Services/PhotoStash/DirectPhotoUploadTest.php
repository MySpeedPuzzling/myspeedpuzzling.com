<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PhotoStash;

use AsyncAws\S3\S3Client;
use DateTimeImmutable;
use League\Flysystem\Filesystem;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Exceptions\PhotoUploadRefused;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use SpeedPuzzling\Web\Services\OnlineRounds\S3QueryPresigner;
use SpeedPuzzling\Web\Services\PhotoStash\DirectPhotoUpload;
use SpeedPuzzling\Web\Services\PhotoStash\PhotoStash;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\ClockedInMemoryFilesystemAdapter;
use SpeedPuzzling\Web\Tests\TestDouble\ExplodingFilesystemAdapter;
use SpeedPuzzling\Web\Value\PhotoUploadLimits;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * docs/features/photo-uploads/README.md - where the browser uploads a photo straight to object storage.
 */
final class DirectPhotoUploadTest extends KernelTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;
    private const string OTHER_PLAYER = PlayerFixture::PLAYER_WITH_STRIPE;

    private MockClock $clock;
    private Filesystem $filesystem;
    private PhotoStash $stash;
    private DirectPhotoUpload $upload;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->clock = new MockClock('2026-10-18 12:00:00');
        $this->filesystem = new Filesystem(new ClockedInMemoryFilesystemAdapter($this->clock));
        $this->stash = $this->newStash($this->filesystem);
        $this->upload = $this->newUpload($this->stash);
    }

    private function newStash(Filesystem $filesystem): PhotoStash
    {
        return new PhotoStash($filesystem, $this->clock, new NullLogger());
    }

    private function newUpload(PhotoStash $stash): DirectPhotoUpload
    {
        $stores = new OnlineMediaStores(
            primaryClient: new S3Client(['region' => 'fsn1', 'endpoint' => 'https://fsn1.example.com', 'accessKeyId' => 'key', 'accessKeySecret' => 'secret']),
            primaryRegion: 'fsn1',
            primaryBucket: 'puzzle',
            primaryAccessKey: 'key',
            primarySecretKey: 'secret',
            primaryPublicEndpoint: 'https://fsn1.example.com',
            secondaryEndpoint: '',
            secondaryRegion: '',
            secondaryBucket: '',
            secondaryAccessKey: '',
            secondarySecretKey: '',
            secondaryPathStyle: true,
            httpClient: new MockHttpClient(),
            logger: new NullLogger(),
        );

        return new DirectPhotoUpload($stash, $stores, new S3QueryPresigner(), $this->clock);
    }

    public function testThePhotoIsSignedForItsPlaceInThePlayersStash(): void
    {
        $presigned = $this->upload->start(self::PLAYER, 'box.jpg', 'image/jpeg', 812_345);

        self::assertTrue(PhotoStash::isToken($presigned->token));
        self::assertSame(['Content-Type' => 'image/jpeg', 'Content-Length' => '812345'], $presigned->headers);

        $url = parse_url($presigned->url);
        self::assertIsArray($url);
        self::assertSame('fsn1.example.com', $url['host'] ?? null);
        self::assertSame('/puzzle/tmp-uploads/' . self::PLAYER . '/' . $presigned->token, $url['path'] ?? null);

        parse_str($url['query'] ?? '', $query);
        self::assertSame('content-length;content-type;host', $query['X-Amz-SignedHeaders'] ?? null, 'Type and exact length are signed - the store refuses any other upload');
        self::assertSame((string) DirectPhotoUpload::PUT_LIFETIME_SECONDS, $query['X-Amz-Expires'] ?? null);
        self::assertSame((new DateTimeImmutable('2026-10-18 12:00:00')->getTimestamp() + DirectPhotoUpload::PUT_LIFETIME_SECONDS) * 1000, $presigned->expiresAtMs);

        self::assertSame(812_345, $this->stash->reservedBytes($presigned->token, self::PLAYER));
        self::assertNull($this->stash->reservedBytes($presigned->token, self::OTHER_PLAYER));
    }

    public function testARetrySignsTheSamePlaceAgain(): void
    {
        $earlier = [];

        for ($i = 0; $i < 19; $i++) {
            $earlier[] = $this->upload->start(self::PLAYER, 'earlier.jpg', 'image/jpeg', 500)->token;
            $this->clock->modify('+1 second');
        }

        $first = $this->upload->start(self::PLAYER, 'box.jpg', 'image/jpeg', 1000);

        for ($i = 0; $i < 25; $i++) {
            $this->clock->modify('+1 second');
            $retry = $this->upload->start(self::PLAYER, 'box.jpg', 'image/jpeg', 1000, token: $first->token);
            self::assertSame($first->token, $retry->token);
        }

        self::assertSame(1000, $this->stash->reservedBytes($first->token, self::PLAYER));

        foreach ($earlier as $token) {
            self::assertSame(500, $this->stash->reservedBytes($token, self::PLAYER), 'A retry takes no new place - nothing made way');
        }
    }

    public function testReplacingWithItselfOrAnUnknownTokenLetsNothingGo(): void
    {
        $first = $this->upload->start(self::PLAYER, 'box.jpg', 'image/jpeg', 1000);

        $retry = $this->upload->start(self::PLAYER, 'box.jpg', 'image/jpeg', 1000, token: $first->token, replaces: $first->token);
        self::assertSame($first->token, $retry->token);
        self::assertSame(1000, $this->stash->reservedBytes($first->token, self::PLAYER));

        $other = $this->upload->start(self::PLAYER, 'other.jpg', 'image/jpeg', 2000, replaces: str_repeat('f', 32));
        self::assertSame(1000, $this->stash->reservedBytes($first->token, self::PLAYER));
        self::assertSame(2000, $this->stash->reservedBytes($other->token, self::PLAYER));
    }

    public function testAStartedPhotoIsRestoredOnceTheBrowserPutIt(): void
    {
        $presigned = $this->upload->start(self::PLAYER, 'box.jpg', 'image/jpeg', 3);
        self::assertNull($this->stash->restore($presigned->token, self::PLAYER), 'Not uploaded yet');

        $this->filesystem->write($this->stash->objectKey(self::PLAYER, $presigned->token), 'abc');

        $restored = $this->stash->restore($presigned->token, self::PLAYER);
        self::assertNotNull($restored);
        self::assertSame(['box.jpg', 'abc'], [$restored->getClientOriginalName(), (string) file_get_contents($restored->getPathname())]);
        $this->stash->reset();
    }

    public function testWhenNoPlaceCanBeMadeThePhotoGoesWithTheForm(): void
    {
        $upload = $this->newUpload($this->unreservableStash());

        try {
            $upload->start(self::PLAYER, 'box.jpg', 'image/jpeg', 1000);
            self::fail('Expected a refusal');
        } catch (PhotoUploadRefused $refused) {
            self::assertSame('storage', $refused->reason, 'The controller answers 503 - the browser then sends the photo with the form');
        }
    }

    /**
     * A stash that cannot make a place - object storage refusing even the description
     */
    private function unreservableStash(): PhotoStash
    {
        return $this->newStash(new Filesystem(new ExplodingFilesystemAdapter()));
    }

    public function testATokenOfAnotherFileOrPlayerGetsAPlaceOfItsOwn(): void
    {
        $mine = $this->upload->start(self::PLAYER, 'box.jpg', 'image/jpeg', 1000);

        self::assertNotSame($mine->token, $this->upload->start(self::PLAYER, 'box.jpg', 'image/jpeg', 2000, token: $mine->token)->token);
        self::assertNotSame($mine->token, $this->upload->start(self::OTHER_PLAYER, 'box.jpg', 'image/jpeg', 1000, token: $mine->token)->token);
    }

    public function testAnotherPhotoForTheSameFieldLetsTheEarlierOneGo(): void
    {
        $first = $this->upload->start(self::PLAYER, 'box.jpg', 'image/jpeg', 1000);
        $second = $this->upload->start(self::PLAYER, 'other.jpg', 'image/jpeg', 3000, replaces: $first->token);

        self::assertNull($this->stash->reservedBytes($first->token, self::PLAYER));
        self::assertSame(3000, $this->stash->reservedBytes($second->token, self::PLAYER));
    }

    public function testNobodyElsesPhotoCanBeLetGo(): void
    {
        $theirs = $this->upload->start(self::OTHER_PLAYER, 'box.jpg', 'image/jpeg', 1000);
        $this->upload->start(self::PLAYER, 'box.jpg', 'image/jpeg', 1000, replaces: $theirs->token);

        self::assertSame(1000, $this->stash->reservedBytes($theirs->token, self::OTHER_PLAYER));
    }

    public function testWhatTheFormWouldRefuseIsNotStarted(): void
    {
        foreach (
            [
            'too_large' => ['image/jpeg', PhotoUploadLimits::MAX_PHOTO_BYTES + 1],
            'type' => ['application/pdf', 1000],
            'empty' => ['image/jpeg', 0],
            ] as $reason => [$mime, $bytes]
        ) {
            try {
                $this->upload->start(self::PLAYER, 'file', $mime, $bytes);
                self::fail('Expected a refusal: ' . $reason);
            } catch (PhotoUploadRefused $refused) {
                self::assertSame($reason, $refused->reason);
            }
        }

        self::assertSame([], $this->reservations());
    }

    public function testAHeicIsStartedLikeAnyPhoto(): void
    {
        $presigned = $this->upload->start(self::PLAYER, 'IMG_0001.HEIC', 'image/HEIC', 30_000_000);

        self::assertSame('image/heic', $presigned->headers['Content-Type']);
    }

    /**
     * @return list<string>
     */
    private function reservations(): array
    {
        $paths = [];

        foreach ($this->filesystem->listContents('tmp-uploads', true) as $item) {
            if ($item->isFile() && str_ends_with($item->path(), '.json')) {
                $paths[] = $item->path();
            }
        }

        return $paths;
    }
}

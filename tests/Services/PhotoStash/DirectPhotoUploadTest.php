<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PhotoStash;

use AsyncAws\S3\S3Client;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Exceptions\PhotoUploadRefused;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use SpeedPuzzling\Web\Services\OnlineRounds\S3QueryPresigner;
use SpeedPuzzling\Web\Services\PhotoStash\DirectPhotoUpload;
use SpeedPuzzling\Web\Services\PhotoStash\PhotoStash;
use SpeedPuzzling\Web\Value\PhotoUploadLimits;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * docs/features/photo-uploads/README.md - where the browser uploads a photo straight to object storage.
 */
final class DirectPhotoUploadTest extends TestCase
{
    private const string PLAYER = '018d0000-0000-0000-0000-000000000001';
    private const string OTHER_PLAYER = '018d0000-0000-0000-0000-000000000005';

    private Filesystem $filesystem;
    private PhotoStash $stash;
    private DirectPhotoUpload $upload;

    protected function setUp(): void
    {
        $clock = new MockClock('2026-10-18 12:00:00');
        $this->filesystem = new Filesystem(new InMemoryFilesystemAdapter());
        $this->stash = new PhotoStash($this->filesystem, $clock, new NullLogger());

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

        $this->upload = new DirectPhotoUpload($this->stash, $stores, new S3QueryPresigner(), $clock);
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
        self::assertSame((strtotime('2026-10-18 12:00:00') + DirectPhotoUpload::PUT_LIFETIME_SECONDS) * 1000, $presigned->expiresAtMs);

        self::assertSame(812_345, $this->stash->reservedBytes($presigned->token, self::PLAYER));
        self::assertNull($this->stash->reservedBytes($presigned->token, self::OTHER_PLAYER));
    }

    public function testARetrySignsTheSamePlaceAgain(): void
    {
        $first = $this->upload->start(self::PLAYER, 'box.jpg', 'image/jpeg', 1000);
        $retry = $this->upload->start(self::PLAYER, 'box.jpg', 'image/jpeg', 1000, token: $first->token);

        self::assertSame($first->token, $retry->token);
        self::assertCount(1, $this->reservations());
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

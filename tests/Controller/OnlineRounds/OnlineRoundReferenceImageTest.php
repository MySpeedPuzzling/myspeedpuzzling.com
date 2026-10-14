<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use Doctrine\DBAL\Connection;
use League\Flysystem\Filesystem;
use SpeedPuzzling\Web\Message\CheckOnlineFinishPhoto;
use SpeedPuzzling\Web\Message\DeleteOnlineReferenceImage;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineReferenceImages;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ImagesWithMetadata;
use SpeedPuzzling\Web\Tests\OnlineCvTesting;
use SpeedPuzzling\Web\Tests\OnlineReviewSeeding;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\PhotoUploadLimits;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The completeness check's reference image on a round's online timing page (online_round_settings,
 * docs/features/online-competitions/README.md §5.8): the card only while the check is on; an upload validated like the
 * site's photos (types, size), a damaged picture said, a saved one previewed by a short presigned URL (the images proxy
 * never serves `online/`); "Remove"; "Check the finish photos again" once the round has some - one forced
 * CheckOnlineRoundFinishPhotos per click. Organisers only, the forms' CSRF token.
 */
final class OnlineRoundReferenceImageTest extends WebTestCase
{
    use OnlineCvTesting;
    use OnlineReviewSeeding;

    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string SETTINGS = '/en/online-rounds/' . self::ROUND . '/settings';
    private const string REMOVE = '/en/online-rounds/' . self::ROUND . '/settings/reference-image/remove';
    private const string RECHECK = '/en/online-rounds/' . self::ROUND . '/settings/check-finish-photos';
    private const string FIELD = 'online_round_reference_image_form[image]';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        $this->restoreCompletenessCheck();

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testTheCardIsThereOnlyWhileTheCheckIsOn(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN, checkOn: false);

        $crawler = $browser->request('GET', self::SETTINGS);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-online-timing-on]'));
        self::assertCount(0, $crawler->filter('[data-online-reference-image]'));
        self::assertCount(0, $crawler->filter('input[type="file"]'));

        self::ensureKernelShutdown();
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN, checkOn: true);
        $crawler = $browser->request('GET', self::SETTINGS);

        $card = $crawler->filter('[data-online-reference-image]');
        self::assertCount(1, $card);
        self::assertStringContainsString('Reference image for the completeness check', $card->text());
        // Nothing uploaded: the catalogue image is the reference, nothing to preview, remove or check again
        self::assertCount(1, $card->filter('[data-reference-catalogue]'));
        self::assertCount(0, $card->filter('[data-reference-preview], [data-reference-remove], [data-reference-recheck]'));
        $field = $card->filter('input[type="file"][name="' . self::FIELD . '"]');
        self::assertCount(1, $field);
        self::assertStringContainsString('.heic', (string) $field->attr('accept'));
        self::assertStringContainsString('JPG, PNG, WebP or HEIC, up to 60 MB', $card->text());
        self::assertSame('Upload', trim($card->filter('[data-reference-upload] button[type="submit"]')->text()));
    }

    public function testAnUploadIsSavedAndPreviewed(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN, checkOn: true);
        $crawler = $browser->request('GET', self::SETTINGS);

        $this->submitUpload($browser, $crawler, $this->file(ImagesWithMetadata::png(1_600, 1_200), 'png'));

        self::assertResponseRedirects(self::SETTINGS . '#online-reference-image', 303);
        $key = $this->referenceImage($browser);
        self::assertNotNull($key);
        self::assertTrue(OnlineReferenceImages::isObjectKey($key));
        self::assertStringStartsWith("\xFF\xD8\xFF", $this->filesystem($browser)->read($key), 'stored as a JPEG');

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('The reference image is saved', $crawler->filter('.alert-success')->text());
        $card = $crawler->filter('[data-online-reference-image]');
        self::assertCount(0, $card->filter('[data-reference-catalogue]'));
        // A short presigned GET of the object - never the images proxy, which refuses online/
        $src = (string) $card->filter('[data-reference-preview] img')->attr('src');
        self::assertStringContainsString('/' . $key . '?', $src);
        self::assertStringContainsString('X-Amz-Expires=' . OnlineReferenceImages::PREVIEW_URL_SECONDS, $src);
        self::assertStringContainsString('X-Amz-Signature=', $src);
        self::assertCount(1, $card->filter('[data-reference-remove]'));
        self::assertSame('Replace', trim($card->filter('[data-reference-upload] button[type="submit"]')->text()));
    }

    public function testAHeicPictureIsAccepted(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN, checkOn: true);
        $crawler = $browser->request('GET', self::SETTINGS);
        $heic = new \Imagick();
        $heic->newImage(640, 480, 'red');
        $heic->setImageFormat('heic');

        $this->submitUpload($browser, $crawler, $this->file($heic->getImageBlob(), 'heic'));

        self::assertResponseRedirects(self::SETTINGS . '#online-reference-image', 303);
        $key = $this->referenceImage($browser);
        self::assertNotNull($key);
        self::assertStringStartsWith("\xFF\xD8\xFF", $this->filesystem($browser)->read($key));
    }

    public function testWhatIsNoUsablePictureIsRefused(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN, checkOn: true);

        // Not a picture at all
        $crawler = $this->submitUpload($browser, $browser->request('GET', self::SETTINGS), $this->file('name,time' . "\n" . 'Olivia,1:02:03', 'csv'));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Please enter file in valid image format', $this->errorOf($crawler));

        // A picture's first bytes, then nothing a decoder reads
        $crawler = $this->submitUpload($browser, $browser->request('GET', self::SETTINGS), $this->file("\xFF\xD8\xFF\xE0" . str_repeat("\x13\x37", 4_000), 'jpg'));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This picture can\'t be read', $this->errorOf($crawler));

        // Larger than the site's photo limit (a sparse file: nothing written)
        $tooLarge = $this->file('', 'jpg');
        $handle = fopen($tooLarge, 'r+');
        assert($handle !== false);
        ftruncate($handle, PhotoUploadLimits::MAX_PHOTO_BYTES + 1);
        fclose($handle);
        $crawler = $this->submitUpload($browser, $browser->request('GET', self::SETTINGS), $tooLarge);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('too large', $this->errorOf($crawler));

        // Nothing chosen
        $form = $browser->request('GET', self::SETTINGS)->filter('form[data-reference-upload]')->form();
        $crawler = $browser->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choose a picture to upload.', $this->errorOf($crawler));

        self::assertNull($this->referenceImage($browser), 'nothing saved');
        self::assertSame([], $this->storedReferences($browser), 'nothing stored');
    }

    public function testRemovingGoesBackToTheCatalogueImage(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN, checkOn: true);
        $this->submitUpload($browser, $browser->request('GET', self::SETTINGS), $this->file(ImagesWithMetadata::png(800, 600), 'png'));
        $key = $this->referenceImage($browser);
        self::assertNotNull($key);
        $crawler = $browser->followRedirect();

        // An expired page changes nothing
        $browser->request('POST', self::REMOVE, ['_token' => 'forged']);
        self::assertResponseRedirects(self::SETTINGS . '#online-reference-image', 303);
        self::assertSame($key, $this->referenceImage($browser));
        self::assertStringContainsString('The page was open for too long', $browser->followRedirect()->filter('.alert-danger')->text());

        $browser->submit($crawler->filter('form[data-reference-remove]')->form());
        self::assertResponseRedirects(self::SETTINGS . '#online-reference-image', 303);
        self::assertNull($this->referenceImage($browser));
        // The object goes after the commit
        self::assertSame([$key], $this->sentOf($browser, DeleteOnlineReferenceImage::class, static fn (DeleteOnlineReferenceImage $message): string => $message->objectKey));

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('The reference image is removed', $crawler->filter('.alert-success')->text());
        self::assertCount(1, $crawler->filter('[data-reference-catalogue]'));
        self::assertCount(0, $crawler->filter('[data-reference-preview]'));
    }

    public function testTheFinishPhotosAreCheckedAgainWithOneClick(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN, checkOn: true);
        $startMs = $this->startOnlineRound();
        $entry = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $tapId = $this->finishTap($entry, $startMs + 600_000);
        $photos = [
            $this->evidence($entry, OnlineMediaKind::Button, $startMs + 600_300, $tapId),
            $this->evidence($entry, OnlineMediaKind::End, $startMs + 3_000_000),
        ];

        foreach ($photos as $photo) {
            $photo->markVerified($photo->declaredBytes);
        }

        // Not a finish photo, and a finish photo not verified yet: not counted, not sent
        $this->evidence($entry, OnlineMediaKind::Periodic, $startMs + 60_000)->markVerified(35_000);
        $this->evidence($entry, OnlineMediaKind::Candidate, $startMs + 900_000);
        $this->entityManager()->flush();

        $crawler = $browser->request('GET', self::SETTINGS);
        $recheck = $crawler->filter('[data-reference-recheck]');
        self::assertCount(1, $recheck);
        self::assertStringContainsString('The round has 2 finish photos.', $recheck->text());

        // An expired page sends nothing
        $browser->request('POST', self::RECHECK, ['_token' => 'forged']);
        self::assertResponseRedirects(self::SETTINGS . '#online-reference-image', 303);
        self::assertSame([], $this->sentOf($browser, CheckOnlineFinishPhoto::class, static fn (CheckOnlineFinishPhoto $message): string => $message->mediaId));

        $browser->submit($recheck->filter('form')->form());

        self::assertResponseRedirects(self::SETTINGS . '#online-reference-image', 303);
        $sent = $this->sentOf($browser, CheckOnlineFinishPhoto::class, static fn (CheckOnlineFinishPhoto $message): string => $message->mediaId . ($message->force ? ' forced' : ''));
        self::assertEqualsCanonicalizing([$photos[0]->id->toString() . ' forced', $photos[1]->id->toString() . ' forced'], $sent);
        self::assertStringContainsString('2 finish photos are being checked again', $browser->followRedirect()->filter('.alert-success')->text());
    }

    public function testOnlyOrganisersChangeIt(): void
    {
        // A participant, no organiser
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR, checkOn: true);

        $browser->request('POST', self::REMOVE, ['_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(403);

        $browser->request('POST', self::RECHECK, ['_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->sentOf($browser, CheckOnlineFinishPhoto::class, static fn (CheckOnlineFinishPhoto $message): string => $message->mediaId));

        $browser->request('POST', self::SETTINGS, ['online_round_reference_image_form' => ['_token' => 'csrf-token']]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->referenceImage($browser));
    }

    private function signedIn(string $playerId, bool $checkOn): KernelBrowser
    {
        if ($checkOn) {
            $this->turnCompletenessCheckOn();
        }

        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);
        // One container for the whole test: the in-memory store and queue keep what a request left there
        $browser->disableReboot();

        return $browser;
    }

    private function submitUpload(KernelBrowser $browser, Crawler $crawler, string $path): Crawler
    {
        $form = $crawler->filter('form[data-reference-upload]')->form();
        $field = $form[self::FIELD];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($path);

        return $browser->submit($form);
    }

    private function errorOf(Crawler $crawler): string
    {
        return $crawler->filter('[data-online-reference-image] .invalid-feedback')->text();
    }

    private function file(string $content, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'reference_') . '.' . $extension;
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    private function referenceImage(KernelBrowser $browser): null|string
    {
        $key = $browser->getContainer()->get(Connection::class)->fetchOne(
            'SELECT reference_image FROM online_round WHERE round_id = :round',
            ['round' => self::ROUND],
        );
        assert($key === null || is_string($key));

        return $key;
    }

    private function filesystem(KernelBrowser $browser): Filesystem
    {
        return $browser->getContainer()->get(Filesystem::class);
    }

    /**
     * @return list<string>
     */
    private function storedReferences(KernelBrowser $browser): array
    {
        $keys = [];

        foreach ($this->filesystem($browser)->listContents('online/', true) as $item) {
            if ($item->isFile() && OnlineReferenceImages::isObjectKey($item->path())) {
                $keys[] = $item->path();
            }
        }

        return $keys;
    }

    /**
     * What the async queue holds of one message class, each turned into a string
     *
     * @template T of object
     * @param class-string<T> $class
     * @param callable(T): string $describe
     * @return list<string>
     */
    private function sentOf(KernelBrowser $browser, string $class, callable $describe): array
    {
        $transport = $browser->getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $found = [];

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof $class) {
                $found[] = $describe($message);
            }
        }

        return $found;
    }
}

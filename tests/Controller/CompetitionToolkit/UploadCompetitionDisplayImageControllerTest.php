<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use Imagick;
use ImagickPixel;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddCompetitionDisplay;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayDesignSanitizer;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DisplayKind;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The display editor's picture upload (D-displays-overlays.md §8.1 `upload_competition_display_image`, §10.1): a
 * stored JPEG/PNG/WebP/GIF under the event's display prefix with the URL the display loads, refused with JSON reasons -
 * 401/403 for everybody but the event's toolkit managers, 413 over 5 MB, 415 for anything else (SVG included), 429
 * past the hourly budget.
 */
final class UploadCompetitionDisplayImageControllerTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'COMPETITION_TOOLKIT_FOR_ORGANISERS';
    private const string EVENT = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testAnAdminUploadsATransparentPngTheDesignAccepts(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay();

        $response = $this->upload($browser, $displayId, $this->image('png', 'logo.png'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertIsString($response['path']);
        self::assertMatchesRegularExpression('~^competition-displays/' . self::EVENT . '/[0-9a-f-]{36}\.png\z~', $response['path']);
        self::assertIsString($response['url']);
        self::assertStringEndsWith('/preset:display/plain/' . $response['path'], $response['url']);

        $filesystem = self::getContainer()->get(Filesystem::class);
        self::assertTrue($filesystem->fileExists($response['path']));
        $stored = new Imagick();
        $stored->readImageBlob($filesystem->read($response['path']));
        self::assertSame('PNG', $stored->getImageFormat());
        self::assertTrue($stored->getImageAlphaChannel(), 'The transparency stays');

        // The path is what the editor puts into the design - the sanitizer keeps it for this event, no other
        $sanitizer = self::getContainer()->get(DisplayDesignSanitizer::class);
        $design = ['schema' => 1, 'elements' => [['id' => 'image001', 'type' => 'image', 'frame' => [], 'props' => ['path' => $response['path']]]]];
        self::assertSame([$response['path']], DisplayDesignSanitizer::imagePaths($sanitizer->normaliseArray($design, self::EVENT)));
    }

    public function testEveryFormatADisplayShowsIsTaken(): void
    {
        $browser = $this->browser(flag: true, player: self::ORGANISER);
        $displayId = $this->addDisplay();

        foreach (['jpeg' => 'jpg', 'webp' => 'webp', 'gif' => 'gif'] as $format => $extension) {
            // The browser's word for the type and the name do not matter, the content does
            $response = $this->upload($browser, $displayId, $this->image($format, 'picture.bin', 'application/octet-stream'));

            self::assertResponseIsSuccessful($format);
            self::assertIsString($response['path']);
            self::assertStringEndsWith('.' . $extension, $response['path']);
        }
    }

    public function testOtherFilesAreRefusedAsUnsupported(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay();

        $svg = $this->file('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>');
        $response = $this->upload($browser, $displayId, new UploadedFile($svg, 'logo.svg', 'image/svg+xml', test: true));
        self::assertResponseStatusCodeSame(415);
        self::assertSame('unsupported_type', $response['error']);
        self::assertSame('Upload a JPEG, PNG, WebP or GIF picture.', $response['message']);

        $html = $this->file('page.png', '<html><script>alert(1)</script></html>');
        $this->upload($browser, $displayId, new UploadedFile($html, 'page.png', 'image/png', test: true));
        self::assertResponseStatusCodeSame(415);
    }

    public function testAPictureOverFiveMegabytesIsTooLarge(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay();

        $large = $this->file('large.jpg', str_repeat('x', 5 * 1024 * 1024 + 1));
        $response = $this->upload($browser, $displayId, new UploadedFile($large, 'large.jpg', 'image/jpeg', test: true));

        self::assertResponseStatusCodeSame(413);
        self::assertSame('too_large', $response['error']);
        self::assertIsString($response['message']);
    }

    public function testAnUploadWithoutAFileOrWithABrokenPicture(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay();

        $response = $this->upload($browser, $displayId, null);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('no_file', $response['error']);

        // A PNG header with nothing after it
        $broken = $this->file('broken.png', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 64));
        $response = $this->upload($browser, $displayId, new UploadedFile($broken, 'broken.png', 'image/png', test: true));
        self::assertContains($browser->getResponse()->getStatusCode(), [415, 422]);
        self::assertContains($response['error'], ['invalid_image', 'unsupported_type']);
    }

    public function testUploadsAreRateLimitedPerPlayer(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);
        $displayId = $this->addDisplay();

        // The test environment's limiters live on disk: the budget used up here is given back at the end
        $limiter = self::getContainer()->get('limiter.competition_display_image_upload')->create(PlayerFixture::PLAYER_ADMIN);
        $limiter->reset();

        try {
            $limiter->consume($limiter->consume(0)->getRemainingTokens());

            $response = $this->upload($browser, $displayId, $this->image('png', 'logo.png'));

            self::assertResponseStatusCodeSame(429);
            self::assertSame('rate_limited', $response['error']);
            self::assertIsString($response['message']);
        } finally {
            $limiter->reset();
        }
    }

    public function testTheOrganiserUploadsWithTheFlagButNotWithoutIt(): void
    {
        $this->upload($this->browser(flag: true, player: self::ORGANISER), $this->addDisplay(), $this->image('png', 'logo.png'));
        self::assertResponseIsSuccessful();

        self::ensureKernelShutdown();
        $response = $this->upload($this->browser(flag: false, player: self::ORGANISER), $this->addDisplay(), $this->image('png', 'logo.png'));
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'forbidden'], $response);
    }

    public function testAnotherPlayerCannotUploadEvenWithTheFlag(): void
    {
        $response = $this->upload($this->browser(flag: true, player: PlayerFixture::PLAYER_REGULAR), $this->addDisplay(), $this->image('png', 'logo.png'));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'forbidden'], $response);
    }

    public function testSignedOutMissingTokenAndUnknownDisplay(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $browser = self::createClient();
        $displayId = $this->addDisplay();

        $response = $this->upload($browser, $displayId, $this->image('png', 'logo.png'));
        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'sign_in_required'], $response);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('POST', '/en/display-editor/' . $displayId . '/image', files: ['file' => $this->image('png', 'logo.png')], server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('invalid_csrf_token', self::decode($browser)['error']);

        $this->upload($browser, Uuid::uuid7()->toString(), $this->image('png', 'logo.png'));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(KernelBrowser $browser, string $displayId, null|UploadedFile $file): array
    {
        $browser->request(
            'POST',
            '/en/display-editor/' . $displayId . '/image',
            files: $file !== null ? ['file' => $file] : [],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => 'http://localhost',
                'HTTP_X_CSRF_TOKEN' => 'csrf-token',
            ],
        );

        return self::decode($browser);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(KernelBrowser $browser): array
    {
        /** @var array<string, mixed> $response */
        $response = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $response;
    }

    private function image(string $format, string $name, null|string $clientType = null): UploadedFile
    {
        $imagick = new Imagick();
        $imagick->newImage(40, 20, new ImagickPixel('transparent'));
        $imagick->setImageFormat($format);
        $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
        $path = $this->file($name, $imagick->getImagesBlob());
        $imagick->clear();

        return new UploadedFile($path, $name, $clientType ?? 'image/' . $format, test: true);
    }

    private function file(string $name, string $content): string
    {
        $path = sys_get_temp_dir() . '/display-image-' . bin2hex(random_bytes(6)) . '-' . $name;
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function addDisplay(): string
    {
        $displayId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(
            new AddCompetitionDisplay($displayId, self::EVENT, DisplayKind::Screen, 'Hall A', 'en', null),
        );

        return $displayId->toString();
    }

    private function browser(bool $flag, string $player): KernelBrowser
    {
        // Before the kernel boots - the flag is a container parameter
        $this->overrideFeatureFlagEnv(self::FLAG, $flag);

        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $player);

        return $browser;
    }
}

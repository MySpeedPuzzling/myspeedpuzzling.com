<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Imagick;
use SpeedPuzzling\Web\Services\PhotoStash\PhotoStash;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ExifPhotos;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;

/**
 * docs/features/feedback-screenshots.md - screenshots travel as freshly encoded JPEGs attached to the feedback e-mail.
 */
final class FeedbackControllerTest extends WebTestCase
{
    public function testFeedbackWithoutScreenshotsIsSentAsBefore(): void
    {
        $browser = $this->signedInBrowser();

        $this->sendFeedback($browser, 'Just words');

        $this->assertResponseRedirects();
        $email = $this->feedbackEmail();
        self::assertStringContainsString('Just words', (string) $email->getHtmlBody());
        self::assertSame([], $email->getAttachments());
        self::assertCount(1, $email->getReplyTo(), 'A reply goes to the player only');
    }

    public function testABackgroundUploadedScreenshotIsAttachedWithoutItsExif(): void
    {
        $browser = $this->signedInBrowser();
        $token = $this->stashed(ExifPhotos::iphoneJpeg(), 'IMG_0001.jpg');

        $this->sendFeedback($browser, 'Look at this', [$token]);

        $this->assertResponseRedirects();
        $email = $this->feedbackEmail();
        $attachments = $email->getAttachments();
        self::assertCount(1, $attachments);

        $screenshot = $attachments[0];
        self::assertSame('screenshot-1.jpg', $screenshot->getFilename(), 'Our own file name, never the one sent');
        self::assertSame('image/jpeg', $screenshot->getMediaType() . '/' . $screenshot->getMediaSubtype());

        $bytes = $screenshot->getBody();
        self::assertStringStartsWith("\xFF\xD8", $bytes);
        self::assertStringNotContainsString(ExifPhotos::SERIAL_NUMBER, $bytes);
        self::assertStringNotContainsString(ExifPhotos::OWNER, $bytes);

        $imagick = new Imagick();
        $imagick->readImageBlob($bytes);
        self::assertSame([], $imagick->getImageProperties('exif:GPS*'), 'No GPS left in the picture');
        $imagick->clear();

        // Shown in the body
        self::assertStringContainsString('cid:', (string) $email->getHtmlBody());

        // Gone from the stash once in the e-mail
        $stash = self::getContainer()->get(PhotoStash::class);
        self::assertNull($stash->describe($token, PlayerFixture::PLAYER_REGULAR));
    }

    public function testAScreenshotSentAsAFileIsAttached(): void
    {
        $browser = $this->signedInBrowser();
        $path = ExifPhotos::file(ExifPhotos::jpeg(40, 30, null));

        $this->sendFeedback($browser, 'Sent with the form', files: [
            new UploadedFile($path, 'screen.jpg', 'image/jpeg', test: true),
        ]);

        $this->assertResponseRedirects();
        self::assertCount(1, $this->feedbackEmail()->getAttachments());
    }

    public function testWhatIsNoPictureIsLeftOutAndTheMessageStillGoes(): void
    {
        $browser = $this->signedInBrowser();
        $notAPicture = $this->stashed('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'x.png');
        $picture = $this->stashed(ExifPhotos::jpeg(40, 30, null), 'ok.jpg');

        $this->sendFeedback($browser, 'Two pictures', [$notAPicture, $picture]);

        $this->assertResponseRedirects();
        $email = $this->feedbackEmail();
        self::assertCount(1, $email->getAttachments());
        self::assertStringContainsString('1 screenshot(s) could not be read', (string) $email->getHtmlBody());
        self::assertStringContainsString('Two pictures', (string) $email->getHtmlBody());
    }

    public function testAnotherPlayersScreenshotIsNeverAttached(): void
    {
        $browser = $this->signedInBrowser();
        $othersToken = $this->stashed(ExifPhotos::jpeg(40, 30, null), 'theirs.jpg', PlayerFixture::PLAYER_WITH_STRIPE);

        $this->sendFeedback($browser, 'Not mine', [$othersToken]);

        $this->assertResponseRedirects();
        self::assertSame([], $this->feedbackEmail()->getAttachments());

        // Still theirs, untouched
        $stash = self::getContainer()->get(PhotoStash::class);
        self::assertNotNull($stash->describe($othersToken, PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testAtMostThreeScreenshotsAreAttached(): void
    {
        $browser = $this->signedInBrowser();
        $tokens = [];

        for ($i = 0; $i < 5; $i++) {
            $tokens[] = $this->stashed(ExifPhotos::jpeg(40, 30, null), "shot-$i.jpg");
        }

        $this->sendFeedback($browser, 'Many', $tokens);

        $this->assertResponseRedirects();
        self::assertCount(3, $this->feedbackEmail()->getAttachments());
    }

    public function testARefusedFormShowsTheScreenshotsAgain(): void
    {
        $browser = $this->signedInBrowser();
        $token = $this->stashed(ExifPhotos::jpeg(40, 30, null), 'kept.jpg');

        $crawler = $this->sendFeedback($browser, 'Refused', [$token], csrf: false);

        $this->assertResponseStatusCodeSame(422);
        self::assertCount(0, self::getMailerMessages());
        self::assertCount(1, $crawler->filter('input[name="photo_stash[screenshots][]"][value="' . $token . '"]'));
        self::assertCount(1, $crawler->filter('img[src="/en/photo-stash/' . $token . '"]'));
    }

    public function testAnEmptyMessageIsAFormError(): void
    {
        $browser = $this->signedInBrowser();

        $this->sendFeedback($browser, '');

        $this->assertResponseStatusCodeSame(422);
        self::assertCount(0, self::getMailerMessages());
    }

    private function signedInBrowser(): KernelBrowser
    {
        $browser = self::createClient();
        $browser->disableReboot();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        return $browser;
    }

    /**
     * @param list<string> $tokens
     * @param list<UploadedFile> $files
     */
    private function sendFeedback(KernelBrowser $browser, string $message, array $tokens = [], array $files = [], bool $csrf = true): Crawler
    {
        $crawler = $browser->request('GET', '/en/feedback');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="feedback_form"]');
        self::assertCount(1, $form);
        self::assertCount(1, $form->filter('[data-controller="feedback-screenshots"]'));

        $values = $form->form()->getPhpValues();
        $fields = $values['feedback_form'];
        self::assertIsArray($fields);
        $fields['message'] = $message;

        if ($csrf === false) {
            $fields['_token'] = 'wrong';
        }

        $values['feedback_form'] = $fields;

        if ($tokens !== []) {
            $values['photo_stash'] = ['screenshots' => $tokens];
        }

        return $browser->request('POST', '/en/feedback', $values, $files !== [] ? ['feedback_form' => ['screenshots' => $files]] : []);
    }

    private function stashed(string $content, string $name, string $playerId = PlayerFixture::PLAYER_REGULAR): string
    {
        $file = new UploadedFile(ExifPhotos::file($content), $name, null, test: true);
        $kept = self::getContainer()->get(PhotoStash::class)->keep($file, $playerId);
        self::assertNotNull($kept);

        return $kept->token;
    }

    private function feedbackEmail(): Email
    {
        $feedback = array_values(array_filter(
            self::getMailerMessages(),
            static fn(object $message): bool => $message instanceof Email && $message->getSubject() === 'MySpeedPuzzling Feedback',
        ));
        self::assertCount(1, $feedback);

        return $feedback[0];
    }
}

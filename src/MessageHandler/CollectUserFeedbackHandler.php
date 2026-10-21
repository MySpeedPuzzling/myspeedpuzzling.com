<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\CollectUserFeedback;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\PhotoStash\PhotoStash;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\FeedbackScreenshotLimits;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Screenshots (docs/features/feedback-screenshots.md) are read from the player's PhotoStash and attached as freshly
 * encoded JPEGs: decoding is the real type check, and the new file carries nothing of the original - no EXIF (a phone
 * photo's GPS), no other frames, nothing hidden behind the picture. The bytes travel inside the queued e-mail, never a
 * path (the worker sending it has no such file). One that cannot be read is left out and the e-mail says so - the
 * message itself always goes.
 */
#[AsMessageHandler]
readonly final class CollectUserFeedbackHandler
{
    public function __construct(
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private MailerInterface $mailer,
        private PhotoStash $photoStash,
        private ImageOptimizer $imageOptimizer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CollectUserFeedback $message): void
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        $userEmail = $profile->email ?? 'anonymous@speedpuzzling.cz';

        $screenshots = [];
        $lost = $message->lostScreenshots;

        foreach (array_slice($message->screenshotTokens, 0, FeedbackScreenshotLimits::MAX_SCREENSHOTS) as $token) {
            $jpeg = $profile !== null ? $this->screenshot($token, $profile->playerId) : null;

            if ($jpeg === null) {
                $lost++;
                continue;
            }

            $screenshots[] = new DataPart($jpeg, 'screenshot-' . (count($screenshots) + 1) . '.jpg', 'image/jpeg');
        }

        $email = (new TemplatedEmail())
            ->to('simona@speedpuzzling.cz')
            ->replyTo($userEmail)
            ->subject('MySpeedPuzzling Feedback')
            ->htmlTemplate('emails/feedback.html.twig')
            ->context([
                'name' => $profile->playerName ?? $profile->playerId ?? 'Anonymous',
                'userEmail' => $userEmail,
                'url' => $message->url,
                'message' => $message->message,
                'screenshots' => array_map(static fn(DataPart $part): string => (string) $part->getFilename(), $screenshots),
                'lostScreenshots' => $lost,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        foreach ($screenshots as $screenshot) {
            // Inline = shown in the body (`cid:<file name>` in the template), still saveable like any attachment
            $email->addPart($screenshot->asInline());
        }

        $this->mailer->send($email);

        if ($profile !== null) {
            foreach ($message->screenshotTokens as $token) {
                $this->photoStash->discard($token, $profile->playerId);
            }
        }
    }

    private function screenshot(string $token, string $playerId): null|string
    {
        $file = $this->photoStash->restore($token, $playerId);

        if ($file === null) {
            return null;
        }

        try {
            return $this->imageOptimizer->toJpeg(
                $file->getPathname(),
                FeedbackScreenshotLimits::MAX_DIMENSION,
                FeedbackScreenshotLimits::JPEG_QUALITY,
            );
        } catch (\Throwable $e) {
            // Not an image Imagick reads - the player sent something else, nothing for a person to look at
            $this->logger->info('A feedback screenshot could not be read', [
                'exception' => $e,
            ]);

            return null;
        }
    }
}

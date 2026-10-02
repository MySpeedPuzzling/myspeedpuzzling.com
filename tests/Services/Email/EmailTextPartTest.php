<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Email;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Dom\HTMLDocument;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\RequestSignInLink;
use SpeedPuzzling\Web\Message\SendEmailVerificationLink;
use SpeedPuzzling\Web\Message\SendPasswordResetLink;
use SpeedPuzzling\Web\Message\SendResultReviewEmailPreview;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\PasswordResetToken;
use SpeedPuzzling\Web\Value\ResultReviewEmailPreviewVariant;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The text part of real e-mails, sent through the real handlers and the real mailer (so the converter wiring,
 * `twig.mailer.html_to_text_converter`, is proven too): every link of the HTML is in the text with its full URL,
 * paragraphs are separated, nothing of <head>, <style> or the hidden preheader leaks in.
 *
 * Asserts URLs and structure, never the full wording - the texts are free to change.
 */
final class EmailTextPartTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
    }

    public function testSignInLinkTextHasTheCodeAndTheWholeLink(): void
    {
        $this->messageBus->dispatch(new RequestSignInLink(PlayerFixture::PLAYER_REGULAR_EMAIL, 'en'));

        $email = $this->sentEmail();
        $text = $this->assertReadableTextPart($email);

        $signInUrl = self::buttonHref($email);
        self::assertStringContainsString('/login-link/check?', $signInUrl);
        self::assertStringContainsString('&', $signInUrl, 'A URL with several query parameters');
        self::assertStringContainsString($signInUrl, $text, 'The sign-in link with its whole query string');

        // The code is in the subject too
        self::assertSame(1, preg_match('/\b(\d{6})\b/', (string) $email->getSubject(), $matches));
        self::assertMatchesRegularExpression('/(^|\n)' . $matches[1] . '\n/', $text, 'The code on a line of its own');
    }

    public function testPasswordResetTextHasTheResetLink(): void
    {
        $token = PasswordResetToken::generate();
        $this->messageBus->dispatch(new SendPasswordResetLink(PlayerFixture::PLAYER_REGULAR_EMAIL, $token->toString(), 'en'));

        $email = $this->sentEmail();
        $text = $this->assertReadableTextPart($email);

        $resetUrl = self::buttonHref($email);
        self::assertStringContainsString($token->toString(), $resetUrl);
        self::assertStringContainsString($resetUrl, $text);
    }

    public function testVerifyEmailTextHasTheVerificationLink(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new UserAccount(Uuid::uuid7(), 'msp|text-part-verify', 'text.part.verify@example.test', new DateTimeImmutable()));
        $entityManager->flush();

        $this->messageBus->dispatch(new SendEmailVerificationLink('msp|text-part-verify', 'de'));

        $email = $this->sentEmail();
        $text = $this->assertReadableTextPart($email);

        self::assertStringContainsString(self::buttonHref($email), $text);
    }

    public function testResultReviewTextKeepsLinksListAndSignatureLines(): void
    {
        $this->messageBus->dispatch(new SendResultReviewEmailPreview('text.part.review@example.test', 'cs', ResultReviewEmailPreviewVariant::First));

        $email = $this->sentEmail();
        $text = $this->assertReadableTextPart($email);

        self::assertMatchesRegularExpression('~https?://[^/\s]+/cs/review-results\?from=rc-[0-9a-f-]+~', $text);
        self::assertStringContainsString('/result-emails/unsubscribe/', $text);
        self::assertMatchesRegularExpression('/^- \S/m', $text, 'The listed results are list items');

        // "…happy puzzling!" and "Your MySpeedPuzzling team" are split by a <br> - two lines, not glued together
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertStringContainsString(
            $translator->trans('result_review.thanks', domain: 'emails', locale: 'cs') . "\n" . $translator->trans('result_review.signature', domain: 'emails', locale: 'cs'),
            $text,
        );
    }

    private function sentEmail(): TemplatedEmail
    {
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $email);

        return $email;
    }

    /**
     * What every e-mail's text part must hold, whatever the template: each link of the HTML (the footer's homepage
     * link and e-mail address included) with its full target, paragraphs, no markup and nothing hidden.
     */
    private function assertReadableTextPart(TemplatedEmail $email): string
    {
        $html = $email->getHtmlBody();
        self::assertIsString($html);
        $text = $email->getTextBody();
        self::assertIsString($text);
        self::assertNotSame('', trim($text));

        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $hrefs = [];

        foreach ($document->getElementsByTagName('a') as $link) {
            $hrefs[] = (string) $link->getAttribute('href');
        }

        self::assertNotSame([], $hrefs);

        foreach (array_unique($hrefs) as $href) {
            $target = preg_replace('~^mailto:([^?]*).*$~i', '$1', $href);
            self::assertIsString($target);
            self::assertStringContainsString($target, $text, 'Every link of the e-mail is readable in its text part');
        }

        self::assertDoesNotMatchRegularExpression('~<[a-z/!]~i', $text, 'No markup');
        self::assertStringNotContainsString('@media', $text, 'Nothing of <head> or <style>');
        self::assertDoesNotMatchRegularExpression('/[\x{00A0}\x{034F}\x{200B}-\x{200D}\x{FEFF}]/u', $text, 'No invisible characters');

        self::assertStringContainsString("\n\n", $text, 'Paragraphs are separated');
        self::assertStringNotContainsString("\n\n\n", $text, 'Never more than one blank line');

        // The hidden preview line - unless the very same words are also in the visible body
        $preheaderTexts = [];

        foreach ($document->querySelectorAll('.preheader') as $preheader) {
            $preheaderTexts[] = self::normalize((string) $preheader->textContent);
            $preheader->remove();
        }

        $visibleText = self::normalize((string) $document->body?->textContent);

        foreach ($preheaderTexts as $preheaderText) {
            if ($preheaderText !== '' && !str_contains($visibleText, $preheaderText)) {
                self::assertStringNotContainsString($preheaderText, $text, 'The hidden preheader is not part of the text');
            }
        }

        return $text;
    }

    /**
     * The target of the e-mail's first button (Inky's table.button) - the rendered e-mail no longer has its context.
     */
    private static function buttonHref(TemplatedEmail $email): string
    {
        $document = HTMLDocument::createFromString((string) $email->getHtmlBody(), LIBXML_NOERROR);
        $href = $document->querySelector('table.button a')?->getAttribute('href');
        self::assertIsString($href);
        self::assertStringStartsWith('http', $href);

        return $href;
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}\x{034F}\x{200B}-\x{200D}\x{FEFF}]+/u', ' ', $text));
    }
}

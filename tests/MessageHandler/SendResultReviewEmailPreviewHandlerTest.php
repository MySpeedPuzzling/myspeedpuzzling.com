<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Message\SendResultReviewEmailPreview;
use SpeedPuzzling\Web\MessageHandler\SendResultReviewEmailPreviewHandler;
use SpeedPuzzling\Web\Value\ResultReviewEmailPreviewVariant;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The preview of the "Your results" e-mail (docs/features/duplicate-results.md, "Sending"): the real template,
 * translations and headers with sample data, "[Preview]" in the subject, nothing written.
 */
final class SendResultReviewEmailPreviewHandlerTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{ResultReviewEmailPreviewVariant, string}>
     */
    public static function provideVariants(): iterable
    {
        foreach (ResultReviewEmailPreviewVariant::cases() as $variant) {
            foreach (['en', 'cs'] as $locale) {
                yield $variant->value . ' ' . $locale => [$variant, $locale];
            }
        }
    }

    #[DataProvider('provideVariants')]
    public function testEveryVariantRendersInTheRealTemplate(ResultReviewEmailPreviewVariant $variant, string $locale): void
    {
        self::bootKernel();

        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(
            new SendResultReviewEmailPreview('owner@example.com', $locale, $variant),
        );

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('owner@example.com', $email->getTo()[0]->getAddress());

        $subjectKey = match ($variant) {
            ResultReviewEmailPreviewVariant::First => 'result_review.subject_first',
            ResultReviewEmailPreviewVariant::Weekly => 'result_review.subject_weekly',
            ResultReviewEmailPreviewVariant::Removed => 'result_review.subject_removed',
        };
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertSame('[Preview] ' . $translator->trans($subjectKey, domain: 'emails', locale: $locale), $email->getSubject());

        // The real e-mail's headers: notifications transport, one-click unsubscribe for an id that belongs to nobody
        self::assertSame('notifications', $email->getHeaders()->get('X-Transport')?->getBodyAsString());
        self::assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
        self::assertMatchesRegularExpression(
            '~^<https?://[^/]+/' . $locale . '/result-emails/unsubscribe/' . SendResultReviewEmailPreviewHandler::PREVIEW_PLAYER_ID . '\?_hash=[^>]+>$~',
            (string) $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString(),
        );

        $html = (string) $email->getHtmlBody();
        self::assertMatchesRegularExpression('~href="https?://[^/"]+/' . $locale . '/review-results\?from=rc-' . SendResultReviewEmailPreviewHandler::PREVIEW_CONTACT_ID . '"~', $html);
        self::assertStringContainsString('Disney Family – 01:25:04', $html);
        self::assertStringContainsString($translator->trans('result_review.removals_heading', domain: 'emails', locale: $locale), $html);

        if ($variant === ResultReviewEmailPreviewVariant::Removed) {
            self::assertStringNotContainsString('Circle of Colors', $html);
        } else {
            self::assertStringContainsString('Circle of Colors: Tropical – 01:10:26', $html);
            self::assertStringContainsString('Songs of Extinct Birds – 01:22:08', $html);
            self::assertStringContainsString($translator->trans('result_review.kind_teammate_copy', domain: 'emails', locale: $locale), $html);
            // Four results, three listed
            self::assertStringNotContainsString('Wildlife Collage', $html);
            self::assertStringContainsString($translator->trans('result_review.more', ['%count%' => 1], domain: 'emails', locale: $locale), $html);
        }

        /** @var list<array{sql: string}> $executed */
        $executed = $debugDataHolder->getData()['default'] ?? [];
        $writes = array_filter(
            array_column($executed, 'sql'),
            static fn (string $sql): bool => preg_match('~^\s*(INSERT|UPDATE|DELETE)~i', $sql) === 1,
        );
        self::assertSame([], array_values($writes), 'The preview writes nothing');
    }
}

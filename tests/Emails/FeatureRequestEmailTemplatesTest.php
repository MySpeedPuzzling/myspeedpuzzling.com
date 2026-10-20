<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Emails;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Every feature request e-mail renders in every locale with its own small print: why the person gets it, the e-mail
 * settings and the one-click unsubscribe (docs/features/feature-requests.md).
 */
final class FeatureRequestEmailTemplatesTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function templates(): iterable
    {
        foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            foreach (['completed', 'declined'] as $status) {
                yield "$status author $locale" => ["emails/feature_request_$status.html.twig", 'author', $locale];
                yield "$status upvoter $locale" => ["emails/feature_request_{$status}_upvoter.html.twig", 'upvoter', $locale];
                yield "$status follower $locale" => ["emails/feature_request_{$status}_follower.html.twig", 'follower', $locale];
            }
        }
    }

    #[DataProvider('templates')]
    public function testRendersWithItsSmallPrint(string $template, string $reason, string $locale): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $container->get(TranslatorInterface::class)->setLocale($locale);

        $html = $container->get(Environment::class)->render($template, [
            'featureRequestId' => '018d0010-0000-0000-0000-000000000001',
            'featureRequestTitle' => 'Percentage of rank',
            'githubUrl' => 'https://github.com/example/repo/pull/1',
            'adminComment' => 'Shipped.',
            'optOutKey' => 'feature_request_emails.opt_out.' . $reason,
            'settingsUrl' => 'https://example.com/settings-link',
            'unsubscribeUrl' => 'https://example.com/unsubscribe-link',
        ]);

        self::assertStringContainsString('Percentage of rank', $html);
        self::assertStringContainsString('https://example.com/settings-link', $html);
        self::assertStringContainsString('https://example.com/unsubscribe-link', $html);
        self::assertStringNotContainsString('feature_request_emails.opt_out', $html, 'Untranslated key');
        self::assertStringNotContainsString('.follower.', $html, 'Untranslated key');
    }
}

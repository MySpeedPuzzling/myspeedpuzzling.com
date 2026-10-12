<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Translation hygiene of the navigation (docs/features/navigation F-05): no navigation label is hard-coded English
 * ("Marketplace", "Messages" read the page keys), the Czech events label says "Události", and the "New" badge has one
 * key and one spelling - `new_badge` ("New"); the second key `menu.new_badge` ("NEW") is gone from every catalogue.
 */
final class NavigationLabelHygieneTest extends KernelTestCase
{
    private const array LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];
    private const string TEMPLATES = __DIR__ . '/../../templates';

    // A label hard-coded as element text, aria-label or title
    private const string HARD_CODED_LABEL = '/(>\s*(Marketplace|Messages)\s*<)|((aria-label|title)="\s*(Marketplace|Messages)\s*")/';

    public function testNavigationPartialsHaveNoHardCodedMarketplaceOrMessagesLabel(): void
    {
        foreach ($this->navigationPartials() as $name => $source) {
            self::assertDoesNotMatchRegularExpression(self::HARD_CODED_LABEL, $source, sprintf('%s: a hard-coded "Marketplace"/"Messages" label - use marketplace.title / messaging.title', $name));
        }
    }

    public function testTheLabelsComeFromThePageKeys(): void
    {
        $sources = $this->navigationPartials();

        self::assertStringContainsString("'marketplace.title'|trans", $sources['_footer.html.twig']);
        self::assertStringContainsString("'marketplace.title'|trans", $sources['base.html.twig (header)']);
        self::assertSame(2, substr_count($sources['messaging/_unread_badge.html.twig'], "'messaging.title'|trans"));

        // The registry names the same keys (F-01 entries marketplace and messages)
        $registry = self::getContainer()->get(NavigationRegistry::class);
        self::assertSame('marketplace.title', $registry->byId('marketplace')?->label);
        self::assertSame('messaging.title', $registry->byId('messages')?->label);
    }

    public function testCzechEventsLabelInTheNavigation(): void
    {
        $translator = $this->translator();

        self::assertTrue($translator->getCatalogue('cs')->defines('menu.events'));
        self::assertSame('Události', $translator->trans('menu.events', [], null, 'cs'));
    }

    public function testOneNewBadgeKeyAndSpelling(): void
    {
        $translator = $this->translator();

        foreach (self::LOCALES as $locale) {
            $catalogue = $translator->getCatalogue($locale);
            self::assertFalse($catalogue->defines('menu.new_badge'), sprintf('%s: menu.new_badge must be gone - the badge key is new_badge', $locale));
            self::assertTrue($catalogue->defines('new_badge'), sprintf('%s: new_badge missing', $locale));
            self::assertNotSame('', trim($catalogue->get('new_badge')), $locale);
        }

        self::assertSame('New', $translator->trans('new_badge', [], null, 'en'));

        $keys = [];
        foreach ((new Finder())->files()->in([self::TEMPLATES, __DIR__ . '/../../src', __DIR__ . '/../../assets'])->name(['*.twig', '*.php', '*.js']) as $file) {
            $source = $file->getContents();
            self::assertStringNotContainsString('menu.new_badge', $source, $file->getRelativePathname());

            if (preg_match_all("/'([a-z0-9_.]*new_badge)'\s*\|\s*trans/", $source, $matches) > 0) {
                foreach ($matches[1] as $key) {
                    $keys[$key] = true;
                }
            }
        }

        self::assertSame(['new_badge'], array_keys($keys), 'Every "New" badge reads the one key new_badge');
    }

    /**
     * The navigation partials: footer, the header block of base, the messages tool, templates/inbox (the Inbox link, badge
     * and stream), templates/navigation.
     *
     * @return array<string, string>
     */
    private function navigationPartials(): array
    {
        $base = (string) file_get_contents(self::TEMPLATES . '/base.html.twig');
        $start = strpos($base, '{% block header %}');
        $end = strpos($base, '<main');
        self::assertIsInt($start);
        self::assertIsInt($end);
        self::assertGreaterThan($start, $end);

        $sources = [
            '_footer.html.twig' => (string) file_get_contents(self::TEMPLATES . '/_footer.html.twig'),
            'base.html.twig (header)' => substr($base, $start, $end - $start),
        ];

        foreach ((new Finder())->files()->in(self::TEMPLATES . '/messaging')->name('_unread_badge*.twig') as $file) {
            $sources['messaging/' . $file->getFilename()] = $file->getContents();
        }

        // The Inbox link, badge and their realtime stream (INB-02 replaced the messages stream)
        foreach ((new Finder())->files()->in(self::TEMPLATES . '/inbox')->name('*.twig') as $file) {
            $sources['inbox/' . $file->getRelativePathname()] = $file->getContents();
        }

        foreach ((new Finder())->files()->in(self::TEMPLATES . '/navigation')->name('*.twig') as $file) {
            $sources['navigation/' . $file->getRelativePathname()] = $file->getContents();
        }

        self::assertArrayHasKey('messaging/_unread_badge.html.twig', $sources);
        self::assertArrayHasKey('inbox/_badge_stream.html.twig', $sources);
        self::assertArrayHasKey('inbox/_link.html.twig', $sources);

        return $sources;
    }

    private function translator(): TranslatorInterface&TranslatorBagInterface
    {
        return self::getContainer()->get(TranslatorInterface::class);
    }
}

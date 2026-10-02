<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use LogicException;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * A translation for JavaScript that fills in a changing count itself (assets/translation_choice.js): the raw
 * message with all its forms, plus the locale of the catalogue it came from - a key translated only in English
 * picks its form by English rules on a Czech page, exactly as Symfony's Translator does on fallback.
 */
final class BrowserTranslationTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('browser_translation', $this->browserTranslation(...)),
        ];
    }

    /**
     * @return array{message: string, locale: string}
     */
    public function browserTranslation(string $id, string $domain = 'messages', null|string $locale = null): array
    {
        if (!$this->translator instanceof TranslatorBagInterface) {
            throw new LogicException('The translator does not expose its catalogues.');
        }

        $catalogue = $this->translator->getCatalogue($locale);

        while (!$catalogue->defines($id, $domain) && $catalogue->getFallbackCatalogue() !== null) {
            $catalogue = $catalogue->getFallbackCatalogue();
        }

        return [
            'message' => $catalogue->get($id, $domain),
            'locale' => $catalogue->getLocale(),
        ];
    }
}

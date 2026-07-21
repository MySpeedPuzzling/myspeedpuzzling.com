<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Guides hub index. Guides are English-only by design: a single /en/ URL,
 * no localized route variants (head handling is overridden in the guides
 * base template accordingly).
 *
 * Why English-only, not an oversight: search authority is scarce for a growing
 * site, so each guide concentrates every backlink and ranking signal on one
 * strong URL aimed at the high-volume English term "speed puzzling", rather
 * than splitting it across six weaker localized URLs. The guide body copy is
 * likewise hardcoded English prose in templates/guides/*.twig, and the
 * `guides.*` translation keys exist only in messages.en.yml.
 *
 * Consequences to respect:
 *  - Do NOT fill `guides.*` keys in other locale files — the routes pin
 *    `_locale => 'en'` and the controllers pin `trans(locale: 'en')`, so a
 *    translated value can never render. tools/check-translations.php therefore
 *    ignores `messages.guides.*` (see its $ignoredKeyPrefixes) so it stops
 *    reporting a false gap.
 *  - The "(in English)" disclaimers next to guide links in other locales
 *    (de/es/fr/ja) are accurate — leave them until the guides are localized.
 *
 * Translating the guides is a deliberate future project (extract the prose,
 * drop the locale pins, add hreflang, translate the winners once they rank),
 * not a gap to be filled piecemeal.
 */
final class GuidesController extends AbstractController
{
    public function __construct(
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/en/guides', name: 'guides', defaults: ['_locale' => 'en'])]
    public function __invoke(): Response
    {
        return $this->render('guides/index.html.twig', [
            'guide_title' => $this->translator->trans('guides.index.title', locale: 'en'),
            'guide_description' => $this->translator->trans('guides.index.meta_description', locale: 'en'),
        ]);
    }
}

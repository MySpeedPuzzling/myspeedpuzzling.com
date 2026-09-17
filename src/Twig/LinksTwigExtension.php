<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\GenerateFacebookLink;
use SpeedPuzzling\Web\Services\GenerateInstagramLink;
use SpeedPuzzling\Web\Services\GenerateTwitchLink;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;

final class LinksTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private GenerateInstagramLink $generateInstagramLink,
        readonly private GenerateFacebookLink $generateFacebookLink,
        readonly private GenerateTwitchLink $generateTwitchLink,
    ) {
    }

    /**
     * @return array<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('instagram', [$this, 'generateInstagramLink']),
            new TwigFilter('facebook', [$this, 'generateFacebookLink']),
            new TwigFilter('twitch', [$this, 'generateTwitchLink']),
        ];
    }

    public function generateInstagramLink(string $input): Markup
    {
        $link = $this->generateInstagramLink->fromUserInput($input);

        return $this->externalAnchor($link->link, $link->text);
    }

    public function generateFacebookLink(string $input): Markup|string
    {
        $link = $this->generateFacebookLink->fromUserInput($input);

        if ($link->link === null) {
            return $link->text;
        }

        return $this->externalAnchor($link->link, $link->text);
    }

    public function generateTwitchLink(string $input): Markup
    {
        $link = $this->generateTwitchLink->fromUserInput($input);

        return $this->externalAnchor($link->link, $link->text);
    }

    /**
     * Both values come straight from what a player typed into their profile, so both are escaped - an apostrophe
     * in a name used to end the href attribute early. The icon marks a link that leaves MySpeedPuzzling.
     */
    private function externalAnchor(null|string $href, string $text): Markup
    {
        return new Markup(sprintf(
            '<a target="_blank" rel="noopener nofollow" href="%s">%s<i class="bi bi-box-arrow-up-right ms-1 small" aria-hidden="true"></i></a>',
            htmlspecialchars((string) $href, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
        ), 'UTF-8');
    }
}

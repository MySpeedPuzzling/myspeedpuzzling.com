<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\PlatformDetector;
use SpeedPuzzling\Web\Value\PostAction;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The one-tap actions of the browse pages as links (docs/features/navigation GEST-15, decision 99; PostAction):
 *
 *   <a {{ post_action_attributes('wishlist_remove', {puzzleId: id}, {context: 'detail'}) }} class="…">…</a>
 *
 * renders the link's href - the confirmation view `post_action_confirm` with the values, and `back` (the page it was
 * on) - and what post_action_controller.js needs to post it in one tap: the route's URL, the fields, the CSRF token, the
 * text of a failure. The action that asks first (Block) opens the view in the modal frame instead (`_top` inside
 * the app) and carries no controller. Values of the wrong shape never reach a link (PostAction validates them - a
 * template passing one fails loudly).
 */
final class PostActionTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private UrlGeneratorInterface $urlGenerator,
        readonly private CsrfTokenManagerInterface $csrfTokenManager,
        readonly private RequestStack $requestStack,
        readonly private TranslatorInterface $translator,
        readonly private PlatformDetector $platformDetector,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('post_action_url', $this->confirmUrl(...)),
            new TwigFunction('post_action_attributes', $this->attributes(...), ['is_safe' => ['html']]),
            new TwigFunction('post_action_data', $this->dataAttributes(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param array<string, string> $routeParameters
     * @param array<string, string> $fields
     */
    public function confirmUrl(string $action, array $routeParameters = [], array $fields = []): string
    {
        $postAction = PostAction::from($action);
        $this->validate($postAction, $routeParameters, $fields);

        $query = ['action' => $postAction->value, ...$routeParameters, ...$fields];
        $back = $this->requestStack->getCurrentRequest()?->getRequestUri();

        if ($back !== null && $back !== '') {
            $query['back'] = $back;
        }

        return $this->urlGenerator->generate('post_action_confirm', $query);
    }

    /**
     * @param array<string, string> $routeParameters
     * @param array<string, string> $fields
     */
    public function attributes(string $action, array $routeParameters = [], array $fields = []): string
    {
        $postAction = PostAction::from($action);
        $href = $this->confirmUrl($action, $routeParameters, $fields);

        if ($postAction->confirmsInFrame()) {
            return sprintf(
                'href="%s" rel="nofollow" data-turbo-frame="%s"',
                self::escape($href),
                $this->platformDetector->isNativeApp() ? '_top' : 'modal-frame',
            );
        }

        return sprintf('href="%s" rel="nofollow" ', self::escape($href)) . $this->dataAttributes($action, $routeParameters, $fields);
    }

    /**
     * The controller's attributes alone - for a GET form to the confirmation view whose fields come from the form (the
     * Players country nudge)
     *
     * @param array<string, string> $routeParameters
     * @param array<string, string> $fields
     */
    public function dataAttributes(string $action, array $routeParameters = [], array $fields = []): string
    {
        $postAction = PostAction::from($action);
        $this->validate($postAction, $routeParameters, $fields);

        $tokenId = $postAction->csrfTokenId($routeParameters);
        $attributes = sprintf(
            'data-controller="post-action" data-post-action-url-value="%s" data-post-action-failed-value="%s"',
            self::escape($this->urlGenerator->generate($postAction->route(), $routeParameters)),
            self::escape($this->translator->trans('post_action.failed')),
        );

        if ($fields !== []) {
            $attributes .= sprintf(' data-post-action-fields-value="%s"', self::escape(json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        }

        if ($tokenId !== null) {
            $attributes .= sprintf(' data-post-action-token-value="%s"', self::escape($this->csrfTokenManager->getToken($tokenId)->getValue()));
        }

        return $attributes;
    }

    /**
     * @param array<string, string> $routeParameters
     * @param array<string, string> $fields
     */
    private function validate(PostAction $postAction, array $routeParameters, array $fields): void
    {
        if ($postAction->routeParametersFrom($routeParameters) === null || $postAction->fieldsFrom($fields) === null || array_diff_key($fields, $postAction->fields()) !== []) {
            throw new \InvalidArgumentException(sprintf('The values for the one-tap action "%s" do not fit it.', $postAction->value));
        }
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

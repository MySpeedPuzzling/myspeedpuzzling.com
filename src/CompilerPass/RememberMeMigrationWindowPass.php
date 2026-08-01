<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\CompilerPass;

use SpeedPuzzling\Web\Security\MigrationWindowRememberMeListener;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Two fixes that let remember-me run on the main firewall while the Auth0
 * authenticator and the window-A chain user provider are still wired
 * (issue #147). Both are artifacts of the migration window and both are
 * deleted in Phase 6, when the firewall drops to user_account_provider alone
 * and Symfony's stock listener becomes correct again.
 *
 * A compiler pass rather than firewall config, because neither fix is reachable
 * from security.php: the listener's subscribed events come from a static method
 * on its class (so the class itself must change, which rules out decoration
 * too), and RememberMeFactory does not extend AbstractFactory, so its config
 * tree has no 'provider' node at all. The definitions themselves are created by
 * SecurityBundle's extension, which is merged into the container after
 * config/services.php is loaded - a plain $services->set() there would just be
 * overwritten.
 */
final class RememberMeMigrationWindowPass implements CompilerPassInterface
{
    private const string LISTENER_ID = 'security.listener.remember_me.main';

    private const string HANDLER_ID = 'security.authenticator.remember_me_handler.main';

    private const string USER_PROVIDER_ID = 'security.user.provider.concrete.user_account_provider';

    /** Position of the user provider in SignatureRememberMeHandler's constructor. */
    private const int HANDLER_USER_PROVIDER_ARGUMENT = 1;

    public function process(ContainerBuilder $container): void
    {
        // Both definitions are created together by RememberMeFactory, so one
        // check covers the pair. Absent only if remember_me is taken off the
        // main firewall - which is the Phase 6 change that also deletes this
        // pass, so there is nothing to do rather than something to repair.
        if (!$container->hasDefinition(self::LISTENER_ID)) {
            return;
        }

        $this->scopeListener($container);
        $this->pinHandlerUserProvider($container);
    }

    /**
     * Core's RememberMeListener clears the cookie on every LoginFailureEvent and
     * the Auth0 authenticator fails on every request - see
     * MigrationWindowRememberMeListener for the full story. The constructor
     * signature is identical (handler, nullable logger), so only the class changes.
     */
    private function scopeListener(ContainerBuilder $container): void
    {
        $container->getDefinition(self::LISTENER_ID)
            ->setClass(MigrationWindowRememberMeListener::class);
    }

    /**
     * The handler inherits the firewall's provider, which during window A is the
     * chain (user_account_provider -> auth0_provider). The chain is fine while the
     * account exists - the native provider claims every "msp|..."/"auth0|..."
     * identifier first - but once it throws UserNotFoundException (a deleted
     * account still holding a valid 30-day cookie, which GDPR deletion now
     * produces) ChainUserProvider falls through to the Auth0 provider, whose
     * loadUserByIdentifier() json_decodes the identifier with JSON_THROW_ON_ERROR.
     * The resulting JsonException is neither an AuthenticationException nor a
     * UserNotFoundException, so nothing catches it: that visitor would get a 500
     * on every page until the cookie expires. Pin the native provider so a missing
     * account degrades to "invalid cookie, stay anonymous".
     */
    private function pinHandlerUserProvider(ContainerBuilder $container): void
    {
        // No hasDefinition() guard on USER_PROVIDER_ID: a provider declared with
        // an 'id' (ours is) lands in the container as an ALIAS, for which
        // hasDefinition() is false - an earlier version of this guard silently
        // skipped the whole fix because of it. A Reference resolves through the
        // alias fine, and getDefinition() below throws loudly if the handler
        // itself ever moves.
        $container->getDefinition(self::HANDLER_ID)
            ->replaceArgument(self::HANDLER_USER_PROVIDER_ARGUMENT, new Reference(self::USER_PROVIDER_ID));
    }
}

<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Disables the curl share handle in Sentry's HTTP client.
 *
 * In FrankenPHP worker mode, the persistent curl share handle keeps pooled connections
 * alive across requests. When the remote Sentry ingestion proxy closes idle connections,
 * curl reuses the stale connection and gets "upstream connect error or disconnect/reset
 * before headers" errors.
 */
final class SentryDisableShareHandleCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('sentry.client.options')) {
            return;
        }

        $definition = $container->getDefinition('sentry.client.options');

        /** @var array<string, mixed> $options */
        $options = $definition->getArgument(0);
        $options['http_enable_curl_share_handle'] = false;

        $definition->setArgument(0, $options);
    }
}

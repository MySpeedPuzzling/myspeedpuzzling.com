<?php declare(strict_types=1);

use Liip\ImagineBundle\Message\WarmupCache;
use Symfony\Config\FrameworkConfig;

return static function (FrameworkConfig $framework): void {
    $messenger = $framework->messenger();

    $bus = $messenger->bus('command_bus');
    $bus->middleware()->id('doctrine_transaction');

    $messenger
        ->transport('async')
        ->options([
            'auto_setup' => false,
        ])
        ->dsn('%env(MESSENGER_TRANSPORT_DSN)%');

    $messenger->routing(WarmupCache::class)->senders(['async']);
};

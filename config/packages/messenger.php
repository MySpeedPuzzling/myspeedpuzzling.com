<?php declare(strict_types=1);

use Liip\ImagineBundle\Message\WarmupCache;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Config\FrameworkConfig;

return static function (FrameworkConfig $framework): void {
    $messenger = $framework->messenger();

    $bus = $messenger->bus('command_bus');
    $bus->middleware()->id('doctrine_transaction');

    $messenger->failureTransport('failed');

    $messenger->transport('sync')
        ->dsn('sync://');

    $messenger->transport('failed')
        ->dsn('doctrine://default?queue_name=failed');

    $messenger->transport('async')
        ->options([
            'auto_setup' => false,
        ])
        ->dsn('%env(MESSENGER_TRANSPORT_DSN)%');

    $messenger->routing(WarmupCache::class)->senders(['async']);
    $messenger->routing(SendEmailMessage::class)->senders(['async']);
    // Events that must run synchronously for immediate UI updates (Turbo Streams)
    $messenger->routing('SpeedPuzzling\Web\Events\PuzzleBorrowed')->senders(['sync']);
    $messenger->routing('SpeedPuzzling\Web\Events\PuzzleAddedToCollection')->senders(['sync']);
    $messenger->routing('SpeedPuzzling\Web\Events\LendingTransferCompleted')->senders(['sync']);
    // All other events can run asynchronously
    $messenger->routing('SpeedPuzzling\Web\Events\*')->senders(['async']);
};

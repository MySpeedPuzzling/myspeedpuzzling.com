<?php declare(strict_types=1);

use Liip\ImagineBundle\Message\WarmupCache;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Config\FrameworkConfig;

return static function (FrameworkConfig $framework): void {
    $messenger = $framework->messenger();

    $messenger->transport('async')
        ->dsn('in-memory://');
};

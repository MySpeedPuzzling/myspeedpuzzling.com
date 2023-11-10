<?php declare(strict_types=1);

use AsyncAws\S3\S3Client;
use League\Flysystem\Filesystem;
use Symfony\Config\OneupFlysystemConfig;

return static function (OneupFlysystemConfig $config): void {
    $config->adapter('minio')
        ->asyncAwsS3()
            ->client(S3Client::class)
            ->bucket('puzzle');

    $config->filesystem('minio')
        ->adapter('minio')
        ->alias(Filesystem::class)
        ->visibility('public')
        ->directoryVisibility('public');
};

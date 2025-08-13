<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\LanguageConstruct\NullableTypeDeclarationFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withEditorConfig()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/migrations',
    ])
    ->withPreparedSets(
        psr12: true,
        common: true,
        cleanCode: true,
    )
    ->withConfiguredRule(NullableTypeDeclarationFixer::class, [
        'syntax' => 'union',
    ]);

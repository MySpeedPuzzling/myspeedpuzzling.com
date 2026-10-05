<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\EarlyHintsLinkHeader;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\WebpackEncoreBundle\Asset\EntrypointLookup;
use Symfony\WebpackEncoreBundle\Asset\EntrypointLookupCollection;
use Symfony\WebpackEncoreBundle\Asset\TagRenderer;

final class EarlyHintsLinkHeaderTest extends TestCase
{
    /**
     * Shaped like the production entrypoints.json (hashed file names + the SRI map of `enableIntegrityHashes()`).
     */
    private const array PRODUCTION_ENTRYPOINTS = [
        'entrypoints' => [
            'app' => [
                'js' => [
                    '/build/runtime.50dd899b.js',
                    '/build/45.d0b83876.js',
                    '/build/app.00ed7bb5.js',
                ],
                'css' => [
                    '/build/45.24554a53.css',
                    '/build/app.db0f6a58.css',
                ],
            ],
        ],
        'integrity' => [
            '/build/runtime.50dd899b.js' => 'sha384-runtimeRUNTIMEruntimeRUNTIMEruntimeRUNTIMEruntimeRUNTIMEruntime1',
            '/build/45.d0b83876.js' => 'sha384-vendorJsVENDORjsVendorJsVENDORjsVendorJsVENDORjsVendorJsVENDORjs2',
            '/build/app.00ed7bb5.js' => 'sha384-appJsAPPjsAppJsAPPjsAppJsAPPjsAppJsAPPjsAppJsAPPjsAppJsAPPjsAppJ3',
            '/build/45.24554a53.css' => 'sha384-vendorCssVENDORcssVendorCssVENDORcssVendorCssVENDORcssVendorCss4',
            '/build/app.db0f6a58.css' => 'sha384-appCssAPPcssAppCssAPPcssAppCssAPPcssAppCssAPPcssAppCssAPPcssApp5',
        ],
    ];

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testEveryEntryCarriesTheIntegrityOfItsFile(): void
    {
        $header = new EarlyHintsLinkHeader($this->writeEntrypoints(self::PRODUCTION_ENTRYPOINTS));

        self::assertSame(
            '</build/45.24554a53.css>; rel=preload; as=style; integrity="sha384-vendorCssVENDORcssVendorCssVENDORcssVendorCssVENDORcssVendorCss4", '
            . '</build/app.db0f6a58.css>; rel=preload; as=style; integrity="sha384-appCssAPPcssAppCssAPPcssAppCssAPPcssAppCssAPPcssAppCssAPPcssApp5", '
            . '</build/runtime.50dd899b.js>; rel=preload; as=script; integrity="sha384-runtimeRUNTIMEruntimeRUNTIMEruntimeRUNTIMEruntimeRUNTIMEruntime1", '
            . '</build/45.d0b83876.js>; rel=preload; as=script; integrity="sha384-vendorJsVENDORjsVendorJsVENDORjsVendorJsVENDORjsVendorJsVENDORjs2", '
            . '</build/app.00ed7bb5.js>; rel=preload; as=script; integrity="sha384-appJsAPPjsAppJsAPPjsAppJsAPPjsAppJsAPPjsAppJsAPPjsAppJsAPPjsAppJ3"',
            $header->get(),
        );
    }

    public function testDevBuildWithoutIntegrityMapHasNoIntegrityParameter(): void
    {
        $header = new EarlyHintsLinkHeader($this->writeEntrypoints([
            'entrypoints' => [
                'app' => [
                    'js' => ['/build/runtime.js', '/build/app.js'],
                    'css' => ['/build/app.css'],
                ],
            ],
        ]));

        self::assertSame(
            '</build/app.css>; rel=preload; as=style, '
            . '</build/runtime.js>; rel=preload; as=script, '
            . '</build/app.js>; rel=preload; as=script',
            $header->get(),
        );
    }

    public function testMissingFileGivesNoHeader(): void
    {
        $header = new EarlyHintsLinkHeader(sys_get_temp_dir() . '/early-hints-missing-' . bin2hex(random_bytes(6)) . '.json');

        self::assertNull($header->get());
    }

    public function testEntrypointsWithoutAppEntryGiveNoHeader(): void
    {
        $header = new EarlyHintsLinkHeader($this->writeEntrypoints(['entrypoints' => []]));

        self::assertNull($header->get());
    }

    /**
     * The preloads are useful only when they match the tags Encore renders from the same build:
     * the same URLs, each with the same integrity.
     */
    public function testEntriesMirrorTheTagsEncoreRenders(): void
    {
        $path = $this->writeEntrypoints(self::PRODUCTION_ENTRYPOINTS);

        $tagRenderer = new TagRenderer(
            new EntrypointLookupCollection(
                new ServiceLocator(['_default' => static fn (): EntrypointLookup => new EntrypointLookup($path)]),
                '_default',
            ),
            new Packages(new Package(new EmptyVersionStrategy())),
        );

        $tags = $tagRenderer->renderWebpackLinkTags('app') . $tagRenderer->renderWebpackScriptTags('app');
        preg_match_all('~<(?:link|script) [^>]*?(?:href|src)="([^"]+)"[^>]*?integrity="([^"]+)"~', $tags, $tagMatches, PREG_SET_ORDER);

        $header = new EarlyHintsLinkHeader($path)->get();
        self::assertNotNull($header);
        preg_match_all('~<([^>]+)>; rel=preload; as=(?:style|script); integrity="([^"]+)"~', $header, $linkMatches, PREG_SET_ORDER);

        $tagIntegrity = [];
        foreach ($tagMatches as $match) {
            $tagIntegrity[$match[1]] = $match[2];
        }

        $linkIntegrity = [];
        foreach ($linkMatches as $match) {
            $linkIntegrity[$match[1]] = $match[2];
        }

        self::assertCount(5, $tagIntegrity);
        self::assertSame($tagIntegrity, $linkIntegrity);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeEntrypoints(array $data): string
    {
        $path = sys_get_temp_dir() . '/early-hints-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->files[] = $path;

        return $path;
    }
}

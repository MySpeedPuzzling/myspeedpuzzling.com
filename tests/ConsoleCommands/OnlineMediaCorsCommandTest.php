<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\ConsoleCommands;

use AsyncAws\Core\Configuration;
use AsyncAws\S3\S3Client;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\ConsoleCommands\OnlineMediaCorsCommand;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaBucketCors;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * `myspeedpuzzling:online-media:cors` - the bucket CORS rule the phones' uploads need: printed, or applied while the
 * bucket's other rules stay.
 */
final class OnlineMediaCorsCommandTest extends KernelTestCase
{
    public function testPrintsTheRuleForTheSitesOwnOrigin(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('myspeedpuzzling:online-media:cors'));

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('"ID": "' . OnlineMediaBucketCors::RULE_ID . '"', $display);
        // APP_URL of the test environment
        self::assertStringContainsString('"http://localhost:8080"', $display);
        self::assertStringContainsString('"PUT"', $display);
        self::assertStringContainsString('Not applied', $display);
    }

    public function testAnOriginThatIsNoOriginIsRefused(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('myspeedpuzzling:online-media:cors'));

        $tester->execute(['--origin' => ['myspeedpuzzling.com']]);

        self::assertSame(1, $tester->getStatusCode());
    }

    public function testApplyingReplacesOurRuleAndKeepsTheBucketsOthers(): void
    {
        $written = null;
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$written): MockResponse {
            if ($method === 'GET') {
                return new MockResponse(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<CORSConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
  <CORSRule><ID>images</ID><AllowedOrigin>*</AllowedOrigin><AllowedMethod>GET</AllowedMethod><MaxAgeSeconds>600</MaxAgeSeconds></CORSRule>
  <CORSRule><ID>msp-online-media</ID><AllowedOrigin>https://old.example</AllowedOrigin><AllowedMethod>PUT</AllowedMethod></CORSRule>
</CORSConfiguration>
XML, ['http_code' => 200, 'response_headers' => ['content-type' => 'application/xml']]);
            }

            self::assertSame('PUT', $method);
            self::assertStringContainsString('/puzzle?cors', $url);
            $written = is_string($options['body'] ?? null) ? $options['body'] : '';

            return new MockResponse('', ['http_code' => 200]);
        });

        $tester = new CommandTester(new OnlineMediaCorsCommand(new OnlineMediaBucketCors(self::stores($httpClient)), 'https://myspeedpuzzling.com/'));
        $tester->execute(['--apply' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertIsString($written);
        self::assertStringContainsString('<ID>images</ID>', $written);
        self::assertStringContainsString('<AllowedOrigin>*</AllowedOrigin>', $written);
        self::assertStringContainsString('<ID>msp-online-media</ID>', $written);
        self::assertStringContainsString('<AllowedOrigin>https://myspeedpuzzling.com</AllowedOrigin>', $written);
        self::assertStringContainsString('<AllowedMethod>PUT</AllowedMethod>', $written);
        self::assertStringNotContainsString('https://old.example', $written);
    }

    public function testABucketWithoutRulesGetsOurs(): void
    {
        $written = null;
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$written): MockResponse {
            if ($method === 'GET') {
                return new MockResponse('<?xml version="1.0" encoding="UTF-8"?><Error><Code>NoSuchCORSConfiguration</Code><Message>The CORS configuration does not exist</Message></Error>', ['http_code' => 404]);
            }

            $written = is_string($options['body'] ?? null) ? $options['body'] : '';

            return new MockResponse('', ['http_code' => 200]);
        });

        $tester = new CommandTester(new OnlineMediaCorsCommand(new OnlineMediaBucketCors(self::stores($httpClient)), 'https://myspeedpuzzling.com/'));
        $tester->execute(['--apply' => true, '--origin' => ['https://myspeedpuzzling.com', 'http://localhost:8080/']]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertIsString($written);
        self::assertSame(1, substr_count($written, '<CORSRule>'));
        self::assertStringContainsString('<AllowedOrigin>http://localhost:8080</AllowedOrigin>', $written);
    }

    private static function stores(MockHttpClient $httpClient): OnlineMediaStores
    {
        return new OnlineMediaStores(
            primaryClient: new S3Client([
                Configuration::OPTION_REGION => 'eu',
                Configuration::OPTION_ENDPOINT => 'https://store.example.org',
                Configuration::OPTION_ACCESS_KEY_ID => 'key',
                Configuration::OPTION_SECRET_ACCESS_KEY => 'secret',
                Configuration::OPTION_PATH_STYLE_ENDPOINT => 'true',
            ], null, $httpClient),
            primaryRegion: 'eu',
            primaryBucket: 'puzzle',
            primaryAccessKey: 'key',
            primarySecretKey: 'secret',
            primaryPublicEndpoint: 'https://store.example.org',
            secondaryEndpoint: '',
            secondaryRegion: '',
            secondaryBucket: '',
            secondaryAccessKey: '',
            secondarySecretKey: '',
            secondaryPathStyle: true,
            httpClient: $httpClient,
            logger: new NullLogger(),
        );
    }
}

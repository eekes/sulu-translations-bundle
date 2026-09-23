<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Unit\Infrastructure\HttpClient;

use Eekes\SuluTranslationsBundle\Domain\Exception\MachineTranslationException;
use Eekes\SuluTranslationsBundle\Infrastructure\HttpClient\DeeplMachineTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class DeeplMachineTranslatorTest extends TestCase
{
    public function testItSendsTheBatchAndReturnsTheResultsInOrder(): void
    {
        /** @var array{method: string, url: string, body: array<string, mixed>} $request */
        $request = ['method' => '', 'url' => '', 'body' => []];

        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$request): MockResponse {
            /** @var array<string, mixed> $body */
            $body = \json_decode((string) $options['body'], true);
            $request = ['method' => $method, 'url' => $url, 'body' => $body];

            return new MockResponse(\json_encode([
                'translations' => [['text' => 'Hallo'], ['text' => 'Tot ziens']],
            ]) ?: '');
        });

        $translator = new DeeplMachineTranslator($client, 'key-123');

        self::assertSame(['Hallo', 'Tot ziens'], $translator->translate(['Hello', 'Goodbye'], 'en', 'nl'));

        self::assertSame('POST', $request['method']);
        self::assertSame('EN', $request['body']['source_lang']);
        self::assertSame('NL', $request['body']['target_lang']);

        // The documented way to make DeepL leave placeholders alone, and the reason the
        // protector wraps them in `<x/>` in the first place.
        self::assertSame('xml', $request['body']['tag_handling']);

        // A list, not the comma-separated string the form-encoded flavour of the endpoint
        // takes. DeepL's JSON API answers the string with 400 "Value for 'ignore_tags' not
        // supported." and translates nothing - which is what a real key first produced.
        self::assertSame(['x'], $request['body']['ignore_tags']);
    }

    public function testARegionalTargetIsPassedOnAndAPlainOneIsLeftAlone(): void
    {
        $captured = [];

        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured[] = \json_decode((string) $options['body'], true)['target_lang'];

            return new MockResponse(\json_encode(['translations' => [['text' => 'x']]]) ?: '');
        });

        $translator = new DeeplMachineTranslator($client, 'key-123');

        $translator->translate(['a'], 'nl', 'en');
        $translator->translate(['a'], 'nl', 'en_US');
        $translator->translate(['a'], 'en', 'pt_BR');
        $translator->translate(['a'], 'en', 'de');

        // `EN` and not `EN-GB`: DeepL accepts the plain code and applies its own default, and
        // a project whose locale is just `en` has not said which English it writes.
        self::assertSame(['EN', 'EN-US', 'PT-BR', 'DE'], $captured);
    }

    public function testTheEndpointFollowsTheKeyUnlessThePlanSaysOtherwise(): void
    {
        // Free and Pro are separate hosts and each rejects the other's key with a bare 403.
        // DeepL marks a Free key with `:fx`, so the key answers the question by itself.
        $client = new MockHttpClient();

        self::assertSame(
            DeeplMachineTranslator::FREE_ENDPOINT,
            (new DeeplMachineTranslator($client, '279a2e9d-83b3-c416-7e2d-f721593e42a0:fx'))->getEndpoint(),
        );

        self::assertSame(
            DeeplMachineTranslator::PRO_ENDPOINT,
            (new DeeplMachineTranslator($client, '279a2e9d-83b3-c416-7e2d-f721593e42a0'))->getEndpoint(),
        );
    }

    public function testTheRequestGoesToTheResolvedEndpoint(): void
    {
        $urls = [];

        $client = new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse(\json_encode(['translations' => [['text' => 'x']]]) ?: '');
        });

        (new DeeplMachineTranslator($client, 'key:fx'))->translate(['a'], 'en', 'nl');
        (new DeeplMachineTranslator($client, 'key'))->translate(['a'], 'en', 'nl');

        self::assertSame([DeeplMachineTranslator::FREE_ENDPOINT, DeeplMachineTranslator::PRO_ENDPOINT], $urls);
    }

    public function testWithoutAnApiKeyItSupportsNothing(): void
    {
        $translator = new DeeplMachineTranslator(new MockHttpClient(), '');

        self::assertFalse($translator->supports('en', 'nl'));
    }

    public function testTheSameLocaleIsNeverSupported(): void
    {
        self::assertFalse((new DeeplMachineTranslator(new MockHttpClient(), 'key'))->supports('nl', 'nl'));
    }

    public function testAnEmptyBatchNeverReachesTheNetwork(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('No request should have been made.');
        });

        self::assertSame([], (new DeeplMachineTranslator($client, 'key'))->translate([], 'en', 'nl'));
    }

    public function testAFailureBecomesADomainException(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 456]));

        $this->expectException(MachineTranslationException::class);

        (new DeeplMachineTranslator($client, 'key'))->translate(['a'], 'en', 'nl');
    }

    public function testTheFailureCarriesWhatDeeplActuallySaid(): void
    {
        // Symfony's own message is `HTTP/2 400 returned for "..."` and names neither the
        // field nor the reason. DeepL puts the whole answer in the body, and that answer is
        // what reaches the editor under the value field and the developer in the log.
        $client = new MockHttpClient(new MockResponse(
            \json_encode(['message' => "Value for 'ignore_tags' not supported."]) ?: '',
            ['http_code' => 400],
        ));

        try {
            (new DeeplMachineTranslator($client, 'key'))->translate(['a'], 'en', 'nl');

            self::fail('A 400 has to become a MachineTranslationException.');
        } catch (MachineTranslationException $exception) {
            self::assertStringContainsString("Value for 'ignore_tags' not supported.", $exception->getMessage());
        }
    }

    public function testAFailureWithAnUnreadableBodyStillSaysSomething(): void
    {
        $client = new MockHttpClient(new MockResponse('<html>gateway timeout</html>', ['http_code' => 504]));

        try {
            (new DeeplMachineTranslator($client, 'key'))->translate(['a'], 'en', 'nl');

            self::fail('A 504 has to become a MachineTranslationException.');
        } catch (MachineTranslationException $exception) {
            // Not JSON and not ours to parse, but 200 characters of it beats nothing.
            self::assertStringContainsString('504', $exception->getMessage());
            self::assertStringContainsString('gateway timeout', $exception->getMessage());
        }
    }
}

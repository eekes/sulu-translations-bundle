<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\HttpClient;

use Eekes\SuluTranslationsBundle\Application\Machine\MachineTranslatorInterface;
use Eekes\SuluTranslationsBundle\Domain\Exception\MachineTranslationException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The one machine translator that ships with the bundle.
 *
 * DeepL because it is the engine whose API is small enough to implement honestly in one class
 * and whose placeholder handling is documented (`tag_handling=xml`). Anything else is a
 * consumer service id in `eekes_sulu_translations.machine_translation.service`.
 */
class DeeplMachineTranslator implements MachineTranslatorInterface
{
    public const FREE_ENDPOINT = 'https://api-free.deepl.com/v2/translate';
    public const PRO_ENDPOINT = 'https://api.deepl.com/v2/translate';

    /**
     * DeepL takes a plain language code, and accepts a regional variant as the **target** for
     * the few languages that have one. Only a locale that actually names a variant gets one:
     * plain `en` is passed through as `EN` so DeepL applies its own default, rather than this
     * bundle quietly deciding that a project's English is British.
     */
    private const TARGET_ALIASES = [
        'en_gb' => 'EN-GB',
        'en_us' => 'EN-US',
        'pt_br' => 'PT-BR',
        'pt_pt' => 'PT-PT',
    ];

    /**
     * @param list<string> $supportedLocales empty means "let DeepL decide"
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly array $supportedLocales = [],
        private readonly ?string $formality = null,
        private readonly ?string $glossaryId = null,
    ) {
    }

    /**
     * Free and Pro are two different hosts, and a key for one is rejected by the other with a
     * bare 403 that says nothing about why.
     *
     * DeepL documents the `:fx` suffix as what marks a Free key, so the key itself answers the
     * question and there is nothing for a project to configure.
     *
     * Resolved here rather than when the container is built, because the key is normally an
     * environment placeholder at that point and there is nothing to look at yet.
     */
    public function getEndpoint(): string
    {
        return \str_ends_with($this->apiKey, ':fx') ? self::FREE_ENDPOINT : self::PRO_ENDPOINT;
    }

    public function translate(array $texts, string $sourceLocale, string $targetLocale): array
    {
        if ([] === $texts) {
            return [];
        }

        if (!$this->supports($sourceLocale, $targetLocale)) {
            throw MachineTranslationException::unsupportedPair($sourceLocale, $targetLocale);
        }

        $body = [
            'text' => \array_values($texts),
            'source_lang' => \strtoupper($this->language($sourceLocale)),
            'target_lang' => $this->targetLanguage($targetLocale),
            // The reason placeholders survive: DeepL leaves unknown XML tags alone.
            'tag_handling' => 'xml',
            // A **list**, not the comma-separated string the form-encoded flavour of this
            // endpoint takes. Sending the string answers 400 "Value for 'ignore_tags' not
            // supported." and translates nothing at all.
            'ignore_tags' => ['x'],
        ];

        if (null !== $this->formality) {
            $body['formality'] = $this->formality;
        }

        if (null !== $this->glossaryId) {
            $body['glossary_id'] = $this->glossaryId;
        }

        try {
            $response = $this->httpClient->request('POST', $this->getEndpoint(), [
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
            ]);

            /** @var array{translations?: list<array{text: string}>} $payload */
            $payload = $response->toArray();
        } catch (HttpExceptionInterface $exception) {
            throw MachineTranslationException::providerFailed($this->reason($exception), $exception);
        }

        $translations = $payload['translations'] ?? null;

        if (null === $translations) {
            throw MachineTranslationException::providerFailed('the response contained no translations');
        }

        return \array_map(static fn (array $translation): string => $translation['text'], $translations);
    }

    /**
     * What DeepL said, rather than only that it said no.
     *
     * Symfony's message is `HTTP/2 400 returned for "https://api.deepl.com/v2/translate"`,
     * which names neither the field nor the reason - while the body is
     * `{"message":"Value for 'ignore_tags' not supported."}`, the whole answer. Dropping that
     * on the floor turned a one-line fix into an afternoon, so the body goes into the
     * message; an editor sees it under the value field and a developer sees it in the log.
     */
    private function reason(HttpExceptionInterface $exception): string
    {
        $message = $exception->getMessage();

        if (!$exception instanceof HttpClientExceptionInterface) {
            return $message;
        }

        try {
            // `false`: the response is an error, and asking for its content is the point.
            $body = \trim($exception->getResponse()->getContent(false));
        } catch (\Throwable) {
            return $message;
        }

        if ('' === $body) {
            return $message;
        }

        /** @var array{message?: string}|null $decoded */
        $decoded = \json_decode($body, true);

        return $message . ': ' . (\is_array($decoded) && isset($decoded['message'])
            ? $decoded['message']
            : \mb_substr($body, 0, 200));
    }

    public function supports(string $sourceLocale, string $targetLocale): bool
    {
        if ('' === $this->apiKey || $sourceLocale === $targetLocale) {
            return false;
        }

        if ([] === $this->supportedLocales) {
            return true;
        }

        return \in_array($sourceLocale, $this->supportedLocales, true)
            && \in_array($targetLocale, $this->supportedLocales, true);
    }

    private function language(string $locale): string
    {
        return \explode('_', \str_replace('-', '_', $locale))[0];
    }

    private function targetLanguage(string $locale): string
    {
        $normalised = \strtolower(\str_replace('-', '_', $locale));

        return self::TARGET_ALIASES[$normalised] ?? \strtoupper($this->language($normalised));
    }
}

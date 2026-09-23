<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Controller\Admin;

use Eekes\SuluTranslationsBundle\Application\Key\TranslationKeySynchronizer;
use Eekes\SuluTranslationsBundle\Application\Locale\LocaleProvider;
use Eekes\SuluTranslationsBundle\Application\Manager\TranslationManagerInterface;
use Eekes\SuluTranslationsBundle\Domain\Exception\InvalidIcuMessageException;
use Eekes\SuluTranslationsBundle\Domain\Exception\PlaceholderMismatchException;
use Eekes\SuluTranslationsBundle\Domain\Exception\PlaceholderStillInUseException;
use Eekes\SuluTranslationsBundle\Domain\Exception\TranslationWriteNotAllowedException;
use Eekes\SuluTranslationsBundle\Domain\Exception\UnsupportedLocaleException;
use Eekes\SuluTranslationsBundle\Domain\Model\Translation;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationSource;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationKeyRepositoryInterface;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationRepositoryInterface;
use Eekes\SuluTranslationsBundle\Infrastructure\Sulu\Security\TranslationSecurityChecker;
use Sulu\Component\Rest\ListBuilder\Doctrine\DoctrineListBuilderFactoryInterface;
use Sulu\Component\Rest\ListBuilder\FieldDescriptorInterface;
use Sulu\Component\Rest\ListBuilder\ListBuilderInterface;
use Sulu\Component\Rest\ListBuilder\Metadata\FieldDescriptorFactoryInterface;
use Sulu\Component\Rest\ListBuilder\PaginatedRepresentation;
use Sulu\Component\Rest\RestHelperInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin API of the translation list and form.
 *
 * The REST id is the **key** id, not the per-locale value id. That is what lets the language
 * switcher work: switching locale keeps the same URL and the same resource, and only the value
 * in the payload changes. A value row exists for every locale, so there is always one to show.
 *
 * **The payload deliberately carries no `availableLocales` or `contentLocales`.** Those are
 * Sulu's ghost mechanism: `Form.js` opens the "copy from another language" dialog as soon as
 * the current locale is not in `availableLocales`. For a page that is right - it really does
 * not exist in that language yet. For a translation key it is wrong twice over: the key exists
 * in every language by construction with only its value empty, and "not translated yet" is the
 * normal state of most rows here, not something to interrupt an editor about.
 *
 * It also crashes. With no value in any language the list is empty, Sulu opens the dialog
 * anyway (`[]` is truthy), and `LocalesOptionFormMetadataVisitor` then skips filling the select
 * because there are no locales to offer - so `ghost_copy_locale` renders a `single_select` with
 * no `values` and React throws.
 *
 * @internal this class should not be instantiated by a project; use a request or response
 *           listener to extend the endpoint's behaviour
 */
final class TranslationController
{
    public function __construct(
        private readonly TranslationKeyRepositoryInterface $keyRepository,
        private readonly TranslationRepositoryInterface $translationRepository,
        private readonly TranslationManagerInterface $manager,
        private readonly TranslationKeySynchronizer $synchronizer,
        private readonly LocaleProvider $localeProvider,
        private readonly TranslationSecurityChecker $securityChecker,
        private readonly FieldDescriptorFactoryInterface $fieldDescriptorFactory,
        private readonly DoctrineListBuilderFactoryInterface $listBuilderFactory,
        private readonly RestHelperInterface $restHelper,
        private readonly string $sourceLocale,
    ) {
    }

    public function cgetAction(Request $request): Response
    {
        $locale = $this->resolveLocale($request);

        if ($locale instanceof Response) {
            return $locale;
        }

        $this->securityChecker->checkPermission(PermissionTypes::VIEW, $locale);

        // Cheap, idempotent, and the only place that can notice a webspace gained a language
        // between two deploys.
        $this->synchronizer->synchroniseLocales();

        /** @var array<string, FieldDescriptorInterface> $fieldDescriptors */
        $fieldDescriptors = $this->fieldDescriptorFactory->getFieldDescriptors(TranslationKey::LIST_KEY);

        $listBuilder = $this->listBuilderFactory->create(TranslationKey::class);
        $this->restHelper->initializeListBuilder($listBuilder, $fieldDescriptors);
        $listBuilder->setParameter('locale', $locale);

        // The row carries the id of the value as well as of the key: the inline editor writes
        // one locale, the row identifies one key. `placeholders` travels the same way - the
        // cell marks a value that lost one, and the open editor lists them - without being a
        // column anybody has to look at.
        $hidden = [];

        foreach (['id', 'value', 'translated', 'placeholders'] as $name) {
            if (isset($fieldDescriptors[$name])) {
                $hidden[$name] = $fieldDescriptors[$name];
                $listBuilder->addSelectField($fieldDescriptors[$name]);
            }
        }

        if ($request->query->getBoolean('untranslated')) {
            $this->restrictToUntranslated($listBuilder, $fieldDescriptors);
        }

        /** @var list<array<string, mixed>> $items */
        $items = $listBuilder->execute();
        $items = $this->addReferenceValues($items, $request, $locale);

        $representation = new PaginatedRepresentation(
            $items,
            TranslationKey::RESOURCE_KEY,
            (int) $listBuilder->getCurrentPage(),
            (int) $listBuilder->getLimit(),
            (int) $listBuilder->count(),
        );

        return new JsonResponse($representation->toArray());
    }

    public function getAction(Request $request, int $id): Response
    {
        $locale = $this->resolveLocale($request);

        if ($locale instanceof Response) {
            return $locale;
        }

        $this->securityChecker->checkPermission(PermissionTypes::VIEW, $locale);

        $key = $this->keyRepository->findById($id);

        if (null === $key) {
            return $this->notFound(\sprintf('There is no translation key with id "%d".', $id));
        }

        return new JsonResponse($this->normalize($key, $locale));
    }

    public function postAction(Request $request): Response
    {
        $locale = $this->resolveLocale($request);

        if ($locale instanceof Response) {
            return $locale;
        }

        $this->securityChecker->checkPermission(PermissionTypes::ADD, $locale);

        /** @var array<string, mixed> $payload */
        $payload = $request->toArray();

        $translationKey = \trim((string) ($payload['translationKey'] ?? ''));
        $domain = \trim((string) ($payload['domain'] ?? 'messages'));

        if ('' === $translationKey) {
            return $this->badRequest('A translation needs a key.');
        }

        $domain = '' === $domain ? 'messages' : $domain;

        // `ensureKey()` hands back the key that is already there, which is right everywhere
        // else it is called from - the extractor, the importer, runtime discovery - and wrong
        // here. An editor filling in the add form is not asking to overwrite a text that
        // exists, and the form said nothing about doing so: this used to answer 200 with the
        // other key's id and silently replace its value in this language.
        if (null !== $this->keyRepository->findOneByKey($domain, $translationKey)) {
            return $this->conflict(\sprintf(
                'The key "%s" already exists in domain "%s". Open it from the list to change its translation.',
                $translationKey,
                $domain,
            ));
        }

        $key = $this->synchronizer->ensureKey(
            $domain,
            $translationKey,
            TranslationSource::Manual,
            // Same rule as on the edit path: only the admin right may decide that a new key
            // is an ICU message.
            $this->securityChecker->canAdminister() && (bool) ($payload['icu'] ?? false),
        );

        return $this->applyPayload($key, $locale, $payload);
    }

    public function putAction(Request $request, int $id): Response
    {
        $locale = $this->resolveLocale($request);

        if ($locale instanceof Response) {
            return $locale;
        }

        $this->securityChecker->checkPermission(PermissionTypes::EDIT, $locale);

        $key = $this->keyRepository->findById($id);

        if (null === $key) {
            return $this->notFound(\sprintf('There is no translation key with id "%d".', $id));
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->toArray();

        return $this->applyPayload($key, $locale, $payload);
    }

    public function deleteAction(Request $request, int $id): Response
    {
        $this->securityChecker->checkPermission(PermissionTypes::DELETE);

        $key = $this->keyRepository->findById($id);

        if (null === $key) {
            return $this->notFound(\sprintf('There is no translation key with id "%d".', $id));
        }

        try {
            $this->manager->deleteKey($key);
        } catch (TranslationWriteNotAllowedException $exception) {
            return $this->forbidden($exception->getMessage());
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Sulu's list sends `ids` for a multi-row delete.
     */
    public function cdeleteAction(Request $request): Response
    {
        $this->securityChecker->checkPermission(PermissionTypes::DELETE);

        $ids = \array_values(\array_filter(\array_map(
            static fn (string $id): int => (int) $id,
            \explode(',', (string) $request->query->get('ids', '')),
        )));

        $deleted = 0;
        $refused = [];

        foreach ($this->keyRepository->findByIds($ids) as $key) {
            try {
                $this->manager->deleteKey($key);
                ++$deleted;
            } catch (TranslationWriteNotAllowedException) {
                // A protected key or a read-only domain in a bulk selection is reported, not
                // a reason to fail the whole delete.
                $refused[] = $key->getKey();
            }
        }

        if ([] !== $refused) {
            return new JsonResponse([
                'code' => 1103,
                'message' => \sprintf('%d of the selected translations are protected and were kept: %s', \count($refused), \implode(', ', \array_slice($refused, 0, 10))),
                'deleted' => $deleted,
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyPayload(TranslationKey $key, string $locale, array $payload): Response
    {
        $translation = $key->getTranslation($locale)
            ?? $this->translationRepository->findOneByKeyAndLocale($key, $locale);

        if (null === $translation) {
            $translation = new Translation($key, $locale);
            $this->translationRepository->save($translation, false);
        }

        // `protected` and `icu` belong to the admin right. A client without it never gets
        // the fields in its form metadata, so it cannot send a *changed* value - and the
        // unchanged one is ignored rather than refused, because Sulu's form store echoes the
        // whole payload back on every save and a 403 there would block ordinary editing.
        if ($this->securityChecker->canAdminister() && \array_key_exists('protected', $payload)) {
            $key->setProtected((bool) $payload['protected']);
        }

        $value = \array_key_exists('value', $payload) ? $payload['value'] : $translation->getValue();

        try {
            // Before the value, so that turning the flag on validates what is already stored
            // in every language rather than only what is being typed now.
            if ($this->securityChecker->canAdminister() && \array_key_exists('icu', $payload)) {
                $this->manager->setIcu($key, (bool) $payload['icu']);
            }

            $this->manager->saveValue($translation, \is_string($value) ? $value : null);
        } catch (PlaceholderMismatchException $exception) {
            return $this->unprocessable($exception->getMessage(), [
                'missing' => $exception->getMissing(),
                'unexpected' => $exception->getUnexpected(),
            ]);
        } catch (PlaceholderStillInUseException $exception) {
            return $this->unprocessable($exception->getMessage(), [
                'stillInUse' => $exception->getPlaceholders(),
                'locales' => $exception->getLocales(),
            ]);
        } catch (InvalidIcuMessageException $exception) {
            return $this->unprocessable($exception->getMessage(), ['reason' => $exception->getReason()]);
        } catch (TranslationWriteNotAllowedException $exception) {
            return $this->forbidden($exception->getMessage());
        }

        return new JsonResponse($this->normalize($key, $locale));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(TranslationKey $key, string $locale): array
    {
        $translation = $key->getTranslation($locale)
            ?? $this->translationRepository->findOneByKeyAndLocale($key, $locale);

        $payload = [
            'id' => $key->getId(),
            'translationKey' => $key->getKey(),
            'domain' => $key->getDomain(),
            'locale' => $locale,
            'icu' => $key->isIcu(),
            // Not behind the admin right: knowing that this sentence has to contain `%name%`
            // is the translator's business more than anybody's.
            'placeholders' => $key->getPlaceholders() ?? [],
            'value' => $translation?->getValue(),
            'translated' => $translation?->isTranslated() ?? false,
            'translationId' => $translation?->getId(),
            // Every language of this key, not just the source one. Which of them is shown
            // next to the input is the translator's own choice from the list toolbar, and
            // that choice lives in their browser - there is no router attribute and no
            // request parameter carrying it to a form request. So the form ships all of
            // them and the field picks. A key has as many rows as the project has locales,
            // which is a handful of short strings.
            'values' => $this->valuesByLocale($key),
        ];

        // The developer-facing half of the key. The metadata visitor removes the fields that
        // would show these; this makes sure they are not in the response either.
        //
        // `icu` is the exception and stays: it is not a field here but the thing that decides
        // whether the value renders as a plural editor or a text area, and a translator has
        // to get the right editor. The *switch* is gone for them, and the write path above
        // ignores it, which is what "may not change it" actually requires.
        if ($this->securityChecker->canAdminister()) {
            $payload['protected'] = $key->isProtected();
            $payload['source'] = $key->getSource()->value;
            $payload['occurrences'] = $key->getOccurrences();
            $payload['lastUsedAt'] = $key->getLastUsedAt()?->format(\DATE_ATOM);
            $payload['orphanedAt'] = $key->getOrphanedAt()?->format(\DATE_ATOM);
        }

        return $payload;
    }

    /**
     * Every locale's value for one key, keyed by locale.
     *
     * Read off the key's own collection, which Doctrine loads in one go, rather than a query
     * per locale. A locale with no row yet - which only happens for a key created in this
     * same request, before materialisation - is simply absent.
     *
     * No per-locale permission check, deliberately, and for the same reason the reference
     * column in the list has none: seeing what a string says in another language is what
     * translating it requires.
     *
     * @return array<string, string|null>
     */
    private function valuesByLocale(TranslationKey $key): array
    {
        $values = [];

        foreach ($this->localeProvider->getLocales() as $supported) {
            $values[$supported] = $key->getTranslation($supported)?->getValue();
        }

        return $values;
    }

    /**
     * The reference-locale column: one query for the whole page, merged into the rows the list
     * builder produced. A translator needs the English next to the French, and a second
     * browser tab is not an answer.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function addReferenceValues(array $items, Request $request, string $locale): array
    {
        $referenceLocale = (string) $request->query->get('referenceLocale', $this->sourceLocale);

        // The list XML has to point `referenceValue` at a real column, and it points it at
        // the key's id. So when there is no reference locale to merge in, the field has to be
        // emptied rather than left alone - otherwise the column renders the row's id.
        if ('' === $referenceLocale || $referenceLocale === $locale || !$this->localeProvider->supports($referenceLocale)) {
            foreach ($items as $index => $item) {
                if (\array_key_exists('referenceValue', $item)) {
                    $items[$index]['referenceValue'] = null;
                }
            }

            return $items;
        }

        $ids = [];

        foreach ($items as $item) {
            if (isset($item['id'])) {
                $ids[] = (int) $item['id'];
            }
        }

        $values = $this->translationRepository->findValuesByKeyIds($ids, $referenceLocale);

        foreach ($items as $index => $item) {
            $items[$index]['referenceValue'] = $values[(int) ($item['id'] ?? 0)] ?? null;
        }

        return $items;
    }

    /**
     * The "not translated yet" filter, which is the screen a translator actually lives in.
     *
     * An empty value is always stored as NULL - `Translation::setValue()` normalises it - so a
     * single IS NULL is the whole condition, and Sulu turns a null value into exactly that.
     *
     * @param array<string, FieldDescriptorInterface> $fieldDescriptors
     */
    private function restrictToUntranslated(ListBuilderInterface $listBuilder, array $fieldDescriptors): void
    {
        $valueDescriptor = $fieldDescriptors['value'] ?? null;

        if (null === $valueDescriptor) {
            return;
        }

        $listBuilder->where($valueDescriptor, null);
    }

    private function resolveLocale(Request $request): string|Response
    {
        $locale = (string) $request->query->get('locale', '');

        if ('' === $locale) {
            $locale = $this->localeProvider->getDefaultLocale();
        }

        if (!$this->localeProvider->supports($locale)) {
            return $this->badRequest((new UnsupportedLocaleException($locale, $this->localeProvider->getLocales()))->getMessage());
        }

        return $locale;
    }

    private function notFound(string $message): JsonResponse
    {
        return $this->error(1100, $message, Response::HTTP_NOT_FOUND);
    }

    private function badRequest(string $message): JsonResponse
    {
        return $this->error(1101, $message, Response::HTTP_BAD_REQUEST);
    }

    private function forbidden(string $message): JsonResponse
    {
        return $this->error(1102, $message, Response::HTTP_FORBIDDEN);
    }

    private function conflict(string $message): JsonResponse
    {
        // 409 and not 422: nothing about the request is malformed or refused on its content,
        // the resource it would create is simply already there.
        return $this->error(1105, $message, Response::HTTP_CONFLICT);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function unprocessable(string $message, array $context = []): JsonResponse
    {
        // 422 and not 400: the request was understood, the content was refused. The inline
        // editor renders this on the cell rather than as a toast, so the editor sees which one.
        return $this->error(1104, $message, Response::HTTP_UNPROCESSABLE_ENTITY, $context);
    }

    /**
     * Every refusal this controller answers with, in the one shape both front ends read.
     *
     * **`detail` has to be the sentence.** Sulu's form view renders
     * `error.detail || error.title || "There was an error when trying to save the form"`, so a
     * body that carries only `message` shows the editor that sentence and nothing about what
     * actually happened - and a body whose `detail` is an object pushes an object into React.
     * Our own structured data therefore lives under `context`, which is ours.
     *
     * @param array<string, mixed> $context
     */
    private function error(int $code, string $message, int $status, array $context = []): JsonResponse
    {
        return new JsonResponse(
            ['code' => $code, 'message' => $message, 'detail' => $message, 'context' => $context],
            $status,
        );
    }
}

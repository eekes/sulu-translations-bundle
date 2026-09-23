<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Controller\Admin;

use Eekes\SuluTranslationsBundle\Application\Io\FileImporter;
use Eekes\SuluTranslationsBundle\Application\Locale\LocaleProvider;
use Eekes\SuluTranslationsBundle\Application\Machine\MachineTranslationRunner;
use Eekes\SuluTranslationsBundle\Application\Manager\TranslationManagerInterface;
use Eekes\SuluTranslationsBundle\Domain\Event\TranslationsBulkModifiedEvent;
use Eekes\SuluTranslationsBundle\Domain\Exception\MachineTranslationException;
use Eekes\SuluTranslationsBundle\Domain\Exception\TranslationExceptionInterface;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationKeyRepositoryInterface;
use Eekes\SuluTranslationsBundle\Infrastructure\Sulu\Security\TranslationSecurityChecker;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything the list toolbar can do that is not "edit one row".
 *
 * Every one of these runs through `TranslationManager`, so validation, versions, the activity
 * event and the catalogue bump happen for a bulk action exactly as they do for a single save.
 * Each also answers a dry run, because the dialogs show a count - and, for an import, a diff -
 * before they commit.
 *
 * @internal
 */
final class TranslationActionController
{
    public function __construct(
        private readonly TranslationManagerInterface $manager,
        private readonly LocaleProvider $localeProvider,
        private readonly TranslationSecurityChecker $securityChecker,
        private readonly FileImporter $importer,
        private readonly MachineTranslationRunner $machineTranslationRunner,
        private readonly TranslationKeyRepositoryInterface $keyRepository,
        private readonly Filesystem $filesystem,
        private readonly string $sourceLocale,
        private readonly bool $machineTranslationEnabled,
    ) {
    }

    public function postSearchReplaceAction(Request $request): Response
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->toArray();
        $locale = $this->locale($payload);

        $this->securityChecker->checkPermission(PermissionTypes::EDIT, $locale);

        $search = (string) ($payload['search'] ?? '');
        $replace = (string) ($payload['replace'] ?? '');
        $domain = $this->domain($payload);
        $dryRun = (bool) ($payload['dryRun'] ?? false);

        if ('' === $search) {
            return $this->badRequest('A search term is required.');
        }

        try {
            $count = $this->manager->searchAndReplace($locale, $search, $replace, $domain, $dryRun);
        } catch (TranslationExceptionInterface $exception) {
            // A replacement that eats a placeholder, or one that takes a placeholder out of
            // the source language while another still uses it. The transaction has not been
            // committed, so nothing was written - and the editor gets the sentence rather
            // than a 500.
            return $this->unprocessable($exception->getMessage());
        }

        if (!$dryRun && $count > 0) {
            $this->manager->commit(new TranslationsBulkModifiedEvent(
                ['action' => 'search_replace', 'search' => $search, 'replaced' => $count],
                $locale,
            ));
        }

        return new JsonResponse(['count' => $count, 'dryRun' => $dryRun]);
    }

    public function postCopyLocaleAction(Request $request): Response
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->toArray();
        $target = $this->locale($payload);
        $source = (string) ($payload['from'] ?? $this->sourceLocale);

        if (!$this->localeProvider->supports($source)) {
            return $this->badRequest(\sprintf('Unknown source locale "%s".', $source));
        }

        $this->securityChecker->checkPermission(PermissionTypes::EDIT, $target);

        $dryRun = (bool) ($payload['dryRun'] ?? false);

        try {
            $count = $this->manager->copyLocale($source, $target, $this->domain($payload), (bool) ($payload['overwrite'] ?? false), $dryRun);
        } catch (TranslationExceptionInterface $exception) {
            return $this->unprocessable($exception->getMessage());
        }

        if (!$dryRun && $count > 0) {
            $this->manager->commit(new TranslationsBulkModifiedEvent(
                ['action' => 'copy_locale', 'from' => $source, 'copied' => $count],
                $target,
            ));
        }

        return new JsonResponse(['count' => $count, 'dryRun' => $dryRun]);
    }

    public function postMachineTranslateAction(Request $request): Response
    {
        if (!$this->machineTranslationEnabled) {
            return $this->badRequest('Machine translation is not configured.');
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->toArray();
        $target = $this->locale($payload);
        $source = (string) ($payload['from'] ?? $this->sourceLocale);

        $this->securityChecker->checkPermission(PermissionTypes::EDIT, $target);

        /** @var list<int> $keyIds */
        $keyIds = \array_map('\intval', (array) ($payload['ids'] ?? []));

        try {
            $summary = $this->machineTranslationRunner->run(
                $source,
                $target,
                $this->domain($payload),
                $keyIds,
                isset($payload['limit']) ? (int) $payload['limit'] : null,
                (bool) ($payload['dryRun'] ?? false),
            );
        } catch (MachineTranslationException $exception) {
            return $this->badRequest($exception->getMessage());
        }

        return new JsonResponse($summary);
    }

    /**
     * A suggestion for one field, from the reference language, **not saved**.
     *
     * This is what the translate button next to the value field calls. Handing the text back
     * instead of writing it is the whole point: the editor reads it, changes what they want
     * and presses save, and the placeholder validation then runs on what they actually chose.
     */
    public function postTranslateFieldAction(Request $request): Response
    {
        if (!$this->machineTranslationEnabled) {
            return $this->badRequest('Machine translation is not configured.');
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->toArray();
        $target = $this->locale($payload);
        $source = (string) ($payload['from'] ?? $this->sourceLocale);

        $this->securityChecker->checkPermission(PermissionTypes::EDIT, $target);

        if (!$this->localeProvider->supports($source)) {
            return $this->badRequest(\sprintf('Unknown source language "%s".', $source));
        }

        if ($source === $target) {
            return $this->badRequest('The source and target language are the same.');
        }

        $key = $this->keyRepository->findById((int) ($payload['id'] ?? 0));

        if (null === $key) {
            return $this->badRequest('There is no such translation key.');
        }

        $text = $key->getTranslation($source)?->getValue();

        if (null === $text || '' === \trim($text)) {
            return $this->badRequest(\sprintf('There is nothing to translate from in "%s".', $source));
        }

        try {
            $value = $this->machineTranslationRunner->translateText($text, $source, $target);
        } catch (MachineTranslationException $exception) {
            return $this->badRequest($exception->getMessage());
        }

        if (null === $value) {
            // The result lost a placeholder. Saying so is better than offering a broken
            // suggestion the write path would refuse anyway.
            return $this->badRequest('The translation came back without its placeholders and was discarded.');
        }

        return new JsonResponse(['value' => $value, 'source' => $text, 'from' => $source]);
    }

    /**
     * Upload, then preview, then commit. The preview is not optional: an import that
     * overwrites eight hundred edited strings without showing them first is how a bundle loses
     * a user's trust exactly once.
     */
    public function postImportAction(Request $request): Response
    {
        $locale = (string) $request->request->get('locale', $this->localeProvider->getDefaultLocale());

        $this->securityChecker->checkPermission(PermissionTypes::EDIT, $locale);

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return $this->badRequest('No file was uploaded.');
        }

        $domain = (string) $request->request->get('domain', 'messages');
        $overwrite = $request->request->getBoolean('overwrite');
        $dryRun = $request->request->getBoolean('dryRun', true);

        // Symfony's reader works on directories and derives the locale and the domain from the
        // file name, so the upload is placed in a directory of its own under the name it needs
        // to have.
        $directory = \sys_get_temp_dir() . '/sulu-translations-' . \bin2hex(\random_bytes(6));
        $extension = $file->getClientOriginalExtension();
        $this->filesystem->mkdir($directory);

        try {
            $file->move($directory, \sprintf('%s.%s.%s', $domain, $locale, '' === $extension ? 'yaml' : $extension));

            $result = $dryRun
                ? $this->importer->preview([$directory], [$locale], $domain, $overwrite)
                : $this->importer->import([$directory], [$locale], $domain, $overwrite);
        } catch (TranslationExceptionInterface $exception) {
            return $this->badRequest($exception->getMessage());
        } finally {
            $this->filesystem->remove($directory);
        }

        return new JsonResponse($result->toArray() + ['samples' => $result->samples]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function locale(array $payload): string
    {
        $locale = (string) ($payload['locale'] ?? '');

        return '' === $locale ? $this->localeProvider->getDefaultLocale() : $locale;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function domain(array $payload): ?string
    {
        $domain = $payload['domain'] ?? null;

        return \is_string($domain) && '' !== $domain ? $domain : null;
    }

    /**
     * The request was understood and the content was refused - a placeholder a bulk action
     * would have broken. 422, like every other refused write in this bundle.
     */
    private function unprocessable(string $message): JsonResponse
    {
        return $this->error(1104, $message, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function badRequest(string $message): JsonResponse
    {
        return $this->error(1101, $message, Response::HTTP_BAD_REQUEST);
    }

    /**
     * `detail` carries the sentence for the same reason it does on the other controller: it
     * is the field Sulu's own views render, and a body with only `message` in it reaches the
     * editor as "There was an error when trying to save the form".
     */
    private function error(int $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['code' => $code, 'message' => $message, 'detail' => $message], $status);
    }
}

<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Controller\Admin;

use Eekes\SuluTranslationsBundle\Application\Locale\LocaleProvider;
use Eekes\SuluTranslationsBundle\Application\Manager\TranslationManagerInterface;
use Eekes\SuluTranslationsBundle\Domain\Exception\TranslationWriteNotAllowedException;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationVersion;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationKeyRepositoryInterface;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationRepositoryInterface;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationVersionRepositoryInterface;
use Eekes\SuluTranslationsBundle\Infrastructure\Sulu\Security\TranslationSecurityChecker;
use Sulu\Component\Rest\ListBuilder\PaginatedRepresentation;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The versions tab: what this string used to say, and a way back.
 *
 * Not a `DoctrineListBuilder` list. The rows belong to one translation, they are never
 * searched, filtered or sorted by anything but their age, and hand-writing twenty lines here
 * is cheaper than a list configuration that has to carry two router attributes to mean
 * "the current key in the current language".
 *
 * @internal
 */
final class TranslationVersionController
{
    public function __construct(
        private readonly TranslationKeyRepositoryInterface $keyRepository,
        private readonly TranslationRepositoryInterface $translationRepository,
        private readonly TranslationVersionRepositoryInterface $versionRepository,
        private readonly TranslationManagerInterface $manager,
        private readonly LocaleProvider $localeProvider,
        private readonly TranslationSecurityChecker $securityChecker,
    ) {
    }

    public function cgetAction(Request $request): Response
    {
        $locale = (string) $request->query->get('locale', $this->localeProvider->getDefaultLocale());
        $keyId = $request->query->getInt('translationKeyId');

        $this->securityChecker->checkPermission(PermissionTypes::VIEW, $locale);

        $key = $this->keyRepository->findById($keyId);
        $translation = null === $key ? null : $this->translationRepository->findOneByKeyAndLocale($key, $locale);

        if (null === $translation) {
            return new JsonResponse((new PaginatedRepresentation([], TranslationVersion::RESOURCE_KEY, 1, 100, 0))->toArray());
        }

        $page = \max(1, $request->query->getInt('page', 1));
        $limit = \max(1, $request->query->getInt('limit', 50));

        $versions = $this->versionRepository->findByTranslation($translation, $limit, ($page - 1) * $limit);
        $total = $this->versionRepository->countByTranslation($translation);

        $items = \array_map(
            static fn (TranslationVersion $version): array => [
                'id' => $version->getId(),
                'value' => $version->getValue(),
                'userName' => $version->getUserName(),
                'createdAt' => $version->getCreatedAt()->format(\DATE_ATOM),
            ],
            $versions,
        );

        return new JsonResponse(
            (new PaginatedRepresentation($items, TranslationVersion::RESOURCE_KEY, $page, $limit, $total))->toArray(),
        );
    }

    /**
     * Restoring is itself a change, so it appends a version of its own. Undoing an undo has to
     * work, and the trail has to show that somebody did it.
     */
    public function postTriggerAction(Request $request, int $id): Response
    {
        $version = $this->versionRepository->findById($id);

        if (null === $version) {
            return new JsonResponse(['code' => 1100, 'message' => \sprintf('There is no version with id "%d".', $id)], Response::HTTP_NOT_FOUND);
        }

        $this->securityChecker->checkPermission(PermissionTypes::EDIT, $version->getTranslation()->getLocale());

        if ('restore' !== $request->query->get('action')) {
            return new JsonResponse(['code' => 1101, 'message' => 'The only supported action is "restore".'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $translation = $this->manager->restoreVersion($version);
        } catch (TranslationWriteNotAllowedException $exception) {
            return new JsonResponse(['code' => 1102, 'message' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse([
            'id' => $translation->getKey()->getId(),
            'value' => $translation->getValue(),
            'translated' => $translation->isTranslated(),
        ]);
    }
}

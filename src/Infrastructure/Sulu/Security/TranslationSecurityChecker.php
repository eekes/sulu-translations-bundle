<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Sulu\Security;

use Eekes\SuluTranslationsBundle\Domain\Exception\TranslationWriteNotAllowedException;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Security\Authorization\SecurityCondition;

/**
 * One security context, optionally scoped per locale.
 *
 * Sulu's own permission model already carries a locale on `SecurityCondition`, and a role can
 * be granted a permission for a subset of locales. So "this translator may only edit French"
 * needs no extra contexts from us - it needs the locale passed into the condition, which is
 * what `eekes_sulu_translations.permissions.per_locale` switches on.
 *
 * Registering one context per locale instead would double the permission matrix a project
 * configures and would break the day a webspace gains a language.
 */
class TranslationSecurityChecker
{
    public function __construct(
        private readonly SecurityCheckerInterface $securityChecker,
        private readonly bool $perLocale = false,
    ) {
    }

    public function hasPermission(string $permission, ?string $locale = null): bool
    {
        return $this->securityChecker->hasPermission($this->condition($locale), $permission);
    }

    public function checkPermission(string $permission, ?string $locale = null): void
    {
        $this->securityChecker->checkPermission($this->condition($locale), $permission);
    }

    /**
     * The server-side gate on a write. The UI hides what a user may not do; this is what
     * decides.
     *
     * @throws TranslationWriteNotAllowedException
     */
    public function checkEdit(string $locale): void
    {
        if (!$this->hasPermission(PermissionTypes::EDIT, $locale)) {
            throw TranslationWriteNotAllowedException::locale($locale);
        }
    }

    /**
     * Whether this user may see *and* change the developer-facing half of a key: its context
     * panel, its ICU switch and its protected switch.
     *
     * Separate from editing on purpose. A translator needs the text and the history; the file
     * paths of the codebase and two switches that break pages when they are wrong are
     * somebody else's job.
     */
    public function canAdminister(): bool
    {
        return $this->securityChecker->hasPermission(
            new SecurityCondition(TranslationKey::ADMIN_SECURITY_CONTEXT),
            PermissionTypes::VIEW,
        );
    }

    public function isPerLocale(): bool
    {
        return $this->perLocale;
    }

    private function condition(?string $locale): SecurityCondition
    {
        return new SecurityCondition(
            TranslationKey::SECURITY_CONTEXT,
            $this->perLocale ? $locale : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Sulu\Admin\Metadata;

use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Infrastructure\Sulu\Security\TranslationSecurityChecker;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadataVisitorInterface;
use Sulu\Bundle\AdminBundle\Metadata\ListMetadata\ListMetadata;
use Sulu\Bundle\AdminBundle\Metadata\ListMetadata\ListMetadataVisitorInterface;

/**
 * Takes the developer-facing fields out of the forms and the list for anyone without the
 * `sulu.settings.translations_admin` right.
 *
 * Those fields are the context panel (where a key comes from, which templates use it, when
 * the site last rendered it), the **ICU** switch and the **protected** switch. They have one
 * thing in common: getting them wrong breaks a page, and none of them is any business of the
 * person whose job is to write good Dutch. A translator gets the text and the history; a
 * developer gets the switches.
 *
 * Removing them from the *metadata* rather than hiding them in the component matters:
 * a hidden field still ships its data to the browser, and an empty panel is worse than none.
 * The controller drops the same fields from the payload and refuses to write them.
 *
 * @internal
 */
class AdminFieldsMetadataVisitor implements FormMetadataVisitorInterface, ListMetadataVisitorInterface
{
    private const FORM_KEYS = [TranslationKey::FORM_KEY, TranslationKey::ADD_FORM_KEY];

    private const ADMIN_FORM_FIELDS = ['context', 'icu', 'protected'];

    private const ADMIN_LIST_FIELDS = ['source', 'lastUsedAt', 'orphanedAt'];

    public function __construct(private readonly TranslationSecurityChecker $securityChecker)
    {
    }

    public function visitFormMetadata(FormMetadata $formMetadata, string $locale, array $metadataOptions = []): void
    {
        if (!\in_array($formMetadata->getKey(), self::FORM_KEYS, true) || $this->securityChecker->canAdminister()) {
            return;
        }

        $items = $formMetadata->getItems();

        foreach (self::ADMIN_FORM_FIELDS as $field) {
            unset($items[$field]);
        }

        $formMetadata->setItems($items);
    }

    public function visitListMetadata(ListMetadata $listMetadata, string $key, string $locale, array $metadataOptions = []): void
    {
        if (TranslationKey::LIST_KEY !== $key || $this->securityChecker->canAdminister()) {
            return;
        }

        $fields = $listMetadata->getFields();

        foreach (self::ADMIN_LIST_FIELDS as $field) {
            unset($fields[$field]);
        }

        $listMetadata->setFields($fields);
    }
}

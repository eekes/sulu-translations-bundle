<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Unit\Infrastructure\Sulu\Admin\Metadata;

use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Infrastructure\Sulu\Admin\Metadata\AdminFieldsMetadataVisitor;
use Eekes\SuluTranslationsBundle\Infrastructure\Sulu\Security\TranslationSecurityChecker;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\ListMetadata\FieldMetadata as ListFieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\ListMetadata\ListMetadata;

class AdminFieldsMetadataVisitorTest extends TestCase
{
    public function testTheAdminFieldsAreRemovedWithoutThePermission(): void
    {
        $form = $this->form();

        $this->visitor(false)->visitFormMetadata($form, 'en');

        // Removed from the metadata rather than hidden in the component: a hidden field
        // still ships its data to the browser, and an empty panel is worse than none.
        self::assertSame(['value'], \array_keys($form->getItems()));
    }

    public function testTheAdminFieldsStayWithThePermission(): void
    {
        $form = $this->form();

        $this->visitor(true)->visitFormMetadata($form, 'en');

        self::assertSame(['context', 'value', 'icu', 'protected'], \array_keys($form->getItems()));
    }

    public function testTheAddFormIsGuardedAsWell(): void
    {
        $form = $this->form(TranslationKey::ADD_FORM_KEY);

        $this->visitor(false)->visitFormMetadata($form, 'en');

        // Having "add" does not make somebody a developer.
        self::assertSame(['value'], \array_keys($form->getItems()));
    }

    public function testOtherFormsAreLeftAlone(): void
    {
        $form = $this->form('some_other_form');

        $this->visitor(false)->visitFormMetadata($form, 'en');

        self::assertSame(['context', 'value', 'icu', 'protected'], \array_keys($form->getItems()));
    }

    public function testTheTechnicalColumnsAreRemovedWithoutThePermission(): void
    {
        $list = $this->list();

        $this->visitor(false)->visitListMetadata($list, TranslationKey::LIST_KEY, 'en');

        self::assertSame(['translationKey', 'value'], \array_keys($list->getFields()));
    }

    public function testTheTechnicalColumnsStayWithThePermission(): void
    {
        $list = $this->list();

        $this->visitor(true)->visitListMetadata($list, TranslationKey::LIST_KEY, 'en');

        self::assertSame(
            ['translationKey', 'value', 'source', 'lastUsedAt', 'orphanedAt'],
            \array_keys($list->getFields()),
        );
    }

    public function testOtherListsAreLeftAlone(): void
    {
        $list = $this->list();

        $this->visitor(false)->visitListMetadata($list, 'some_other_list', 'en');

        self::assertCount(5, $list->getFields());
    }

    private function visitor(bool $canAdminister): AdminFieldsMetadataVisitor
    {
        $securityChecker = $this->createMock(TranslationSecurityChecker::class);
        $securityChecker->method('canAdminister')->willReturn($canAdminister);

        return new AdminFieldsMetadataVisitor($securityChecker);
    }

    private function form(string $key = TranslationKey::FORM_KEY): FormMetadata
    {
        $form = new FormMetadata();
        $form->setKey($key);

        foreach (['context', 'value', 'icu', 'protected'] as $name) {
            $field = new FieldMetadata($name);
            $form->addItem($field);
        }

        return $form;
    }

    private function list(): ListMetadata
    {
        $list = new ListMetadata();
        $fields = [];

        foreach (['translationKey', 'value', 'source', 'lastUsedAt', 'orphanedAt'] as $name) {
            $fields[$name] = new ListFieldMetadata($name);
        }

        $list->setFields($fields);

        return $list;
    }
}

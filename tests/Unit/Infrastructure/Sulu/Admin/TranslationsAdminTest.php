<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Unit\Infrastructure\Sulu\Admin;

use Eekes\SuluTranslationsBundle\Application\Locale\LocaleProvider;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Infrastructure\Sulu\Admin\TranslationsAdmin;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactory;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Component\Localization\Localization;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;

class TranslationsAdminTest extends TestCase
{
    public function testTheListHasTheLanguageSwitcher(): void
    {
        $views = $this->build();
        $list = $views->get(TranslationsAdmin::LIST_VIEW)->getView();

        // `:locale` in the path plus `locales` in the options is what puts Sulu's language
        // dropdown top left. It is the feature the flat-row bundles cannot have, and the
        // reason this one is shaped the way it is.
        self::assertSame('/translations/:locale', $list->getPath());
        self::assertSame(['de', 'en', 'fr', 'nl'], $list->getOption('locales'));
        self::assertSame(TranslationKey::RESOURCE_KEY, $list->getOption('resourceKey'));
    }

    public function testTheFormHasItToo(): void
    {
        $views = $this->build();
        $form = $views->get(TranslationsAdmin::EDIT_FORM_VIEW)->getView();

        // So an editor can flip language while editing one key and keep their place.
        self::assertSame('/translations/:locale/:id', $form->getPath());
        self::assertSame(['de', 'en', 'fr', 'nl'], $form->getOption('locales'));
    }

    public function testTheListClearsTheActiveRowOnEveryVisit(): void
    {
        $list = $this->build()->get(TranslationsAdmin::LIST_VIEW)->getView();

        /** @var list<ToolbarAction> $toolbarActions */
        $toolbarActions = $list->getOption('toolbarActions');
        $types = \array_map(static fn (ToolbarAction $action) => $action->getType(), $toolbarActions);

        // Not writing the attribute is not enough: one that already reached a user - an old
        // version, a bookmark, a shared link - copies itself between the URL and their stored
        // settings forever. So the list drops it on mount, from the only hook Sulu offers.
        self::assertContains('eekes_sulu_translations.clear_active_row', $types);
    }

    public function testTheBackButtonDoesNotHandTheListAnActiveRow(): void
    {
        $form = $this->build()->get(TranslationsAdmin::EDIT_FORM_VIEW)->getView();

        // Mapping `id` to `active` is what a page tree does, so the list can re-expand the
        // node the editor came from. On a flat table it wedges the list instead: Sulu binds
        // `active` to the list store, persists it in the user's settings and puts it back in
        // the URL on every later visit, and `DefaultLoadingStrategy` then hands it to
        // `FlatStructureStrategy.clear()` as a parent id whenever the active row happens to
        // be on the page being shown. That throws, the response is dropped, and paging looks
        // dead - the URL changes and the table does not.
        self::assertNull($form->getOption('routerAttributesToBackView'));
        self::assertSame(TranslationsAdmin::LIST_VIEW, $form->getOption('backView'));
    }

    public function testTheConfigSaysWhetherCellsMayBeTypedIn(): void
    {
        $config = $this->admin()->getConfig();
        $readOnlyConfig = $this->admin(permissions: [PermissionTypes::VIEW])->getConfig();

        self::assertIsArray($config);
        self::assertIsArray($readOnlyConfig);

        // The list transformer reads this to decide between an input and plain text. Without
        // it a read-only user gets a cell that looks editable and answers 403 on blur.
        self::assertTrue($config['editable']);
        self::assertFalse($readOnlyConfig['editable']);
    }

    public function testTheListPaginatesAndSearches(): void
    {
        $list = $this->build()->get(TranslationsAdmin::LIST_VIEW)->getView();

        // Both default to on, so this asserts that nothing we add later quietly turns them
        // off - a list of thousands of keys without a pager is unusable, and it is the kind
        // of regression that only shows up in a browser.
        self::assertNotFalse($list->getOption('paginated') ?? true);
        self::assertTrue($list->getOption('searchable') ?? true);
    }

    public function testTheVersionsTabIsScopedToTheKeyButNotFrozenToOneLanguage(): void
    {
        $views = $this->build();
        $versions = $views->get(TranslationsAdmin::EDIT_FORM_VERSIONS_VIEW)->getView();

        // Only the id. Mapping `locale` here as well is the obvious thing to write and it
        // breaks the language switcher: `routerAttributesToListRequest` is read once in the
        // `List` constructor from a snapshot of the router attributes and lands in
        // `ListStore.options`, which the store merges as `{...observableOptions,
        // ...this.options}` - so the frozen value wins over the observable locale the
        // switcher writes to. The tab reloaded in the language it was opened in and the rows
        // only changed on a full page reload.
        self::assertSame(
            ['id' => 'translationKeyId'],
            $versions->getOption('routerAttributesToListRequest'),
        );
    }

    public function testTheDetailsTabShowsTheKeyAsItsHeading(): void
    {
        $views = $this->build();
        $details = $views->get(TranslationsAdmin::EDIT_FORM_DETAILS_VIEW)->getView();
        $tab = $views->get(TranslationsAdmin::EDIT_FORM_VIEW)->getView();

        // Sulu's `Form` view hides the resource tab's title unless it is asked for, while the
        // versions and activity tabs print it by default - so without this the heading
        // appeared and disappeared as the editor moved between tabs.
        self::assertTrue($details->getOption('titleVisible'));
        self::assertSame('translationKey', $tab->getOption('titleProperty'));
    }

    public function testWithoutViewPermissionThereAreNoViews(): void
    {
        $views = $this->build(permissions: []);

        self::assertFalse($views->has(TranslationsAdmin::LIST_VIEW));
        self::assertFalse($views->has(TranslationsAdmin::EDIT_FORM_VIEW));
    }

    public function testWithoutEditPermissionThereIsNoForm(): void
    {
        $views = $this->build(permissions: [PermissionTypes::VIEW]);

        self::assertTrue($views->has(TranslationsAdmin::LIST_VIEW));
        self::assertFalse($views->has(TranslationsAdmin::EDIT_FORM_VIEW));
    }

    public function testTranslatingTheSelectionIsOfferedOnlyWithAnEngineAndTheRightToEdit(): void
    {
        $types = fn (bool $machineTranslation, array $permissions) => \array_map(
            static fn (ToolbarAction $action) => $action->getType(),
            $this->build($permissions, $machineTranslation)->get(TranslationsAdmin::LIST_VIEW)
                ->getView()->getOption('toolbarActions'),
        );

        $all = [PermissionTypes::VIEW, PermissionTypes::ADD, PermissionTypes::EDIT, PermissionTypes::DELETE];

        self::assertContains('eekes_sulu_translations.translate_selection', $types(true, $all));

        // No engine: a button that can only fail.
        self::assertNotContains('eekes_sulu_translations.translate_selection', $types(false, $all));

        // It writes every row it translates, so read-only means no.
        self::assertNotContains('eekes_sulu_translations.translate_selection', $types(true, [PermissionTypes::VIEW]));
    }

    public function testTurningMachineTranslationOnAddsNothingToTheFormToolbar(): void
    {
        // It used to add `eekes_sulu_translations.translate_with_machine` here, and that took
        // the whole edit form down as soon as anybody configured a DeepL key: no JavaScript
        // ever registered the key with `formToolbarActionRegistry`, and Sulu's `Form` throws
        // on an unknown one while mounting. Machine translation on the form is the globe next
        // to the value field, not a button in the toolbar.
        $without = $this->build()->get(TranslationsAdmin::EDIT_FORM_DETAILS_VIEW)->getView();
        $with = $this->build(machineTranslation: true)->get(TranslationsAdmin::EDIT_FORM_DETAILS_VIEW)->getView();

        $types = static fn ($view) => \array_map(
            static fn (ToolbarAction $action) => $action->getType(),
            $view->getOption('toolbarActions'),
        );

        self::assertSame(['sulu_admin.save', 'sulu_admin.delete'], $types($without));
        self::assertSame($types($without), $types($with));
    }

    /**
     * @param list<string> $permissions
     */
    private function admin(
        array $permissions = [PermissionTypes::VIEW, PermissionTypes::ADD, PermissionTypes::EDIT, PermissionTypes::DELETE],
        bool $machineTranslation = false,
    ): TranslationsAdmin {
        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('hasPermission')->willReturnCallback(
            static fn ($subject, string $permission): bool => \in_array($permission, $permissions, true),
        );

        $webspaceManager = $this->createMock(WebspaceManagerInterface::class);
        $webspaceManager->method('getAllLocalesByWebspaces')->willReturn([
            'test' => [
                'en' => new Localization('en'),
                'nl' => new Localization('nl'),
                'fr' => new Localization('fr'),
                'de' => new Localization('de'),
            ],
        ]);

        return new TranslationsAdmin(
            new ViewBuilderFactory(),
            $securityChecker,
            new LocaleProvider($webspaceManager, [], 'en'),
            null,
            $machineTranslation,
        );
    }

    /**
     * @param list<string> $permissions
     */
    private function build(
        array $permissions = [PermissionTypes::VIEW, PermissionTypes::ADD, PermissionTypes::EDIT, PermissionTypes::DELETE],
        bool $machineTranslation = false,
    ): ViewCollection {
        $views = new ViewCollection();
        $this->admin($permissions, $machineTranslation)->configureViews($views);

        return $views;
    }
}

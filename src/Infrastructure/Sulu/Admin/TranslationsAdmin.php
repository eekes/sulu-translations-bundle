<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Sulu\Admin;

use Eekes\SuluTranslationsBundle\Application\Locale\LocaleProvider;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationVersion;
use Sulu\Bundle\ActivityBundle\Infrastructure\Sulu\Admin\View\ActivityViewBuilderFactoryInterface;
use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItem;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItemCollection;
use Sulu\Bundle\AdminBundle\Admin\View\ListItemAction;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactoryInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;

/**
 * Settings -> Translations.
 *
 * The one thing to understand here: `:locale` in the route plus `addLocales()` is what puts
 * Sulu's language dropdown top left, and it is the reason this bundle exists in the shape it
 * does. The list and the form both have it, so an editor can flip language while editing one
 * key and keep their place. See the `admin-ui` skill.
 */
class TranslationsAdmin extends Admin
{
    public const LIST_VIEW = 'eekes_sulu_translations.list';
    public const ADD_FORM_VIEW = 'eekes_sulu_translations.add_form';
    public const ADD_FORM_DETAILS_VIEW = 'eekes_sulu_translations.add_form.details';
    public const EDIT_FORM_VIEW = 'eekes_sulu_translations.edit_form';
    public const EDIT_FORM_DETAILS_VIEW = 'eekes_sulu_translations.edit_form.details';
    public const EDIT_FORM_VERSIONS_VIEW = 'eekes_sulu_translations.edit_form.versions';
    public const EDIT_FORM_ACTIVITY_VIEW = 'eekes_sulu_translations.edit_form.activity';

    public function __construct(
        private readonly ViewBuilderFactoryInterface $viewBuilderFactory,
        private readonly SecurityCheckerInterface $securityChecker,
        private readonly LocaleProvider $localeProvider,
        private readonly ?ActivityViewBuilderFactoryInterface $activityViewBuilderFactory = null,
        private readonly bool $machineTranslation = false,
    ) {
    }

    public function configureNavigationItems(NavigationItemCollection $navigationItemCollection): void
    {
        if (!$this->securityChecker->hasPermission(TranslationKey::SECURITY_CONTEXT, PermissionTypes::VIEW)) {
            return;
        }

        $item = new NavigationItem('eekes_sulu_translations.translations');
        $item->setPosition(45);
        $item->setView(static::LIST_VIEW);

        $navigationItemCollection->get(Admin::SETTINGS_NAVIGATION_ITEM)->addChild($item);
    }

    public function configureViews(ViewCollection $viewCollection): void
    {
        if (!$this->securityChecker->hasPermission(TranslationKey::SECURITY_CONTEXT, PermissionTypes::VIEW)) {
            return;
        }

        $locales = $this->localeProvider->getLocales();

        $viewCollection->add($this->createListView($locales));

        if ($this->securityChecker->hasPermission(TranslationKey::SECURITY_CONTEXT, PermissionTypes::ADD)) {
            $this->addAddViews($viewCollection, $locales);
        }

        if ($this->securityChecker->hasPermission(TranslationKey::SECURITY_CONTEXT, PermissionTypes::EDIT)) {
            $this->addEditViews($viewCollection, $locales);
        }
    }

    public function getSecurityContexts(): array
    {
        return [
            self::SULU_ADMIN_SECURITY_SYSTEM => [
                'Settings' => [
                    TranslationKey::SECURITY_CONTEXT => [
                        PermissionTypes::VIEW,
                        PermissionTypes::ADD,
                        PermissionTypes::EDIT,
                        PermissionTypes::DELETE,
                    ],
                    // Only `view`, and it means "may see and change these fields at all".
                    // Splitting it further would mean a matrix nobody can reason about, and
                    // there is nothing to add or delete about a key's origin anyway. See
                    // TranslationKey::ADMIN_SECURITY_CONTEXT for why this is a context of its
                    // own rather than a fifth permission type.
                    TranslationKey::ADMIN_SECURITY_CONTEXT => [
                        PermissionTypes::VIEW,
                    ],
                ],
            ],
        ];
    }

    public function getConfigKey(): ?string
    {
        return 'eekes_sulu_translations';
    }

    /**
     * Handed to the admin JavaScript: the list and the toolbar actions need to know which
     * optional features this installation actually has.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): ?array
    {
        return [
            'locales' => $this->localeProvider->getLocales(),
            'defaultLocale' => $this->localeProvider->getDefaultLocale(),
            'machineTranslation' => $this->machineTranslation,
            // Whether the cells in the list may be typed in at all. Without it a read-only
            // user gets an input that looks editable and answers 403 on blur, and the pencil
            // that hints at editing would be a lie.
            'editable' => $this->securityChecker->hasPermission(TranslationKey::SECURITY_CONTEXT, PermissionTypes::EDIT),
        ];
    }

    /**
     * @param list<string> $locales
     */
    private function createListView(array $locales): \Sulu\Bundle\AdminBundle\Admin\View\ListViewBuilderInterface
    {
        // Renders nothing. A toolbar action is the only hook Sulu offers that runs when a list
        // mounts, and this one drops the `active` attribute before the first request - see
        // `ClearActiveRowToolbarAction` and the note on the edit view below.
        $toolbarActions = [new ToolbarAction('eekes_sulu_translations.clear_active_row')];

        if ($this->securityChecker->hasPermission(TranslationKey::SECURITY_CONTEXT, PermissionTypes::ADD)) {
            $toolbarActions[] = new ToolbarAction('sulu_admin.add');
        }

        if ($this->securityChecker->hasPermission(TranslationKey::SECURITY_CONTEXT, PermissionTypes::DELETE)) {
            $toolbarActions[] = new ToolbarAction('sulu_admin.delete');
        }

        if ($this->securityChecker->hasPermission(TranslationKey::SECURITY_CONTEXT, PermissionTypes::EDIT)) {
            // Everything that operates on the current filter rather than on one row.
            $toolbarActions[] = new ToolbarAction('eekes_sulu_translations.bulk_actions', [
                'machineTranslation' => $this->machineTranslation,
            ]);
        }

        $toolbarActions[] = new ToolbarAction('eekes_sulu_translations.reference_locale', ['locales' => $locales]);

        // Translates the rows the editor ticked, one at a time, so they watch them fill in.
        // Dead until something is selected. The bulk run in the dropdown above does the same
        // work server-side for the whole filter and answers with a number - the right shape
        // for two thousand keys, the wrong one for the twelve in front of you.
        if ($this->machineTranslation
            && $this->securityChecker->hasPermission(TranslationKey::SECURITY_CONTEXT, PermissionTypes::EDIT)
        ) {
            $toolbarActions[] = new ToolbarAction('eekes_sulu_translations.translate_selection');
        }

        $view = $this->viewBuilderFactory
            ->createListViewBuilder(static::LIST_VIEW, '/translations/:locale')
            ->setResourceKey(TranslationKey::RESOURCE_KEY)
            ->setListKey(TranslationKey::LIST_KEY)
            ->setTitle('eekes_sulu_translations.translations')
            ->addListAdapters(['table'])
            ->addLocales($locales)
            ->setDefaultLocale($this->localeProvider->getDefaultLocale())
            ->addToolbarActions($toolbarActions)
            ->enableSearching();

        if ($this->securityChecker->hasPermission(TranslationKey::SECURITY_CONTEXT, PermissionTypes::ADD)) {
            $view->setAddView(static::ADD_FORM_VIEW);
        }

        if ($this->securityChecker->hasPermission(TranslationKey::SECURITY_CONTEXT, PermissionTypes::EDIT)) {
            $view->setEditView(static::EDIT_FORM_VIEW);
        }

        return $view;
    }

    /**
     * @param list<string> $locales
     */
    private function addAddViews(ViewCollection $viewCollection, array $locales): void
    {
        $viewCollection->add(
            $this->viewBuilderFactory
                ->createResourceTabViewBuilder(static::ADD_FORM_VIEW, '/translations/:locale/add')
                ->setResourceKey(TranslationKey::RESOURCE_KEY)
                ->addLocales($locales)
                ->setBackView(static::LIST_VIEW),
        );

        $viewCollection->add(
            $this->viewBuilderFactory
                ->createFormViewBuilder(static::ADD_FORM_DETAILS_VIEW, '/details')
                ->setResourceKey(TranslationKey::RESOURCE_KEY)
                ->setFormKey('translation_add')
                ->setTabTitle('sulu_admin.details')
                ->addToolbarActions([new ToolbarAction('sulu_admin.save')])
                ->setEditView(static::EDIT_FORM_VIEW)
                ->setParent(static::ADD_FORM_VIEW),
        );
    }

    /**
     * @param list<string> $locales
     */
    private function addEditViews(ViewCollection $viewCollection, array $locales): void
    {
        $formToolbarActions = [new ToolbarAction('sulu_admin.save')];

        if ($this->securityChecker->hasPermission(TranslationKey::SECURITY_CONTEXT, PermissionTypes::DELETE)) {
            $formToolbarActions[] = new ToolbarAction('sulu_admin.delete');
        }

        // Deliberately **no** machine-translation action here. It was one, and it took the
        // whole form down the moment `machine_translation.enabled` was turned on: nothing
        // ever registered `eekes_sulu_translations.translate_with_machine` with Sulu's
        // `formToolbarActionRegistry`, and an unknown key makes `Form.componentDidMount`
        // throw before the view renders. A form toolbar button is also the wrong shape for
        // it - it translates one field, from the reference language the translator picked,
        // and that belongs next to the field. It is the globe in `TranslationValue`.
        // The bulk version lives in the list toolbar, where it operates on a filter.

        $viewCollection->add(
            $this->viewBuilderFactory
                ->createResourceTabViewBuilder(static::EDIT_FORM_VIEW, '/translations/:locale/:id')
                ->setResourceKey(TranslationKey::RESOURCE_KEY)
                ->addLocales($locales)
                // Deliberately no `addRouterAttributesToBackView(['id' => 'active'])`. That
                // is the tree-list pattern: the back button hands the list the id it came
                // from so a page tree can re-expand it. This list is a flat table, and the
                // attribute is poison there - Sulu binds `active` to the list store, stores
                // it in the user's settings and puts it back in the URL on every later
                // visit. From then on `DefaultLoadingStrategy` passes it to
                // `FlatStructureStrategy.clear()` as a parent id whenever the active row is
                // on the page being shown, that throws, the response is dropped, and paging
                // looks dead: the URL changes and the table does not.
                ->setBackView(static::LIST_VIEW)
                ->setTitleProperty('translationKey'),
        );

        $viewCollection->add(
            $this->viewBuilderFactory
                ->createFormViewBuilder(static::EDIT_FORM_DETAILS_VIEW, '/details')
                ->setResourceKey(TranslationKey::RESOURCE_KEY)
                ->setFormKey(TranslationKey::FORM_KEY)
                ->setTabTitle('sulu_admin.details')
                // The key itself, as an `<h1>` above the fields. Sulu's `Form` view hides the
                // resource tab's title unless it is asked for, while the versions and activity
                // tabs next to it print it by default - so without this the heading appears
                // and disappears as the editor moves between tabs. It is also the one piece of
                // context the details form never shows otherwise: the key field is far enough
                // down that a long description scrolls it off the screen.
                ->setTitleVisible(true)
                ->addToolbarActions($formToolbarActions)
                ->setParent(static::EDIT_FORM_VIEW),
        );

        // "What did it say before, and put it back" - the question asked constantly about a
        // translation, because a wrong string is live on the site the moment it is saved.
        $viewCollection->add(
            $this->viewBuilderFactory
                ->createListViewBuilder(static::EDIT_FORM_VERSIONS_VIEW, '/versions')
                ->setResourceKey(TranslationVersion::RESOURCE_KEY)
                ->setListKey(TranslationVersion::LIST_KEY)
                ->setTabTitle('eekes_sulu_translations.versions')
                ->addListAdapters(['table'])
                // Only the id. `locale` must **not** be mapped here, however much it looks
                // like it belongs: `routerAttributesToListRequest` is read once, in the
                // `List` constructor, from a plain snapshot of the router attributes, and it
                // lands in `ListStore.options` - which the store merges as
                // `{...observableOptions, ...this.options}`, so the frozen value wins over
                // the observable `locale` Sulu binds to the language switcher. Flipping the
                // language then rewrote the URL, reloaded the list and asked the server for
                // the language the tab was opened in, so the rows never changed until the
                // page was reloaded. Dropping the mapping leaves the observable in place and
                // the request follows the switcher.
                ->addRouterAttributesToListRequest(['id' => 'translationKeyId'])
                ->addItemActions([new ListItemAction('eekes_sulu_translations.restore_version')])
                // The same set Sulu's own activity tab disables. Without `disableTabGap()`
                // in particular, this table sits visibly lower than the Activity one next to
                // it, which just looks broken.
                //
                // Not `skin: flat`, though the activity tab uses it: that skin drops the blue
                // header background, and the activity tab gets away with it only because it
                // hides its header entirely. This table shows its columns, so it keeps the
                // default `dark` skin and the header Sulu users expect.
                ->disableTabGap()
                ->disableSearching()
                ->disableSelection()
                ->disableColumnOptions()
                ->disableFiltering()
                ->setParent(static::EDIT_FORM_VIEW),
        );

        if (null !== $this->activityViewBuilderFactory && $this->activityViewBuilderFactory->hasActivityListPermission()) {
            $viewCollection->add(
                $this->activityViewBuilderFactory
                    ->createActivityListViewBuilder(
                        static::EDIT_FORM_ACTIVITY_VIEW,
                        '/activity',
                        TranslationKey::RESOURCE_KEY,
                    )
                    ->setTabTitle('sulu_admin.activity')
                    ->setParent(static::EDIT_FORM_VIEW),
            );
        }
    }
}

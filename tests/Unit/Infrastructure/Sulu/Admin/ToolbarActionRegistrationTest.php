<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Unit\Infrastructure\Sulu\Admin;

use Eekes\SuluTranslationsBundle\Application\Locale\LocaleProvider;
use Eekes\SuluTranslationsBundle\Infrastructure\Sulu\Admin\TranslationsAdmin;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\View;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactory;
use Sulu\Component\Localization\Localization;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;

/**
 * Every toolbar action the Admin class asks for has to exist in the registry that view reads.
 *
 * An unknown key is not a missing button: `Form.componentDidMount` and its list counterpart
 * *throw* on one, so the whole view stops rendering and the browser console is the only place
 * that says why. And the two registries are separate - a key registered with
 * `listToolbarActionRegistry` is just as unknown to a form as one nobody registered at all.
 *
 * This test exists because `eekes_sulu_translations.translate_with_machine` was added to the
 * edit form and never registered anywhere. It was invisible for as long as nobody configured
 * a machine translator, and it took the entire edit form down for the first person who did.
 */
class ToolbarActionRegistrationTest extends TestCase
{
    /**
     * Which registry a view type reads its toolbar actions from. Anything else - a resource
     * tab, an activity list - has no toolbar actions of its own.
     */
    private const REGISTRY_PER_VIEW_TYPE = [
        'sulu_admin.form' => 'formToolbarActionRegistry',
        'sulu_admin.list' => 'listToolbarActionRegistry',
    ];

    public function testEveryToolbarActionTheAdminAsksForIsRegistered(): void
    {
        $registered = [];

        foreach (self::REGISTRY_PER_VIEW_TYPE as $registry) {
            $registered[$registry] = $this->registeredKeys($registry);

            self::assertNotEmpty(
                $registered[$registry],
                \sprintf('No `%s` registrations were found, so this test proves nothing.', $registry),
            );
        }

        $checked = 0;

        foreach ($this->views() as $view) {
            $registry = self::REGISTRY_PER_VIEW_TYPE[$view->getType()] ?? null;

            if (null === $registry) {
                continue;
            }

            /** @var list<ToolbarAction> $actions */
            $actions = $view->getOption('toolbarActions') ?? [];

            foreach ($actions as $action) {
                self::assertContains(
                    $action->getType(),
                    $registered[$registry],
                    \sprintf(
                        '"%s" is a toolbar action of the %s view "%s", but nothing registers it with `%s`. '
                        . 'Sulu throws on an unknown key, so that view will not render at all.',
                        $action->getType(),
                        $view->getType(),
                        $view->getName(),
                        $registry,
                    ),
                );

                ++$checked;
            }
        }

        self::assertGreaterThan(0, $checked, 'No toolbar actions were checked, so this test proves nothing.');
    }

    /**
     * Every view the admin builds, in both the configurations that change which toolbar
     * actions it asks for. A feature that is off by default is exactly the one whose button
     * nobody notices is broken.
     *
     * @return list<View>
     */
    private function views(): array
    {
        $views = [];

        foreach ([false, true] as $machineTranslation) {
            $collection = new \Sulu\Bundle\AdminBundle\Admin\View\ViewCollection();

            $this->admin($machineTranslation)->configureViews($collection);

            foreach ($collection->all() as $builder) {
                $views[] = $builder->getView();
            }
        }

        return $views;
    }

    /**
     * The keys one registry is given anywhere in the admin JavaScript - Sulu's own bundles
     * and ours - read from the source rather than listed here, because a list would go stale
     * the first time Sulu adds an action and this test would start passing for the wrong
     * reason.
     *
     * Sulu's own jest files register `test1` and `test2` with the real registries, so the
     * test directories are skipped.
     *
     * @return list<string>
     */
    private function registeredKeys(string $registry): array
    {
        $root = \dirname(__DIR__, 5);
        $keys = [];

        foreach ([$root . '/assets/js', $root . '/vendor/sulu/sulu/src/Sulu/Bundle'] as $directory) {
            if (!\is_dir($directory)) {
                continue;
            }

            $files = new \RegexIterator(
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                ),
                '/\.js$/',
            );

            /** @var \SplFileInfo $file */
            foreach ($files as $file) {
                $path = \str_replace('\\', '/', $file->getPathname());

                if (\str_contains($path, '/tests/') || \str_contains($path, '/__tests__/')) {
                    continue;
                }

                \preg_match_all(
                    \sprintf('/\b%s\s*\.\s*add\(\s*[\'"]([^\'"]+)[\'"]/', \preg_quote($registry, '/')),
                    (string) \file_get_contents($file->getPathname()),
                    $matches,
                );

                foreach ($matches[1] as $key) {
                    $keys[] = $key;
                }
            }
        }

        return \array_values(\array_unique($keys));
    }

    private function admin(bool $machineTranslation): TranslationsAdmin
    {
        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $securityChecker->method('hasPermission')->willReturnCallback(
            static fn ($subject, string $permission): bool => \in_array($permission, [
                PermissionTypes::VIEW,
                PermissionTypes::ADD,
                PermissionTypes::EDIT,
                PermissionTypes::DELETE,
            ], true),
        );

        $webspaceManager = $this->createMock(WebspaceManagerInterface::class);
        $webspaceManager->method('getAllLocalesByWebspaces')->willReturn([
            'test' => ['en' => new Localization('en'), 'nl' => new Localization('nl')],
        ]);

        return new TranslationsAdmin(
            new ViewBuilderFactory(),
            $securityChecker,
            new LocaleProvider($webspaceManager, [], 'en'),
            null,
            $machineTranslation,
        );
    }
}

/* eslint-disable flowtype/require-valid-file-annotation */
/**
 * `sulu-admin-bundle` is a peer dependency, installed by the consuming project's admin build
 * and not by the bundle's own test run. The one class the toolbar actions extend does nothing
 * but keep its constructor arguments, so it is stubbed rather than pulled in.
 */
export default class AbstractListToolbarAction {
    constructor(listStore, list, router, locales, resourceStore, options) {
        this.listStore = listStore;
        this.list = list;
        this.router = router;
        this.locales = locales;
        this.resourceStore = resourceStore;
        this.options = options;
    }
}

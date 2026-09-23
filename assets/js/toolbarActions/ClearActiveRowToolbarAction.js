// @flow
import {action} from 'mobx';
import AbstractListToolbarAction from 'sulu-admin-bundle/views/List/toolbarActions/AbstractListToolbarAction';

/**
 * Not a button: it renders nothing. A toolbar action is the only hook Sulu gives a bundle that
 * runs when a list view mounts, and this list needs one thing done before its first request.
 *
 * **`active` is poison on a flat list.** It is the page tree's mechanism - "re-expand the node
 * I came from" - and Sulu wires it into every list regardless of the adapter: the List view
 * binds it to the router, `ListStore` persists it in the user's settings, and
 * `List.getDerivedRouteAttributes` puts it back into the URL on every later visit. Once it has
 * a value, `ListStore.sendRequest` hands it to `DefaultLoadingStrategy` as a **parent id**
 * whenever the row it names is on the page being shown, and `FlatStructureStrategy.clear()`
 * throws on a parent id. The request goes out, the server answers it correctly, and the
 * response is dropped on the floor - so paging looks dead: the URL changes and the table does
 * not.
 *
 * Nothing in this bundle sets it (see `TranslationsAdmin::addEditViews()`), but that is not
 * enough. A value that already reached a user - from an older version, a bookmark, a shared
 * link - copies itself between the URL and their settings forever and never expires. So it is
 * cleared on every mount rather than merely not written.
 */
export default class ClearActiveRowToolbarAction extends AbstractListToolbarAction {
    constructor(...args: Array<*>) {
        super(...args);

        this.clearActiveRow();
    }

    /**
     * One call clears all three copies: the observable the loading strategy reads, the `active`
     * query parameter (the router binding writes the URL back), and the stored user setting
     * (the store's own intercept writes that back).
     *
     * Deliberately not `reset()`: `active` is not part of what the list is showing, so
     * dropping it must not throw the editor back to page one.
     */
    @action clearActiveRow() {
        if (!this.listStore.active.get()) {
            return;
        }

        this.listStore.setActive(undefined);
    }

    getToolbarItemConfig() {
        return undefined;
    }
}

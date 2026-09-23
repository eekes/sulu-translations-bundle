/* eslint-disable flowtype/require-valid-file-annotation */
import {observable} from 'mobx';
import ClearActiveRowToolbarAction from '../toolbarActions/ClearActiveRowToolbarAction';

const listStore = (active) => ({
    active: observable.box(active),
    setActive: jest.fn(),
});

describe('ClearActiveRowToolbarAction', () => {
    it('drops an active row the list was handed', () => {
        // The one that breaks paging: Sulu passes it on as a parent id, and the flat structure
        // strategy throws on a parent id, which drops the response the table was waiting for.
        const store = listStore(97);

        new ClearActiveRowToolbarAction(store);

        expect(store.setActive).toHaveBeenCalledWith(undefined);
    });

    it('ignores the null a cleared setting reads back as', () => {
        // Once the setting has been cleared the profile endpoint hands back `null` rather than
        // nothing, and Sulu binds that to the store. It is falsy everywhere it matters - no
        // parent id, and the router leaves it out of the URL - so rewriting it would only cost
        // a settings request on every visit to the list.
        const store = listStore(null);

        new ClearActiveRowToolbarAction(store);

        expect(store.setActive).not.toHaveBeenCalled();
    });

    it('leaves a clean list alone', () => {
        // Writing undefined over undefined would push a pointless settings request on every
        // single visit to the list.
        const store = listStore(undefined);

        new ClearActiveRowToolbarAction(store);

        expect(store.setActive).not.toHaveBeenCalled();
    });

    it('renders nothing', () => {
        // It is not a button - it is the only mount hook Sulu gives a bundle.
        expect(new ClearActiveRowToolbarAction(listStore(undefined)).getToolbarItemConfig())
            .toBeUndefined();
    });
});

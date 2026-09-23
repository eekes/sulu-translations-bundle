// @flow
import React from 'react';
import {action, observable} from 'mobx';
import {translate} from 'sulu-admin-bundle/utils';
import {Dialog} from 'sulu-admin-bundle/components';
import AbstractListItemAction from 'sulu-admin-bundle/views/List/itemActions/AbstractListItemAction';
import {restoreVersion} from '../api/translations';

/**
 * Putting an old value back. Itself a change, so it appends a version of its own - undoing an
 * undo has to work, and the activity trail has to show that somebody did it.
 */
export default class RestoreVersionItemAction extends AbstractListItemAction {
    @observable version: ?Object = undefined;
    @observable running: boolean = false;

    @action handleClick = (item: Object) => {
        this.version = item;
    };

    @action handleCancel = () => {
        this.version = undefined;
    };

    @action handleConfirm = () => {
        if (!this.version) {
            return;
        }

        this.running = true;

        restoreVersion(this.version.id)
            .then(action(() => {
                this.running = false;
                this.version = undefined;
                this.listStore.reload();
            }))
            .catch(action(() => {
                this.running = false;
            }));
    };

    getNode() {
        if (!this.version) {
            return null;
        }

        return (
            <Dialog
                cancelText={translate('sulu_admin.cancel')}
                confirmLoading={this.running}
                confirmText={translate('eekes_sulu_translations.restore')}
                key="restore-version"
                onCancel={this.handleCancel}
                onConfirm={this.handleConfirm}
                open={true}
                title={translate('eekes_sulu_translations.restore')}
            >
                {translate('eekes_sulu_translations.restore_confirm')}
            </Dialog>
        );
    }

    getItemActionConfig(item: ?Object) {
        return {
            icon: 'su-process',
            onClick: item ? () => this.handleClick(item) : undefined,
            disabled: !item,
        };
    }
}

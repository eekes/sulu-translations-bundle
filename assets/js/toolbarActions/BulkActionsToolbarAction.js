// @flow
import React from 'react';
import {action, observable} from 'mobx';
import {translate} from 'sulu-admin-bundle/utils';
import {Dialog} from 'sulu-admin-bundle/components';
import AbstractListToolbarAction from 'sulu-admin-bundle/views/List/toolbarActions/AbstractListToolbarAction';
import {copyLocale, machineTranslate, searchAndReplace} from '../api/translations';
import translationStore from '../stores/translationStore';
import SearchReplaceForm from './SearchReplaceForm';
import CopyLocaleForm from './CopyLocaleForm';

/**
 * Everything that acts on more than one row.
 *
 * Each of them asks the server for a count first and shows it in the confirmation - a bulk
 * edit whose size you only learn afterwards is how a translation database gets wrecked in one
 * click.
 */
export default class BulkActionsToolbarAction extends AbstractListToolbarAction {
    @observable openDialog: ?string = undefined;
    @observable pendingCount: ?number = undefined;
    @observable running: boolean = false;
    @observable payload: Object = {};

    @action open = (dialog: string) => {
        this.openDialog = dialog;
        this.pendingCount = undefined;
        this.payload = {};
    };

    /**
     * The one dialog that has nothing to fill in, so it counts straight away: the editor is
     * told what is about to happen - how many rows, out of which language - instead of being
     * asked to press "Preview" to find out.
     */
    @action openMachine = () => {
        this.open('machine');

        const from = this.referenceLocale;

        if (from) {
            this.payload = {from};
        }

        this.preview(machineTranslate);
    };

    /**
     * The language the run translates *from*: the one the translator picked in the toolbar,
     * the same one the reference column and both globes use. Without one the server falls back
     * to `source_locale`, which is what the dialog then says.
     */
    get referenceLocale(): ?string {
        const reference = translationStore.referenceLocale;

        return reference && reference !== this.locale ? reference : undefined;
    }

    @action close = () => {
        this.openDialog = undefined;
        this.pendingCount = undefined;
        this.running = false;
    };

    @action setPayload = (payload: Object) => {
        this.payload = {...this.payload, ...payload};
        this.pendingCount = undefined;
    };

    get locale(): string {
        const locale = this.listStore.observableOptions.locale;

        return (locale && locale.get()) || translationStore.defaultLocale;
    }

    @action preview = (call: (payload: Object) => Promise<Object>) => {
        this.running = true;

        return call({...this.payload, locale: this.locale, dryRun: true})
            .then(action((response) => {
                this.running = false;
                this.pendingCount = response.count !== undefined ? response.count : response.translated;
            }))
            .catch(action(() => {
                this.running = false;
            }));
    };

    @action commit = (call: (payload: Object) => Promise<Object>) => {
        this.running = true;

        return call({...this.payload, locale: this.locale, dryRun: false})
            .then(action(() => {
                this.running = false;
                this.close();
                this.listStore.reload();
            }))
            .catch(action(() => {
                this.running = false;
            }));
    };

    getNode() {
        if (this.openDialog === 'search_replace') {
            return (
                <Dialog
                    align="left"
                    cancelText={translate('sulu_admin.cancel')}
                    confirmLoading={this.running}
                    confirmText={
                        this.pendingCount === undefined
                            ? translate('eekes_sulu_translations.preview')
                            : translate('sulu_admin.ok')
                    }
                    key="search-replace"
                    onCancel={this.close}
                    onConfirm={
                        this.pendingCount === undefined
                            ? () => this.preview(searchAndReplace)
                            : () => this.commit(searchAndReplace)
                    }
                    open={true}
                    title={translate('eekes_sulu_translations.search_replace')}
                >
                    <SearchReplaceForm count={this.pendingCount} onChange={this.setPayload} values={this.payload} />
                </Dialog>
            );
        }

        if (this.openDialog === 'copy_locale') {
            return (
                <Dialog
                    align="left"
                    cancelText={translate('sulu_admin.cancel')}
                    confirmLoading={this.running}
                    confirmText={
                        this.pendingCount === undefined
                            ? translate('eekes_sulu_translations.preview')
                            : translate('sulu_admin.ok')
                    }
                    key="copy-locale"
                    onCancel={this.close}
                    onConfirm={
                        this.pendingCount === undefined
                            ? () => this.preview(copyLocale)
                            : () => this.commit(copyLocale)
                    }
                    open={true}
                    title={translate('eekes_sulu_translations.copy_locale')}
                >
                    <CopyLocaleForm
                        count={this.pendingCount}
                        currentLocale={this.locale}
                        locales={translationStore.locales}
                        onChange={this.setPayload}
                        values={this.payload}
                    />
                </Dialog>
            );
        }

        if (this.openDialog === 'machine') {
            const counting = this.pendingCount === undefined;

            return (
                <Dialog
                    align="left"
                    cancelText={translate('sulu_admin.cancel')}
                    confirmDisabled={counting || 0 === this.pendingCount}
                    confirmLoading={this.running}
                    confirmText={translate('sulu_admin.ok')}
                    key="machine"
                    onCancel={this.close}
                    onConfirm={() => this.commit(machineTranslate)}
                    open={true}
                    title={translate('eekes_sulu_translations.translate_missing')}
                >
                    {counting && translate('eekes_sulu_translations.counting')}
                    {!counting && 0 === this.pendingCount && translate('eekes_sulu_translations.machine_nothing')}
                    {!counting && this.pendingCount > 0 && translate('eekes_sulu_translations.machine_confirm', {
                        count: this.pendingCount,
                        locale: this.referenceLocale || translationStore.sourceLocale,
                    })}
                </Dialog>
            );
        }

        return null;
    }

    getToolbarItemConfig() {
        const options = [
            {
                label: translate('eekes_sulu_translations.search_replace'),
                onClick: () => this.open('search_replace'),
            },
            {
                label: translate('eekes_sulu_translations.copy_locale'),
                onClick: () => this.open('copy_locale'),
            },
        ];

        if (this.options.machineTranslation || translationStore.machineTranslation) {
            options.push({
                label: translate('eekes_sulu_translations.translate_missing'),
                onClick: this.openMachine,
            });
        }

        return {
            type: 'dropdown',
            label: translate('eekes_sulu_translations.bulk_actions'),
            icon: 'su-bars',
            options,
        };
    }
}

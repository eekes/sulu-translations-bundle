// @flow
import {action} from 'mobx';
import {translate} from 'sulu-admin-bundle/utils';
import AbstractListToolbarAction from 'sulu-admin-bundle/views/List/toolbarActions/AbstractListToolbarAction';
import translationStore from '../stores/translationStore';

/**
 * Picks the language shown next to the one being edited.
 *
 * **How the parameter reaches the server.** A toolbar action cannot contribute request
 * parameters - `requestParameters` is a route option, read once by the List view, and
 * `AbstractListToolbarAction` has no `getRequestParameters()` hook at all. (An earlier
 * version of this class defined one; it was never called, so the reference column silently
 * never worked from the UI.)
 *
 * What does work is `ListStore.options`: it is observable and gets merged into every request
 * the store makes. So the choice is written there, once on construction and again whenever
 * the editor changes it.
 */
export default class ReferenceLocaleToolbarAction extends AbstractListToolbarAction {
    constructor(...args: Array<*>) {
        super(...args);

        // Before the first load, so the very first page already carries the column.
        this.applyToListStore(translationStore.referenceLocale, false);
    }

    @action applyToListStore(locale: ?string, reload: boolean = true) {
        const options = {...this.listStore.options};

        if (locale) {
            options.referenceLocale = locale;
        } else {
            delete options.referenceLocale;
        }

        this.listStore.options = options;

        if (reload) {
            this.listStore.reload();
        }
    }

    @action handleChange = (locale: ?string) => {
        translationStore.setReferenceLocale(locale);
        this.applyToListStore(locale);
    };

    getToolbarItemConfig() {
        const locales = (this.options.locales: any) || translationStore.locales;
        const current = translationStore.referenceLocale;

        return {
            type: 'dropdown',
            label: current
                ? `${translate('eekes_sulu_translations.reference_locale')}: ${current}`
                : translate('eekes_sulu_translations.no_reference'),
            icon: 'su-language',
            options: [
                {
                    label: translate('eekes_sulu_translations.no_reference'),
                    onClick: () => this.handleChange(undefined),
                },
                ...locales.map((locale) => ({
                    disabled: locale === current,
                    label: locale,
                    onClick: () => this.handleChange(locale),
                })),
            ],
        };
    }
}

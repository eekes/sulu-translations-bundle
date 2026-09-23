// @flow
import {action, computed, observable} from 'mobx';

const REFERENCE_LOCALE_STORAGE_KEY = 'eekes_sulu_translations.referenceLocale';

/**
 * The bits of state the list shares with its toolbar: which languages exist, which one is shown
 * next to the one being edited, and which optional features this installation has.
 *
 * The reference locale lives here and in localStorage rather than in the URL: it is a personal
 * preference of the translator, not part of what the page is showing, and a translator picks it
 * once and keeps it for months.
 */
class TranslationStore {
    @observable locales: Array<string> = [];
    @observable defaultLocale: string = 'en';
    @observable sourceLocale: string = 'en';
    @observable editable: boolean = true;
    @observable machineTranslation: boolean = false;
    @observable referenceLocale: ?string = undefined;

    @action setConfig(config: Object) {
        this.locales = config.locales || [];
        this.defaultLocale = config.defaultLocale || 'en';
        this.sourceLocale = config.sourceLocale || config.defaultLocale || 'en';
        this.machineTranslation = !!config.machineTranslation;
        this.editable = config.editable !== false;

        if (this.referenceLocale === undefined) {
            this.referenceLocale = this.readStoredReferenceLocale() || this.sourceLocale;
        }
    }

    @action setReferenceLocale(locale: ?string) {
        this.referenceLocale = locale;

        try {
            if (locale) {
                window.localStorage.setItem(REFERENCE_LOCALE_STORAGE_KEY, locale);
            } else {
                window.localStorage.removeItem(REFERENCE_LOCALE_STORAGE_KEY);
            }
        } catch (error) {
            // Private mode, blocked storage: the preference is simply not remembered.
        }
    }

    @computed get hasReferenceLocale(): boolean {
        return !!this.referenceLocale;
    }

    readStoredReferenceLocale(): ?string {
        try {
            return window.localStorage.getItem(REFERENCE_LOCALE_STORAGE_KEY);
        } catch (error) {
            return undefined;
        }
    }
}

export default new TranslationStore();
export {TranslationStore, REFERENCE_LOCALE_STORAGE_KEY};

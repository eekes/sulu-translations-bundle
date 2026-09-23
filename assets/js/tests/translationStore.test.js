/* eslint-disable flowtype/require-valid-file-annotation */
import {TranslationStore, REFERENCE_LOCALE_STORAGE_KEY} from '../stores/translationStore';

describe('translationStore', () => {
    beforeEach(() => {
        window.localStorage.clear();
    });

    it('takes its languages from the admin configuration', () => {
        const store = new TranslationStore();

        store.setConfig({locales: ['en', 'nl'], defaultLocale: 'nl', machineTranslation: true});

        // mobx wraps it in an observable array, which is not deep-equal to a plain one.
        expect(store.locales.slice()).toEqual(['en', 'nl']);
        expect(store.defaultLocale).toBe('nl');
        expect(store.machineTranslation).toBe(true);
    });

    it('defaults the reference language to the source language', () => {
        const store = new TranslationStore();

        store.setConfig({locales: ['en', 'nl'], defaultLocale: 'en'});

        expect(store.referenceLocale).toBe('en');
    });

    it('remembers what the translator picked', () => {
        const store = new TranslationStore();
        store.setConfig({locales: ['en', 'nl', 'fr'], defaultLocale: 'en'});

        store.setReferenceLocale('nl');

        expect(window.localStorage.getItem(REFERENCE_LOCALE_STORAGE_KEY)).toBe('nl');

        // A personal preference of the translator, not part of what the page is showing - so
        // it survives a reload rather than living in the URL.
        const reopened = new TranslationStore();
        reopened.setConfig({locales: ['en', 'nl', 'fr'], defaultLocale: 'en'});

        expect(reopened.referenceLocale).toBe('nl');
    });

    it('can be turned off', () => {
        const store = new TranslationStore();
        store.setConfig({locales: ['en', 'nl'], defaultLocale: 'en'});

        store.setReferenceLocale(undefined);

        expect(store.hasReferenceLocale).toBe(false);
    });

    it('survives storage being unavailable', () => {
        const store = new TranslationStore();
        const original = window.localStorage.setItem;

        window.localStorage.setItem = () => {
            throw new Error('Blocked in private mode');
        };

        expect(() => store.setReferenceLocale('fr')).not.toThrow();
        expect(store.referenceLocale).toBe('fr');

        window.localStorage.setItem = original;
    });
});

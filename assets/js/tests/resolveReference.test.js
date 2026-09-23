/* eslint-disable flowtype/require-valid-file-annotation */
import resolveReference from '../fields/resolveReference';
import translationStore from '../stores/translationStore';

function data(values) {
    return {values};
}

describe('resolveReference', () => {
    beforeEach(() => {
        translationStore.referenceLocale = undefined;
        translationStore.setConfig({locales: ['en', 'fr', 'nl'], defaultLocale: 'en', sourceLocale: 'en'});
    });

    it('shows the reference language the translator picked, not the source language', () => {
        // The bug this function exists for: editing English with the reference set to French
        // showed nothing at all, because the endpoint answered with the source locale and the
        // source locale was the one being edited.
        translationStore.setReferenceLocale('fr');

        const reference = resolveReference(data({en: 'Read more', fr: 'Lire la suite'}), 'en');

        expect(reference.locale).toBe('fr');
        expect(reference.value).toBe('Lire la suite');
    });

    it('falls back to the source language when nothing was picked', () => {
        translationStore.referenceLocale = undefined;

        const reference = resolveReference(data({en: 'Read more', fr: 'Lire la suite'}), 'fr');

        expect(reference.locale).toBe('en');
        expect(reference.value).toBe('Read more');
    });

    it('shows nothing when the reference is the language being edited', () => {
        translationStore.setReferenceLocale('fr');

        expect(resolveReference(data({fr: 'Lire la suite'}), 'fr')).toEqual({
            locale: undefined,
            value: undefined,
        });
    });

    it('shows nothing when that language has no value yet', () => {
        translationStore.setReferenceLocale('nl');

        // An untranslated reference is not a reference. The panel hides the row rather than
        // printing an empty one, and the machine-translation button hides with it - a run
        // from an empty string has nothing to translate.
        expect(resolveReference(data({en: 'Read more', nl: null}), 'en').value).toBeUndefined();
        expect(resolveReference(data({en: 'Read more', nl: ''}), 'en').value).toBeUndefined();
        expect(resolveReference(data({en: 'Read more'}), 'en').value).toBeUndefined();
    });

    it('survives a key whose values never arrived', () => {
        translationStore.setReferenceLocale('fr');

        expect(resolveReference(data({}), 'en').value).toBeUndefined();
    });
});

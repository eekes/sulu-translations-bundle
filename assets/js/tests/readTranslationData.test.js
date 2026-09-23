/* eslint-disable flowtype/require-valid-file-annotation */
import readTranslationData from '../fields/readTranslationData';

/**
 * A stand-in for Sulu's form store: JSON Pointer semantics, which is the whole point.
 * `jsonpointer.get(doc, '/')` addresses the property named "" - not the document.
 */
function formInspector(data) {
    return {
        getValueByPath(path) {
            if (path === '' ) {
                return data;
            }

            if (!path.startsWith('/')) {
                return undefined;
            }

            const key = path.slice(1);

            return Object.prototype.hasOwnProperty.call(data, key) ? data[key] : undefined;
        },
    };
}

const payload = {
    id: 48,
    translationKey: 'demo.read_more',
    domain: 'messages',
    icu: false,
    source: 'code',
    lastUsedAt: '2026-09-21T15:26:00+00:00',
    occurrences: [{path: 'templates/base.html.twig', line: 51}],
    values: {en: 'Read more', fr: 'Lire la suite', nl: null},
};

describe('readTranslationData', () => {
    it('reads every field the context panel shows', () => {
        const data = readTranslationData(formInspector(payload));

        expect(data.id).toBe(48);
        expect(data.translationKey).toBe('demo.read_more');
        expect(data.domain).toBe('messages');
        expect(data.source).toBe('code');
        expect(data.lastUsedAt).toBe('2026-09-21T15:26:00+00:00');
        expect(data.occurrences).toEqual([{path: 'templates/base.html.twig', line: 51}]);
        expect(data.values).toEqual({en: 'Read more', fr: 'Lire la suite', nl: null});
    });

    it('never asks for the root pointer', () => {
        // `/` is the property named "", not the document. Asking for it is how the panel
        // shipped blank, so the regression is pinned here rather than in a comment.
        const paths = [];
        const inspector = {
            getValueByPath(path) {
                paths.push(path);

                return undefined;
            },
        };

        readTranslationData(inspector);

        expect(paths).not.toContain('/');
        expect(paths).not.toContain('');
        expect(paths).toContain('/translationKey');
        expect(paths).toContain('/lastUsedAt');
    });

    it('survives a form inspector that has nothing', () => {
        expect(readTranslationData(undefined)).toEqual({
            domain: undefined,
            icu: false,
            id: undefined,
            lastUsedAt: undefined,
            occurrences: [],
            orphanedAt: undefined,
            placeholders: [],
            source: undefined,
            translationKey: undefined,
            values: {},
        });
    });

    it('always hands back an array of occurrences', () => {
        expect(readTranslationData(formInspector({})).occurrences).toEqual([]);
        expect(readTranslationData(formInspector({occurrences: null})).occurrences).toEqual([]);
    });

    it('always hands back a values map', () => {
        expect(readTranslationData(formInspector({})).values).toEqual({});
        expect(readTranslationData(formInspector({values: null})).values).toEqual({});
    });

    it('always hands back an array of placeholders', () => {
        // Same mobx 4 trap as the occurrences above: a guard on `Array.isArray` throws an
        // observable array away, and the chips would be empty for every key that has any.
        expect(readTranslationData(formInspector({})).placeholders).toEqual([]);
        expect(readTranslationData(formInspector({placeholders: null})).placeholders).toEqual([]);
        expect(readTranslationData(formInspector({placeholders: ['%name%']})).placeholders).toEqual(['%name%']);
    });

    it('reports icu as a boolean', () => {
        expect(readTranslationData(formInspector({icu: true})).icu).toBe(true);
        expect(readTranslationData(formInspector({})).icu).toBe(false);
    });
});

describe('readTranslationData with mobx observables', () => {
    // Sulu hands the form data over as mobx 4 observables, and a mobx 4 observable array is
    // not an array as far as `Array.isArray()` is concerned. Guarding on that threw the file
    // list away while the endpoint was returning five of them.
    const {observable} = require('mobx');

    function observableInspector(data) {
        const store = observable(data);

        return {
            getValueByPath(path) {
                const key = path.slice(1);

                return store[key];
            },
        };
    }

    it('turns an observable array of occurrences into a real array', () => {
        const data = readTranslationData(observableInspector({
            occurrences: [{path: 'templates/base.html.twig', line: 51}],
        }));

        expect(Array.isArray(data.occurrences)).toBe(true);
        expect(data.occurrences).toEqual([{path: 'templates/base.html.twig', line: 51}]);
    });

    it('turns an observable values map into a plain object', () => {
        // Same trap once more: an observable object is readable by key, but only after
        // `toJS` is it one the reference resolver can index into safely.
        const data = readTranslationData(observableInspector({
            values: {en: 'Read more', fr: 'Lire la suite'},
        }));

        expect(data.values).toEqual({en: 'Read more', fr: 'Lire la suite'});
        expect(data.values.fr).toBe('Lire la suite');
    });

    it('reads plain values off an observable too', () => {
        const data = readTranslationData(observableInspector({
            translationKey: 'demo.read_more',
            source: 'code',
            lastUsedAt: '2026-09-21T16:02:39+00:00',
        }));

        expect(data.translationKey).toBe('demo.read_more');
        expect(data.source).toBe('code');
        expect(data.lastUsedAt).toBe('2026-09-21T16:02:39+00:00');
    });
});

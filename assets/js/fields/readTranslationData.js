// @flow
import {toJS} from 'mobx';

/**
 * Reads the translation payload out of a Sulu form inspector, one pointer at a time.
 *
 * **Never `getValueByPath('/')`.** That is a JSON Pointer to the property whose key is the
 * empty string, not to the root document - the root is `''`. Sulu's store implements it with
 * `jsonpointer.has()`, so `'/'` quietly returns `undefined` and every field read off it comes
 * back empty. That is exactly how the Context panel shipped blank.
 *
 * Reading each field by its own pointer is also honest about what this component depends on
 * from the endpoint, which is the other reason to do it this way.
 *
 * **Everything goes through `toJS()`.** Sulu runs mobx 4, whose observable arrays are
 * array-*like* objects rather than real arrays: `Array.isArray(data.occurrences)` is `false`,
 * and any code that guards on it throws the list away without a word. That is precisely how
 * the "Used in" panel stayed empty while the endpoint was returning five files - the values
 * were there the whole time, and a truthiness check on an array that is not an array silently
 * discarded them.
 */
export type TranslationData = {
    domain: ?string,
    icu: boolean,
    id: ?number,
    lastUsedAt: ?string,
    occurrences: Array<Object>,
    orphanedAt: ?string,
    placeholders: Array<string>,
    source: ?string,
    translationKey: ?string,
    values: {[locale: string]: ?string},
};

export default function readTranslationData(formInspector: ?Object): TranslationData {
    const read = (name: string) => (formInspector ? toJS(formInspector.getValueByPath('/' + name)) : undefined);
    const occurrences = read('occurrences');
    const placeholders = read('placeholders');
    const values = read('values');

    return {
        domain: read('domain'),
        icu: !!read('icu'),
        id: read('id'),
        lastUsedAt: read('lastUsedAt'),
        occurrences: Array.isArray(occurrences) ? occurrences : [],
        orphanedAt: read('orphanedAt'),
        // Same mobx 4 trap as `occurrences`: an observable array is not an array, and a
        // guard on `Array.isArray` throws the list away without a word.
        placeholders: Array.isArray(placeholders) ? placeholders : [],
        source: read('source'),
        translationKey: read('translationKey'),
        // Every locale of this key, so `resolveReference` can show the one the translator
        // picked in the toolbar. `toJS` for the same reason as above: an observable map is
        // not a plain object, and reading a locale off it by key would come back empty.
        values: values && 'object' === typeof values ? values : {},
    };
}

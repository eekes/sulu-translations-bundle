/* eslint-disable flowtype/require-valid-file-annotation */
import {autorun, configure, observable} from 'mobx';
import {hasSource, isTranslated, needsTranslation, rowsNeedingTranslation, shouldStopRun, translateRow}
    from '../machine/rowTranslation';
import {saveValue, translateField} from '../api/translations';

jest.mock('../api/translations', () => ({
    saveValue: jest.fn(),
    translateField: jest.fn(),
}));

function row(overrides = {}) {
    return {id: 1, value: undefined, referenceValue: 'Read more', ...overrides};
}

describe('hasSource', () => {
    it('is what decides whether a row gets a globe at all', () => {
        expect(hasSource(row())).toBe(true);
        // Already translated is still translatable - the globe asks first and replaces it.
        expect(hasSource(row({value: 'Lees meer'}))).toBe(true);
    });

    it('refuses a row with nothing to translate from', () => {
        expect(hasSource(row({referenceValue: undefined}))).toBe(false);
        expect(hasSource(row({referenceValue: '   '}))).toBe(false);
        expect(hasSource(row({id: undefined}))).toBe(false);
        expect(hasSource(undefined)).toBe(false);
    });
});

describe('isTranslated', () => {
    it('is what decides whether the globe asks before writing', () => {
        expect(isTranslated(row({value: 'Lees meer'}))).toBe(true);
        expect(isTranslated(row())).toBe(false);
        expect(isTranslated(row({value: '   '}))).toBe(false);
    });
});

describe('needsTranslation', () => {
    it('takes a row that is empty and has something to translate from', () => {
        expect(needsTranslation(row())).toBe(true);
    });

    it('leaves a translation somebody already wrote alone', () => {
        // The list saves straight away and has no undo, so machine translation here fills
        // what is missing and never replaces what a person wrote.
        expect(needsTranslation(row({value: 'Lees meer'}))).toBe(false);
    });

    it('takes a translated row when the editor asked for overwriting', () => {
        expect(needsTranslation(row({value: 'Lees meer'}), true)).toBe(true);
    });

    it('still refuses a row with nothing to translate from, overwrite or not', () => {
        // Overwriting is about the target, never about conjuring a source.
        expect(needsTranslation(row({value: 'Lees meer', referenceValue: undefined}), true)).toBe(false);
        expect(needsTranslation(row({id: undefined}), true)).toBe(false);
    });

    it('treats whitespace as empty', () => {
        expect(needsTranslation(row({value: '   '}))).toBe(true);
    });

    it('skips a row with no reference text', () => {
        // Nothing to translate from: the endpoint would refuse it, and an editor would watch
        // a globe fail for a reason that is not on their screen.
        expect(needsTranslation(row({referenceValue: undefined}))).toBe(false);
        expect(needsTranslation(row({referenceValue: '  '}))).toBe(false);
    });

    it('skips a row without an id', () => {
        expect(needsTranslation(row({id: undefined}))).toBe(false);
        expect(needsTranslation(undefined)).toBe(false);
    });
});

describe('rowsNeedingTranslation', () => {
    it('keeps the order of the page, because that is the order the editor watches', () => {
        const rows = [
            row({id: 1}),
            row({id: 2, value: 'Al vertaald'}),
            row({id: 3}),
            row({id: 4, referenceValue: ''}),
        ];

        expect(rowsNeedingTranslation(rows).map((item) => item.id)).toEqual([1, 3]);
    });

    it('takes the translated rows too when overwriting, minus the ones with no source', () => {
        const rows = [
            row({id: 1}),
            row({id: 2, value: 'Al vertaald'}),
            row({id: 3}),
            row({id: 4, referenceValue: ''}),
        ];

        expect(rowsNeedingTranslation(rows, true).map((item) => item.id)).toEqual([1, 2, 3]);
    });

    it('survives a list that has not loaded yet', () => {
        expect(rowsNeedingTranslation(undefined)).toEqual([]);
    });
});

describe('shouldStopRun', () => {
    it('stops for the failures that will repeat on every remaining row', () => {
        expect(shouldStopRun({status: 401})).toBe(true);
        expect(shouldStopRun({status: 403})).toBe(true);
        expect(shouldStopRun({status: 429})).toBe(true);
        expect(shouldStopRun({status: 502})).toBe(true);
    });

    it('stops when there is no response at all', () => {
        // A network failure or an abort: the clearest "stop" there is.
        expect(shouldStopRun(undefined)).toBe(true);
        expect(shouldStopRun({})).toBe(true);
    });

    it('skips a row the server refused on its own merits', () => {
        // A dropped placeholder or an ICU message the write path will not take says nothing
        // about the other rows. Stopping there leaves the editor with "1 of 10" and no idea
        // which nine were fine.
        expect(shouldStopRun({status: 422})).toBe(false);
        expect(shouldStopRun({status: 404})).toBe(false);
        expect(shouldStopRun({status: 400})).toBe(false);
    });
});

describe('translateRow', () => {
    beforeEach(() => {
        translateField.mockReset();
        saveValue.mockReset();
    });

    it('asks for a suggestion, stores it through the write path and updates the row', async () => {
        translateField.mockResolvedValue({value: 'Lees meer'});
        saveValue.mockResolvedValue({value: 'Lees meer', translated: true});

        const item = row();

        await expect(translateRow(item, 'nl', 'en')).resolves.toBe('Lees meer');

        expect(translateField).toHaveBeenCalledWith({id: 1, locale: 'nl', from: 'en'});
        // The ordinary endpoint, so the row gets its version, its activity entry and the
        // placeholder validation a bulk run would skip.
        expect(saveValue).toHaveBeenCalledWith(1, 'nl', 'Lees meer');

        // Mutated on purpose: Sulu's table reads its cells off these observable rows.
        expect(item.value).toBe('Lees meer');
        expect(item.translated).toBe(true);
    });

    it('leaves the row untouched when the write is refused', async () => {
        translateField.mockResolvedValue({value: 'Lees meer'});
        saveValue.mockRejectedValue({context: {missing: ['%name%']}, status: 422});

        const item = row();

        await expect(translateRow(item, 'nl', 'en')).rejects.toEqual({context: {missing: ['%name%']}, status: 422});
        expect(item.value).toBeUndefined();
    });

    it('writes the row inside an action, because Sulu runs mobx in strict mode', async () => {
        // `configure({enforceActions: 'observed'})` is Sulu's, set in its admin entrypoint.
        // Writing to an observable something is watching throws outside an action, and a
        // production build cannot even say which invariant it was - the console gets "An
        // invariant failed, however the error is obfuscated because this is a production
        // build" and nothing more. A row on screen is watched by definition.
        translateField.mockResolvedValue({value: 'Lees meer'});
        saveValue.mockResolvedValue({value: 'Lees meer', translated: true});

        const item = observable({id: 1, referenceValue: 'Read more', translated: false, value: undefined});
        const seen = [];

        configure({enforceActions: 'observed'});

        // An autorun is what makes the row "observed" - without a watcher, strict mode has
        // nothing to complain about and this test would pass on the broken code too.
        const dispose = autorun(() => seen.push(item.value));

        try {
            await expect(translateRow(item, 'nl', 'en')).resolves.toBe('Lees meer');
        } finally {
            dispose();
            configure({enforceActions: 'never'});
        }

        expect(seen).toEqual([undefined, 'Lees meer']);
    });

    it('never saves when there is no suggestion to save', async () => {
        translateField.mockRejectedValue({status: 400, message: 'DeepL said no'});

        await expect(translateRow(row(), 'nl', 'en')).rejects.toEqual({status: 400, message: 'DeepL said no'});
        expect(saveValue).not.toHaveBeenCalled();
    });
});

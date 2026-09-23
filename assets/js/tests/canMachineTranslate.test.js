/* eslint-disable flowtype/require-valid-file-annotation */
import canMachineTranslate from '../fields/canMachineTranslate';

function data(overrides = {}) {
    return {id: 7, values: {}, ...overrides};
}

function reference(overrides = {}) {
    return {locale: 'en', value: 'Read more', ...overrides};
}

describe('canMachineTranslate', () => {
    it('offers the globe when there is a reference to translate and an engine to do it', () => {
        expect(canMachineTranslate(data(), reference(), {configured: true})).toBe(true);
    });

    it('stays away when no engine is configured', () => {
        // `machine_translation.service` is optional and most projects never set it. A button
        // that answers "machine translation is not configured" is worse than no button.
        expect(canMachineTranslate(data(), reference(), {configured: false})).toBe(false);
    });

    it('stays away when the field cannot be edited', () => {
        // A read-only domain or a missing permission: the suggestion would land in a box
        // nobody is allowed to save.
        expect(canMachineTranslate(data(), reference(), {configured: true, disabled: true})).toBe(false);
    });

    it('stays away on the add form, where the key has no id yet', () => {
        // The endpoint translates a stored key, so there is nothing to point it at.
        expect(canMachineTranslate(data({id: undefined}), reference(), {configured: true})).toBe(false);
    });

    it('stays away when the reference language has no text', () => {
        expect(canMachineTranslate(data(), reference({value: undefined}), {configured: true})).toBe(false);
    });

    it('stays away when there is no reference language at all', () => {
        // What `resolveReference` returns while editing the reference language itself: not a
        // language pair, so not something to send to a translator.
        expect(canMachineTranslate(data(), {locale: undefined, value: undefined}, {configured: true})).toBe(false);
    });
});

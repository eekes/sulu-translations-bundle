/* eslint-disable flowtype/require-valid-file-annotation */
jest.mock('sulu-admin-bundle/services', () => ({Requester: {}}), {virtual: true});
jest.mock(
    'sulu-admin-bundle/utils',
    () => ({
        // The real catalogues are not loaded here, so the stub hands back the key with its
        // parameters attached. That keeps the assertions about *which* message and *which*
        // placeholders, rather than about wording that belongs in the JSON files.
        translate: (key, parameters) => parameters
            ? `${key}(${Object.keys(parameters).map((name) => `${name}=${parameters[name]}`).join(',')})`
            : key,
    }),
    {virtual: true}
);

const {describeError} = require('../api/translations');

describe('describeError', () => {
    it('names the placeholders a 422 complained about', () => {
        const message = describeError({
            status: 422,
            message: 'The translation does not use the same placeholders.',
            context: {missing: ['%count%'], unexpected: ['%total%']},
        });

        expect(message).toContain('eekes_sulu_translations.placeholder_error');
        expect(message).toContain('%count%');
        expect(message).toContain('%total%');
    });

    it('does not blame the placeholders for anything else', () => {
        // The bug this guards: every failure used to be reported as a placeholder mismatch,
        // so a database a migration behind read as "you rewrote the variables".
        const message = describeError({
            status: 500,
            message: 'An exception occurred while executing a query.',
        });

        expect(message).toBe('An exception occurred while executing a query.');
        expect(message).not.toContain('placeholder');
    });

    it('still says something when the server said nothing', () => {
        expect(describeError({status: 502})).toBe('eekes_sulu_translations.save_failed');
        expect(describeError(undefined)).toBe('eekes_sulu_translations.save_failed');
    });

    it('ignores a detail that carries no placeholders', () => {
        // An ICU syntax refusal is a 422 with a `reason`, not with placeholder lists.
        const message = describeError({
            status: 422,
            message: 'The message is not valid ICU.',
            context: {reason: 'Unmatched brace'},
        });

        expect(message).toBe('The message is not valid ICU.');
    });
});

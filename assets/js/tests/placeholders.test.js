/* eslint-disable flowtype/require-valid-file-annotation */
import {inspect, missingIn, nameOf, namesIn} from '../fields/placeholders';

/**
 * These cases are the ones `PlaceholderExtractorTest` runs against the PHP side. The chips
 * decide what an editor sees and the server decides what it accepts, so the two have to agree
 * about what a placeholder is - and the only way to keep them agreeing is to ask them the
 * same questions.
 */
describe('namesIn', () => {
    it.each([
        ['Plain text', []],
        [undefined, []],
        ['', []],
        ['Hello %name%, you have %count% messages', ['name', 'count']],
        ['Hello {{ name }}', ['name']],
        ['Hello {name}', ['name']],
        ['{count, plural, one {# item} other {# items}}', ['count']],
        ['%user.name%', ['user.name']],
        ['%a% and %a%', ['a']],
        // An editor who rewrote a Symfony-style string as ICU has not broken anything.
        ['%count% of {total}', ['count', 'total']],
        ['Use { to open a block', []],
        ['100% done', []],
    ])('reads %p', (message, expected) => {
        expect(namesIn(message)).toEqual(expected);
    });
});

describe('nameOf', () => {
    it('reads the argument out of a stored token', () => {
        expect(nameOf('%name%')).toBe('name');
        expect(nameOf('{{ name }}')).toBe('name');
        expect(nameOf('{count}')).toBe('count');
        expect(nameOf('not a placeholder')).toBeUndefined();
    });
});

describe('inspect', () => {
    it('keeps the declared order and says which ones are there', () => {
        expect(inspect(['%name%', '{count}'], 'Bonjour %name%')).toEqual([
            {token: '%name%', name: 'name', present: true},
            {token: '{count}', name: 'count', present: false},
        ]);
    });

    it('accepts another notation for the same argument', () => {
        expect(inspect(['%count%'], 'Er zijn {count} berichten')[0].present).toBe(true);
    });

    it('has nothing to show for a key that declares nothing', () => {
        expect(inspect(undefined, 'Bonjour')).toEqual([]);
        expect(inspect([], 'Bonjour')).toEqual([]);
    });
});

describe('missingIn', () => {
    it('reports the token, because that is what an editor has to type', () => {
        expect(missingIn(['%name%', '{count}'], 'Bonjour {count}')).toEqual(['%name%']);
    });

    it('leaves an untranslated row alone', () => {
        // Most of the screen is empty rows, and marking all of them marks nothing.
        expect(missingIn(['%name%'], undefined)).toEqual([]);
        expect(missingIn(['%name%'], '')).toEqual([]);
    });
});

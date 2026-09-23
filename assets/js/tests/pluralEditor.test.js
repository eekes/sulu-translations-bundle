/* eslint-disable flowtype/require-valid-file-annotation */
import {assemble, categoriesFor, parse} from '../fields/icuPlural';

describe('parse', () => {
    it('reads a plain plural message', () => {
        expect(parse('{count, plural, one {# item} other {# items}}')).toEqual({
            argument: 'count',
            branches: {one: '# item', other: '# items'},
        });
    });

    it('tolerates whitespace and a different argument name', () => {
        expect(parse('{ apples , plural , one {one apple} other {# apples} }')).toEqual({
            argument: 'apples',
            branches: {one: 'one apple', other: '# apples'},
        });
    });

    it('starts an empty message off with an "other" branch', () => {
        expect(parse('')).toEqual({argument: 'count', branches: {other: ''}});
        expect(parse(null)).toEqual({argument: 'count', branches: {other: ''}});
    });

    it('refuses anything it cannot put back together', () => {
        // The field falls back to a plain text area for each of these. A half-complete ICU
        // editor that silently drops a branch is worse than no editor at all.
        expect(parse('Just a sentence')).toBeNull();
        expect(parse('{count, plural, =0 {none} other {# items}}')).toBeNull();
        expect(parse('{gender, select, male {he} other {they}}')).toBeNull();
        expect(parse('{count, plural, one {# item}')).toBeNull();
        expect(parse('{count, plural, one {# item} two {a {nested} thing}}')).toBeNull();
    });

    it('requires an "other" branch, which ICU does too', () => {
        expect(parse('{count, plural, one {# item}}')).toBeNull();
    });
});

describe('assemble', () => {
    it('writes the categories back in ICU order', () => {
        expect(assemble('count', {other: '# items', one: '# item'}))
            .toBe('{count, plural, one {# item} other {# items}}');
    });

    it('leaves out the optional categories the editor left empty', () => {
        expect(assemble('count', {one: '# item', few: '', other: '# items'}))
            .toBe('{count, plural, one {# item} other {# items}}');
    });

    it('always produces something valid', () => {
        expect(assemble('count', {})).toBe('{count, plural, other {}}');
    });

    it('keeps an empty `other`, because ICU and `parse` both insist on it', () => {
        // The bug this guards: filling `one` before `other` used to compose a message without
        // `other`, which `parse` refuses - so the editor collapsed into the raw text area
        // mid-word and left an invalid message behind for the server to reject.
        expect(assemble('count', {one: '# item', other: ''}))
            .toBe('{count, plural, one {# item} other {}}');
    });

    it('survives every order the branches can be filled in', () => {
        // Whatever the editor types next, what comes out has to be readable again - otherwise
        // the field the editor is typing into disappears underneath them.
        const categories = ['zero', 'one', 'two', 'few', 'many', 'other'];

        categories.forEach((category) => {
            const message = assemble('count', {[category]: 'something'});

            expect(parse(message)).not.toBeNull();
            expect(parse(message).branches[category]).toBe('something');
        });
    });

    it('round-trips', () => {
        const message = '{count, plural, one {# item} other {# items}}';
        const parsed = parse(message);

        expect(assemble(parsed.argument, parsed.branches)).toBe(message);
    });
});

describe('categoriesFor', () => {
    it('gives a language only the categories it has', () => {
        // Hardcoding a list would show Dutch editors four boxes they must leave empty, and
        // would hide the ones Polish actually needs.
        expect(categoriesFor('en')).toEqual(['one', 'other']);
        expect(categoriesFor('ja')).toEqual(['other']);
        expect(categoriesFor('pl')).toEqual(expect.arrayContaining(['one', 'few', 'many']));
    });
});

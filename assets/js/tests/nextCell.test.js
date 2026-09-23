/* eslint-disable flowtype/require-valid-file-annotation */
import nextCell from '../transformers/nextCell';

describe('nextCell', () => {
    const cells = ['a', 'b', 'c'];

    it('goes forwards and backwards', () => {
        expect(nextCell(cells, 'b', 1)).toBe('c');
        expect(nextCell(cells, 'b', -1)).toBe('a');
    });

    it('stops at the ends instead of wrapping around', () => {
        // An editor who tabs off the last row is done with the page. A cursor that reappears
        // at the top without warning types the wrong sentence into the wrong row.
        expect(nextCell(cells, 'c', 1)).toBeUndefined();
        expect(nextCell(cells, 'a', -1)).toBeUndefined();
    });

    it('gives up on a cell that is not on the page any more', () => {
        // Paging, sorting or a filter changed underneath the open editor.
        expect(nextCell(cells, 'z', 1)).toBeUndefined();
        expect(nextCell(cells, undefined, 1)).toBeUndefined();
        expect(nextCell([], 'a', 1)).toBeUndefined();
    });
});

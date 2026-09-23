// @flow

/**
 * The attribute every collapsed value cell carries, and the way one cell finds the next.
 *
 * **Why a DOM query and not a store.** While a cell is open its textarea lives in a portal on
 * `document.body`, so the browser's own tab order runs from there into whatever follows the
 * table - the native Tab key cannot reach the next row however the cells are wired. The cells
 * themselves are in the table in row order, though, which makes "the next one" exactly what it
 * looks like on screen. A registry of live components would have to be kept in step with
 * paging, sorting and filtering; `document.querySelectorAll` is in step by construction.
 */
export const CELL_ATTRIBUTE = 'data-translation-value-cell';

/**
 * The cell Tab should land on, or nothing at the ends of the page.
 *
 * Nothing rather than wrapping around: an editor who tabs off the last row is done with the
 * page, and a cursor that reappears at the top without warning saves the wrong sentence into
 * the wrong row.
 */
export default function nextCell<T>(cells: Array<T>, current: ?T, direction: number): ?T {
    if (!current) {
        return undefined;
    }

    const index = cells.indexOf(current);

    if (-1 === index) {
        return undefined;
    }

    return cells[index + direction];
}

// @flow
import {action} from 'mobx';
import {saveValue, translateField} from '../api/translations';

export type Row = {
    id?: number,
    referenceValue?: ?string,
    translated?: boolean,
    value?: ?string,
};

/**
 * Whether a row can be machine translated at all - which is what decides whether its globe is
 * drawn.
 *
 * **A reference sentence is not optional.** Without it there is nothing to translate *from*
 * and the endpoint answers "there is nothing to translate from in …" - an editor would watch
 * a globe fail for a reason that is not on their screen. It rides along on the row already,
 * for the reference language the translator picked, so the list knows what it can do without
 * asking the server.
 */
export function hasSource(row: ?Row): boolean {
    if (!row || !row.id) {
        return false;
    }

    const reference = row.referenceValue;

    return !!(reference && reference.trim());
}

/**
 * Whether this row already says something in the language being edited. Whitespace is not a
 * translation.
 */
export function isTranslated(row: ?Row): boolean {
    const value = row && row.value;

    return !!(value && value.trim());
}

/**
 * Whether a bulk pass picks the row up.
 *
 * **Empty rows only, unless the editor says otherwise.** A translation in the list is saved
 * the moment it comes back - no draft to read first, no undo to take it away again - so the
 * default is the rule every other machine run follows: fill what is missing, never replace
 * what a person wrote. `overwrite` is the editor deliberately asking for the other thing, in
 * a dialog that says how many rows it is about to replace.
 *
 * The per-row globe does not use this: it is offered on every row it *can* translate, and
 * asks before replacing that one row. One row in front of you with a question attached is a
 * different thing from a dozen going by while you watch.
 */
export function needsTranslation(row: ?Row, overwrite: boolean = false): boolean {
    if (!hasSource(row)) {
        return false;
    }

    return overwrite || !isTranslated(row);
}

/**
 * The rows of the current page that "Translate this page" would work through, in the order
 * they are on screen - which is the order the editor watches them fill in.
 */
export function rowsNeedingTranslation(rows: ?Array<Row>, overwrite: boolean = false): Array<Row> {
    if (!rows) {
        return [];
    }

    return rows.filter((row) => needsTranslation(row, overwrite));
}

/**
 * Whether a failure is about the whole run or only about this row.
 *
 * A run used to stop on the first error of any kind, on the reasoning that a refused key or an
 * exhausted quota fails identically on every remaining row. That is true of those, and wrong
 * about everything else: one message the write path will not take - a placeholder the engine
 * dropped, an ICU message it mangled - says nothing whatsoever about the other eleven rows,
 * and stopping there leaves the editor with "1 of 10" and no idea which nine were fine.
 *
 * So the ones that are about the connection or the account stop the run, and anything the
 * server decided about *this* row is skipped and counted. No status at all is a network
 * failure or an abort, which is the clearest "stop" there is.
 */
export function shouldStopRun(error: ?{status?: number}): boolean {
    const status = error && error.status;

    if (!status) {
        return true;
    }

    // 401 and 403: not signed in, or not allowed. 429: out of quota. 5xx: the engine or the
    // server is down. None of them get better on the next row.
    return 401 === status || 403 === status || 429 === status || status >= 500;
}

/**
 * Translate one row and store it.
 *
 * Two calls, deliberately: `translate-field` suggests and stores nothing, the ordinary write
 * endpoint stores. Going through the write path rather than a bulk endpoint is what gives the
 * row its version, its activity entry and its placeholder validation - a machine translation
 * that dropped a `%name%` is refused here exactly like a person's would be.
 *
 * The row is mutated with what came back, and that is the point: Sulu's table reads its cells
 * off these observable objects, so the value cell and the translated column redraw themselves
 * as each row lands.
 *
 * **The mutation has to be an `action`.** Sulu calls `configure({enforceActions: 'observed'})`
 * at startup, so writing to an observable that something is watching - which is exactly what
 * a row on screen is - throws outside one. In a production build mobx cannot even say which
 * invariant it was: the console gets "An invariant failed, however the error is obfuscated
 * because this is a production build" and nothing else. A `.then()` is its own microtask, so
 * an `action` wrapped around the caller's promise chain does not cover this one.
 */
export function translateRow(row: Row, locale: string, from: string): Promise<?string> {
    return translateField({id: row.id, locale, from})
        .then((suggestion) => saveValue(Number(row.id), locale, suggestion.value))
        .then(action((saved) => {
            row.value = saved.value;

            if (saved.translated !== undefined) {
                row.translated = saved.translated;
            }

            return saved.value;
        }));
}

// @flow

/**
 * The browser-side half of `PlaceholderExtractor`.
 *
 * It exists because the chips have to answer "is this one in the text yet" while the editor is
 * still typing, and a round trip per keystroke is not an answer. The server stays the
 * authority - it is the thing that refuses the save - and this only ever decides what the
 * chips look like.
 *
 * The two implementations are kept honest by their tests: `placeholders.test.js` runs the same
 * cases as `PlaceholderExtractorTest`, so a notation added on one side and forgotten on the
 * other fails there rather than in production.
 */

const NOTATIONS = [
    // `{{ name }}` first: the ICU pattern below would otherwise see the inner brace.
    /\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g,
    /%([a-zA-Z0-9_.]+)%/g,
    // ICU arguments: `{name}` or the head of `{name, plural, ...}`.
    /\{\s*([a-zA-Z0-9_]+)\s*[,}]/g,
];

/**
 * The argument names a message uses, whatever notation each one is written in. `%count%` and
 * `{count}` are the same argument - an editor who rewrote a Symfony-style string as ICU has
 * not broken anything.
 */
export function namesIn(message: ?string): Array<string> {
    if (!message) {
        return [];
    }

    const names = [];
    let remaining = message;

    NOTATIONS.forEach((pattern) => {
        remaining = remaining.replace(pattern, (match, name) => {
            if (names.indexOf(name) === -1) {
                names.push(name);
            }

            // Blanked, not removed: the next pattern must not see a brace this one has
            // already claimed, and the string has to keep its shape.
            return ' '.repeat(match.length);
        });
    });

    return names;
}

/**
 * The argument a stored token stands for: `%name%` -> `name`.
 */
export function nameOf(token: string): ?string {
    const names = namesIn(token);

    return names.length > 0 ? names[0] : undefined;
}

/**
 * One entry per placeholder the key declares, in the order it was declared, saying whether the
 * value in front of the editor carries it.
 */
export function inspect(placeholders: ?Array<string>, value: ?string): Array<Object> {
    if (!placeholders || placeholders.length === 0) {
        return [];
    }

    const used = namesIn(value);

    return placeholders.map((token) => {
        const name = nameOf(token);

        return {token, name, present: !!name && used.indexOf(name) !== -1};
    });
}

/**
 * Just the ones that are not in the text - what the row marker and the error line need.
 */
export function missingIn(placeholders: ?Array<string>, value: ?string): Array<string> {
    // An empty value is "not translated yet", not a broken translation, and marking every
    // untranslated row would mark most of the screen.
    if (!value) {
        return [];
    }

    return inspect(placeholders, value)
        .filter((placeholder) => !placeholder.present)
        .map((placeholder) => placeholder.token);
}

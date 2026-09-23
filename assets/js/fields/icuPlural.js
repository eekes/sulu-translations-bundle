// @flow

/**
 * Reading and writing an ICU `plural` message, with no React in sight.
 *
 * Separate from the component on purpose: this is the part that can be wrong in ways an
 * editor would only notice in production, so it is the part that gets tested exhaustively.
 *
 * Deliberately conservative. It handles a message that is exactly one `plural` argument and
 * nothing else; a nested argument, a `select`, an offset or an `=0` branch it cannot
 * round-trip makes `parse()` return null and the field falls back to the raw text area. A
 * half-complete ICU editor that silently drops a branch is worse than no editor at all.
 */
export const CATEGORY_ORDER = ['zero', 'one', 'two', 'few', 'many', 'other'];

export function parse(message: ?string): ?{argument: string, branches: {[string]: string}} {
    if (!message) {
        return {argument: 'count', branches: {other: ''}};
    }

    const head = message.match(/^\s*\{\s*([a-zA-Z0-9_]+)\s*,\s*plural\s*,/);

    if (!head) {
        return null;
    }

    let rest = message.slice(head[0].length);
    const branches = {};

    while (rest.length > 0) {
        const branch = rest.match(/^\s*(=\d+|zero|one|two|few|many|other)\s*\{/);

        if (!branch) {
            break;
        }

        // An `=0` branch is valid ICU and is not one of the six categories; refusing it is
        // what keeps the round trip honest.
        if (branch[1].startsWith('=')) {
            return null;
        }

        let depth = 1;
        let index = branch[0].length;

        while (index < rest.length && depth > 0) {
            if (rest[index] === '{') {
                depth++;
            } else if (rest[index] === '}') {
                depth--;
            }

            index++;
        }

        if (depth !== 0) {
            return null;
        }

        const body = rest.slice(branch[0].length, index - 1);

        if (body.indexOf('{') !== -1) {
            // A nested argument. Not something this editor can put back together.
            return null;
        }

        branches[branch[1]] = body;
        rest = rest.slice(index);
    }

    // ICU requires an `other` branch, and anything left over means we did not understand the
    // whole message.
    if (branches.other === undefined || rest.trim() !== '}') {
        return null;
    }

    return {argument: head[1], branches};
}

/**
 * **`other` is always written, empty or not.** ICU requires it and `parse()` refuses a message
 * without it, so dropping it when it happens to be empty makes the editor destroy itself: type
 * the `one` branch first, the composed message loses `other`, the next `parse()` returns null,
 * and the plural editor collapses into the raw text area halfway through a word - leaving an
 * invalid message behind that the server then refuses. Every other category is still dropped
 * when empty, because those are genuinely optional.
 */
export function assemble(argument: string, branches: {[string]: string}): string {
    const parts = CATEGORY_ORDER
        .filter((category) => 'other' === category || (branches[category] !== undefined && branches[category] !== ''))
        .map((category) => `${category} {${branches[category] || ''}}`);

    return `{${argument}, plural, ${parts.join(' ')}}`;
}

/**
 * The categories a language actually has. Polish has `few` and `many`, English has `one` and
 * `other`, Japanese has only `other` - a hardcoded list would show Dutch editors boxes they
 * must leave empty and hide the ones Polish needs.
 */
export function categoriesFor(locale: string): Array<string> {
    const found = {other: true};

    if (typeof Intl !== 'undefined' && Intl.PluralRules) {
        try {
            const rules = new Intl.PluralRules(locale);

            for (let value = 0; value <= 200; value++) {
                found[rules.select(value)] = true;
            }

            [0.5, 1.5, 2.5].forEach((value) => {
                found[rules.select(value)] = true;
            });
        } catch (error) {
            found.one = true;
        }
    } else {
        found.one = true;
    }

    return CATEGORY_ORDER.filter((category) => found[category]);
}

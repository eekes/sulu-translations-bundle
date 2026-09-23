// @flow

/**
 * Turns the flat `{path, line}` list the scanner stores into one entry per file.
 *
 * A key used three times in one template is three occurrences in the database - correct, and
 * unreadable in a panel. What an editor (and a developer looking for it) wants is the list of
 * *files*, with the lines behind each one.
 *
 * Sorted by path so the panel does not reshuffle between two loads of the same key.
 */
export type GroupedOccurrence = {
    lines: Array<number>,
    path: string,
};

export default function groupOccurrences(occurrences: ?Array<Object>): Array<GroupedOccurrence> {
    if (!Array.isArray(occurrences)) {
        return [];
    }

    const byPath = new Map();

    occurrences.forEach((occurrence) => {
        if (!occurrence || typeof occurrence.path !== 'string' || occurrence.path === '') {
            return;
        }

        if (!byPath.has(occurrence.path)) {
            byPath.set(occurrence.path, []);
        }

        const lines = byPath.get(occurrence.path);
        const line = Number(occurrence.line);

        // Line 0 is the scanner saying "somewhere in this file" - a path with no usable
        // number, which is worth listing but not worth printing a ":0" for.
        if (line > 0 && !lines.includes(line)) {
            lines.push(line);
        }
    });

    return [...byPath.entries()]
        .map(([path, lines]) => ({lines: lines.sort((a, b) => a - b), path}))
        .sort((a, b) => a.path.localeCompare(b.path));
}

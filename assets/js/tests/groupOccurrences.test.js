/* eslint-disable flowtype/require-valid-file-annotation */
import groupOccurrences from '../fields/groupOccurrences';

describe('groupOccurrences', () => {
    it('lists one entry per file with its lines', () => {
        expect(groupOccurrences([
            {path: 'templates/pages/default.html.twig', line: 17},
            {path: 'templates/base.html.twig', line: 51},
            {path: 'templates/pages/default.html.twig', line: 16},
            {path: 'src/Command/DemoTranslationsCommand.php', line: 58},
        ])).toEqual([
            {path: 'src/Command/DemoTranslationsCommand.php', lines: [58]},
            {path: 'templates/base.html.twig', lines: [51]},
            {path: 'templates/pages/default.html.twig', lines: [16, 17]},
        ]);
    });

    it('keeps templates and php files in one list', () => {
        const grouped = groupOccurrences([
            {path: 'src/Mailer.php', line: 12},
            {path: 'templates/mail.html.twig', line: 4},
        ]);

        expect(grouped.map((entry) => entry.path)).toEqual(['src/Mailer.php', 'templates/mail.html.twig']);
    });

    it('is stable between two loads of the same key', () => {
        const input = [
            {path: 'b.twig', line: 2},
            {path: 'a.twig', line: 9},
            {path: 'a.twig', line: 1},
        ];

        expect(groupOccurrences(input)).toEqual(groupOccurrences([...input].reverse()));
    });

    it('drops duplicate lines within a file', () => {
        expect(groupOccurrences([
            {path: 'a.twig', line: 5},
            {path: 'a.twig', line: 5},
        ])).toEqual([{path: 'a.twig', lines: [5]}]);
    });

    it('keeps a file the scanner could not give a line for', () => {
        // Line 0 means "somewhere in this file". The file is still worth listing.
        expect(groupOccurrences([{path: 'a.twig', line: 0}])).toEqual([{path: 'a.twig', lines: []}]);
    });

    it('survives nonsense', () => {
        expect(groupOccurrences(undefined)).toEqual([]);
        expect(groupOccurrences([])).toEqual([]);
        expect(groupOccurrences([{line: 3}, {path: ''}, null])).toEqual([]);
    });
});

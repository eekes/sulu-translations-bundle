// @flow
import React from 'react';
import {translate} from 'sulu-admin-bundle/utils';
import {inspect} from './placeholders';
import placeholderChips from './placeholderChips.scss';

/**
 * The placeholders a key declares, shown next to the box the translation is typed in.
 *
 * Before this existed the only way an editor learned that a sentence needs `%name%` was the
 * 422 they got after writing it without one. The list is stored on the key (see
 * `architecture`), so it is the same set in every language and it does not depend on which
 * reference language happens to be selected.
 *
 * A chip that is already in the text is quiet; one that is missing is marked, and clicking it
 * pastes it at the cursor. `onInsert` is optional - the plural editor has no single box to
 * paste into, and a button that does nothing when clicked is worse than a label.
 */
export default class PlaceholderChips extends React.Component<{
    disabled?: boolean,
    onInsert?: (token: string) => void,
    placeholders: ?Array<string>,
    value: ?string,
}> {
    render() {
        const {disabled, onInsert, placeholders, value} = this.props;
        const entries = inspect(placeholders, value);

        if (entries.length === 0) {
            return null;
        }

        return (
            <div className={placeholderChips.container}>
                <span className={placeholderChips.label}>{translate('eekes_sulu_translations.placeholders')}</span>
                {entries.map((entry) => {
                    const className = entry.present ? placeholderChips.chip : placeholderChips.chipMissing;
                    const title = translate(entry.present
                        ? 'eekes_sulu_translations.placeholder_present'
                        : 'eekes_sulu_translations.placeholder_missing');

                    if (!onInsert || disabled) {
                        return (
                            <span className={className} key={entry.token} title={title}>{entry.token}</span>
                        );
                    }

                    return (
                        <button
                            className={className}
                            key={entry.token}
                            /*
                                `onMouseDown`, not `onClick`: in the inline editor the textarea
                                saves on blur, and clicking a chip would commit the write
                                before the insert ever ran. Preventing the default keeps the
                                focus - and the cursor position - exactly where it was.
                            */
                            onMouseDown={(event) => {
                                event.preventDefault();
                                onInsert(entry.token);
                            }}
                            title={title}
                            type="button"
                        >
                            {entry.token}
                        </button>
                    );
                })}
            </div>
        );
    }
}

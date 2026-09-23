// @flow
import React from 'react';
import {translate} from 'sulu-admin-bundle/utils';
import {assemble, categoriesFor} from './icuPlural';
import pluralEditor from './pluralEditor.scss';

/**
 * One input per plural category instead of asking an editor to type
 * `{count, plural, one {...} other {...}}` by hand.
 *
 * The parsing and assembling live in `icuPlural.js`, which has no React in it and is where
 * the exhaustive tests are.
 */
export default class PluralEditor extends React.Component<Object> {
    handleChange = (category: string, event: SyntheticInputEvent<HTMLInputElement>) => {
        const {argument, branches, onChange} = this.props;

        onChange(assemble(argument, {...branches, [category]: event.currentTarget.value}));
    };

    render() {
        const {branches, disabled, locale} = this.props;

        return (
            <div className={pluralEditor.container}>
                {categoriesFor(locale).map((category) => (
                    <label className={pluralEditor.row} key={category}>
                        <span className={pluralEditor.label}>
                            {translate(`eekes_sulu_translations.plural_category.${category}`)}
                        </span>
                        <input
                            className={pluralEditor.input}
                            disabled={disabled}
                            onChange={(event) => this.handleChange(category, event)}
                            type="text"
                            value={branches[category] || ''}
                        />
                    </label>
                ))}
            </div>
        );
    }
}

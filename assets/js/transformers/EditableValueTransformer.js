// @flow
import React from 'react';
import InlineValueEditor from './InlineValueEditor';
import translationStore from '../stores/translationStore';

/**
 * A list field transformer gets the whole row as its third argument, which is what makes
 * editing in place possible at all: the cell knows which key it belongs to without the value
 * having to smuggle an id along inside the string.
 */
export default class EditableValueTransformer {
    locale: string;

    constructor(locale: () => string) {
        this.getLocale = locale;
    }

    getLocale: () => string;

    transform(value: *, parameters: Object, item: Object) {
        if (!item || !item.id) {
            return value;
        }

        // A read-only user gets the text and nothing else: an input that answers 403 on blur
        // is worse than no input, and the pencil would be promising something untrue.
        if (!translationStore.editable) {
            return value;
        }

        return (
            <InlineValueEditor
                domain={item.domain}
                id={item.id}
                key={`${item.id}-${this.getLocale()}`}
                locale={this.getLocale()}
                onSaved={(saved) => {
                    item.value = saved;
                }}
                placeholders={item.placeholders}
                value={value}
            />
        );
    }
}

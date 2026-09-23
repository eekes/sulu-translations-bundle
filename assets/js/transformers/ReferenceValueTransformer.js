// @flow
import React from 'react';
import ReferenceValueCell from './ReferenceValueCell';
import translationStore from '../stores/translationStore';

/**
 * The reference column: the same key in the language the translator picked in the toolbar.
 *
 * A transformer rather than a plain column because of the globe - and because the column has
 * no database field of its own. Its `field-name` points at the key's id and the controller
 * merges the real values in afterwards, so `value` here is whatever the controller put there,
 * which is `null` on every path that has no reference to show.
 */
export default class ReferenceValueTransformer {
    constructor(locale: () => string) {
        this.getLocale = locale;
    }

    getLocale: () => string;

    transform(value: *, parameters: Object, item: Object) {
        if (!item || !item.id || !value) {
            return value;
        }

        const locale = this.getLocale();

        return (
            <ReferenceValueCell
                from={translationStore.referenceLocale}
                key={`${item.id}-${locale}`}
                locale={locale}
                row={item}
            />
        );
    }
}

// @flow
import React from 'react';
import {translate} from 'sulu-admin-bundle/utils';
import {Checkbox, Form, SingleSelect} from 'sulu-admin-bundle/components';
import bulkActionsForm from './bulkActionsForm.scss';

/**
 * Sulu's own components rather than a bare `select` and `checkbox`, for the reason given in
 * `SearchReplaceForm`. `SingleSelect` shows "please choose" while nothing is picked, so there
 * is no placeholder option to hand-roll either.
 */
export default function CopyLocaleForm(props: Object) {
    const {count, currentLocale, locales, onChange, values} = props;

    return (
        <div>
            <Form>
                <Form.Field label={translate('eekes_sulu_translations.copy_from')} required={true}>
                    <SingleSelect onChange={(value) => onChange({from: value})} value={values.from}>
                        {locales.filter((locale) => locale !== currentLocale).map((locale) => (
                            <SingleSelect.Option key={locale} value={locale}>{locale}</SingleSelect.Option>
                        ))}
                    </SingleSelect>
                </Form.Field>

                <Form.Field>
                    <Checkbox
                        checked={!!values.overwrite}
                        onChange={(checked) => onChange({overwrite: checked})}
                    >
                        {translate('eekes_sulu_translations.overwrite')}
                    </Checkbox>
                </Form.Field>
            </Form>

            {count !== undefined &&
                <p className={bulkActionsForm.count}>{translate('eekes_sulu_translations.matches_found', {count})}</p>
            }
        </div>
    );
}

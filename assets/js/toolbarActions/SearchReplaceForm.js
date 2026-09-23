// @flow
import React from 'react';
import {translate} from 'sulu-admin-bundle/utils';
import {Form, Input} from 'sulu-admin-bundle/components';
import bulkActionsForm from './bulkActionsForm.scss';

/**
 * Sulu's own `Form` and `Input`, not bare HTML elements: a dialog that drops the admin's field
 * styling for browser defaults looks like a half-finished feature, whatever it does.
 *
 * `Form.Field` is what supplies the label, the spacing and the required marker - the same
 * chrome every other field in the admin has - so nothing here styles anything itself.
 */
export default function SearchReplaceForm(props: Object) {
    const {count, onChange, values} = props;

    return (
        <div>
            <Form>
                <Form.Field label={translate('eekes_sulu_translations.search')} required={true}>
                    <Input
                        onChange={(value) => onChange({search: value})}
                        value={values.search}
                    />
                </Form.Field>

                {/*
                    Not required: emptying a string everywhere is a legitimate thing to want,
                    and refusing it would send the editor to forty rows by hand.
                */}
                <Form.Field label={translate('eekes_sulu_translations.replace')}>
                    <Input
                        onChange={(value) => onChange({replace: value})}
                        value={values.replace}
                    />
                </Form.Field>
            </Form>

            {count !== undefined &&
                <p className={bulkActionsForm.count}>{translate('eekes_sulu_translations.matches_found', {count})}</p>
            }
        </div>
    );
}

// @flow
import React from 'react';
import {observer} from 'mobx-react';
import {action, observable} from 'mobx';
import {translate} from 'sulu-admin-bundle/utils';
import {Dialog, Icon} from 'sulu-admin-bundle/components';
import icuHelp from './icuHelp.scss';

/**
 * The "what is this?" next to the plural editor.
 *
 * Sat on the field itself rather than in the README, because the person who needs it is an
 * editor looking at four boxes labelled One and Other and wondering why. A tooltip is too
 * small for a worked example, so it is a small dialog: one sentence, one example, one warning
 * about the `#`.
 */
@observer
class IcuHelp extends React.Component<{}> {
    @observable open: boolean = false;

    @action show = () => {
        this.open = true;
    };

    @action hide = () => {
        this.open = false;
    };

    render() {
        return (
            <React.Fragment>
                <button
                    aria-label={translate('eekes_sulu_translations.icu_help')}
                    className={icuHelp.trigger}
                    onClick={this.show}
                    type="button"
                >
                    <Icon name="su-info-circle" /> {translate('eekes_sulu_translations.icu_help')}
                </button>

                {/*
                    Left, not Sulu's centred default: this dialog is a worked example with a
                    table in it, and centred prose with a centred table under it reads as a
                    layout accident.
                */}
                <Dialog
                    align="left"
                    cancelText={undefined}
                    confirmText={translate('sulu_admin.ok')}
                    onConfirm={this.hide}
                    open={this.open}
                    title={translate('eekes_sulu_translations.icu_help')}
                >
                    <div className={icuHelp.body}>
                        <p>{translate('eekes_sulu_translations.icu_help_intro')}</p>

                        <table className={icuHelp.example}>
                            <tbody>
                                <tr>
                                    <th>{translate('eekes_sulu_translations.plural_category.one')}</th>
                                    <td><code># item in your cart</code></td>
                                </tr>
                                <tr>
                                    <th>{translate('eekes_sulu_translations.plural_category.other')}</th>
                                    <td><code># items in your cart</code></td>
                                </tr>
                            </tbody>
                        </table>

                        <p>{translate('eekes_sulu_translations.icu_help_hash')}</p>
                        <p>{translate('eekes_sulu_translations.icu_help_categories')}</p>
                        <p className={icuHelp.note}>{translate('eekes_sulu_translations.icu_help_raw')}</p>
                    </div>
                </Dialog>
            </React.Fragment>
        );
    }
}

export default IcuHelp;

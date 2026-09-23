// @flow
import React from 'react';
import {observer} from 'mobx-react';
import {action, observable} from 'mobx';
import {translate} from 'sulu-admin-bundle/utils';
import {Dialog, Icon} from 'sulu-admin-bundle/components';
import Tooltip from 'sulu-admin-bundle/components/Tooltip';
import {describeError} from '../api/translations';
import {hasSource, isTranslated, translateRow} from '../machine/rowTranslation';
import translationStore from '../stores/translationStore';
import referenceValueCell from './referenceValueCell.scss';

/**
 * The reference language's sentence, with the globe that turns it into this row's translation.
 *
 * The globe lives here rather than in the value cell because this is the text it translates:
 * the editor reads the English, decides it is fine, and clicks the globe at the end of the
 * line they just read. The value cell is a box to type in and already has a pencil in it.
 *
 * Unlike the form, this one **saves**: a list has no draft to read first and nothing to press
 * save on afterwards. So the globe is offered on every row it can translate, and a row that
 * already says something gets a question before it is replaced - the old text is kept as a
 * version either way, which is what the dialog tells the editor. An empty row is written
 * straight away, because there is nothing to lose and a confirmation on every one of forty
 * empty rows is a dialog the editor learns to dismiss without reading.
 */
@observer
class ReferenceValueCell extends React.Component<{
    from: ?string,
    locale: string,
    row: Object,
}> {
    @observable translating: boolean = false;
    @observable confirming: boolean = false;
    @observable error: ?string = undefined;

    get canTranslate(): boolean {
        const {from, locale} = this.props;

        return translationStore.machineTranslation
            && translationStore.editable
            && !!from
            && from !== locale
            && hasSource(this.props.row);
    }

    @action handleClick = (event: SyntheticEvent<HTMLButtonElement>) => {
        // The row is a link to the form in most of Sulu's lists; a click on the globe is not
        // a click on the row.
        event.stopPropagation();

        if (isTranslated(this.props.row)) {
            this.confirming = true;

            return;
        }

        this.translate();
    };

    @action handleCancel = () => {
        this.confirming = false;
    };

    @action translate = () => {
        const {from, locale, row} = this.props;

        this.confirming = false;
        this.translating = true;
        this.error = undefined;

        translateRow(row, locale, String(from))
            .then(action(() => {
                this.translating = false;
            }))
            .catch(action((error) => {
                this.translating = false;
                this.error = describeError(error);
            }));
    };

    /**
     * Only for a row that already says something. The current text goes into the history on
     * the way out - the write path versions the previous value - so this is a question about
     * inconvenience, not about losing work, and it says so.
     */
    renderConfirmation() {
        if (!this.confirming) {
            return null;
        }

        return (
            <Dialog
                align="left"
                cancelText={translate('sulu_admin.cancel')}
                confirmText={translate('sulu_admin.ok')}
                key="translate-row"
                onCancel={this.handleCancel}
                onConfirm={this.translate}
                open={true}
                title={translate('eekes_sulu_translations.translate_row_confirm')}
            >
                {translate('eekes_sulu_translations.translate_row_confirm_text', {locale: this.props.from})}
            </Dialog>
        );
    }

    renderGlobe() {
        if (!this.canTranslate) {
            return null;
        }

        const label = this.translating
            ? translate('eekes_sulu_translations.translating')
            : translate('eekes_sulu_translations.translate_from', {locale: this.props.from});

        return (
            <Tooltip label={label}>
                <button
                    aria-label={label}
                    className={referenceValueCell.globe}
                    disabled={this.translating}
                    onClick={this.handleClick}
                    type="button"
                >
                    <Icon name={this.translating ? 'su-sync' : 'su-globe'} />
                </button>
            </Tooltip>
        );
    }

    render() {
        const {from, row} = this.props;

        return (
            <span className={referenceValueCell.container}>
                {/*
                    The error sits in the cell and stays there. A toast for row 34 of a page
                    of 50 says nothing about which row it was talking about.
                */}
                {this.error
                    ? <span className={referenceValueCell.error} title={this.error}>{this.error}</span>
                    : <span className={referenceValueCell.text} lang={from}>{row.referenceValue}</span>
                }
                {this.renderGlobe()}
                {this.renderConfirmation()}
            </span>
        );
    }
}

export default ReferenceValueCell;

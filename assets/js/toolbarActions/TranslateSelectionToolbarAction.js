// @flow
import React from 'react';
import {action, computed, observable} from 'mobx';
import {translate} from 'sulu-admin-bundle/utils';
import {Checkbox, Dialog, Form} from 'sulu-admin-bundle/components';
import AbstractListToolbarAction from 'sulu-admin-bundle/views/List/toolbarActions/AbstractListToolbarAction';
import {describeError} from '../api/translations';
import {rowsNeedingTranslation, shouldStopRun, translateRow} from '../machine/rowTranslation';
import translationStore from '../stores/translationStore';

/**
 * Translates the rows the editor ticked, one at a time.
 *
 * **The selection decides the scope, and the button says so by being dead until there is
 * one.** It used to take whatever was on the page, which reads as an invitation to translate
 * things nobody looked at - a filter that is one click away from "everything" and a button
 * that quietly acts on all of it. Ticking rows is the editor stating exactly which sentences a
 * machine may write, and Sulu's list already has the checkboxes.
 *
 * **Sequential on purpose.** The bulk run in the dropdown does the same work server-side and
 * answers with a number, which is the right shape for two thousand keys and the wrong one for
 * the twelve in front of you: a spinner, then a table that changed all at once. Here every row
 * is its own request and redraws its cell the moment it lands - `translateRow` writes the
 * value back onto the observable row Sulu's table renders from. Watching it happen *is* the
 * feature, and it means every row goes through the ordinary write path, with its version, its
 * activity entry and its placeholder validation.
 *
 * A row the server refuses - a placeholder the engine dropped, a message the write path will
 * not take - is counted and skipped; only a failure that will repeat on every remaining row
 * stops the run. The dialog stays open afterwards with what was left behind.
 *
 * Empty rows only, unless the editor ticks the box in the confirmation. That box is the one
 * place in the bundle where a machine may replace a sentence a person wrote, which is why it
 * sits next to a count of exactly how many, resets itself after every run, and is nowhere
 * near the per-row globe.
 */
export default class TranslateSelectionToolbarAction extends AbstractListToolbarAction {
    @observable confirming: boolean = false;
    @observable running: boolean = false;
    @observable overwrite: boolean = false;
    @observable done: number = 0;
    @observable failed: number = 0;
    @observable total: number = 0;
    @observable firstFailure: ?string = undefined;
    @observable error: ?string = undefined;

    get locale(): string {
        const locale = this.listStore.observableOptions.locale;

        return (locale && locale.get()) || translationStore.defaultLocale;
    }

    get referenceLocale(): ?string {
        const reference = translationStore.referenceLocale;

        // The same rule the reference column and the form's globe follow: the language being
        // edited is not a language pair.
        return reference && reference !== this.locale ? reference : undefined;
    }

    /**
     * The ticked rows - the very objects the table renders, so translating one shows up in
     * the cell without a reload.
     */
    @computed get selected(): Array<Object> {
        return this.listStore.selections ? this.listStore.selections.slice() : [];
    }

    /**
     * Computed rather than counted once when the dialog opens: the checkbox changes which of
     * the ticked rows are in scope, and a confirmation that still says "12" after the editor
     * asked for existing translations to be replaced is worse than no number at all.
     */
    @computed get rows(): Array<Object> {
        return rowsNeedingTranslation(this.selected, this.overwrite);
    }

    @action open = () => {
        this.confirming = true;
        this.error = undefined;
        this.firstFailure = undefined;
        this.done = 0;
        this.failed = 0;
        this.total = 0;
    };

    @action close = () => {
        this.confirming = false;
        this.running = false;
        this.error = undefined;
        // Never remembered between runs. Replacing what a translator wrote is something to
        // decide each time, in front of the count it applies to.
        this.overwrite = false;
    };

    @action setOverwrite = (overwrite: boolean) => {
        this.overwrite = overwrite;
    };

    /**
     * One row after the other, never in parallel: a dozen rows fired at once is a dozen DeepL
     * requests in one breath, landing in whatever order the network felt like.
     */
    @action run = () => {
        const rows = this.rows;
        const from = this.referenceLocale;

        if (!from || 0 === rows.length) {
            this.close();

            return;
        }

        this.running = true;
        this.done = 0;
        this.failed = 0;
        this.firstFailure = undefined;
        this.total = rows.length;

        const next = (index: number) => {
            if (index >= rows.length) {
                this.stop();

                return;
            }

            translateRow(rows[index], this.locale, from)
                .then(action(() => {
                    ++this.done;
                    next(index + 1);
                }))
                .catch(action((error) => {
                    const message = describeError(error);

                    // Only the failures that will repeat on every remaining row stop the run;
                    // a row the write path refused on its own merits is counted and left
                    // behind. See `shouldStopRun`.
                    if (shouldStopRun(error)) {
                        this.running = false;
                        this.error = translate('eekes_sulu_translations.translate_selection_failed', {
                            done: this.done,
                            count: this.total,
                            message,
                        });

                        return;
                    }

                    ++this.failed;
                    this.firstFailure = this.firstFailure || message;
                    next(index + 1);
                }));
        };

        next(0);
    };

    /**
     * The end of a run. A clean one closes the dialog and gets out of the way; one that left
     * rows behind stays open with what happened, because "8 of 10" is the only place the
     * editor will ever be told which two still need them.
     */
    @action stop = () => {
        this.running = false;

        if (this.failed > 0) {
            this.error = translate('eekes_sulu_translations.translate_selection_partial', {
                done: this.done,
                count: this.total,
                failed: this.failed,
                message: this.firstFailure || '',
            });

            return;
        }

        this.confirming = false;
        this.overwrite = false;
    };

    getNode() {
        if (!this.confirming) {
            return null;
        }

        const from = this.referenceLocale;
        const pending = this.rows.length;

        return (
            <Dialog
                align="left"
                cancelText={translate(this.error ? 'sulu_admin.close' : 'sulu_admin.cancel')}
                confirmDisabled={!!this.error || !from || (0 === pending && !this.running)}
                confirmLoading={this.running}
                confirmText={translate('sulu_admin.ok')}
                key="translate-selection"
                onCancel={this.close}
                onConfirm={this.run}
                open={true}
                title={translate('eekes_sulu_translations.translate_selection')}
            >
                <p>
                    {this.error
                        || (this.running && translate('eekes_sulu_translations.translate_selection_progress', {
                            done: this.done,
                            count: this.total,
                        }))
                        || (pending > 0 && from
                            ? translate(this.overwrite
                                ? 'eekes_sulu_translations.translate_selection_confirm_all'
                                : 'eekes_sulu_translations.translate_selection_confirm', {count: pending, locale: from})
                            : translate('eekes_sulu_translations.translate_selection_nothing'))
                    }
                </p>

                {/*
                    Sulu's own `Checkbox` in a `Form.Field`, never a bare input - a dialog with
                    browser-default controls in the middle of the admin reads as half finished
                    whatever it does.

                    Gone while the run is going: it decides which rows the run picked up, and
                    that was decided when the editor pressed OK.
                */}
                {!this.running && !this.error &&
                    <Form>
                        <Form.Field>
                            <Checkbox checked={this.overwrite} onChange={this.setOverwrite}>
                                {translate('eekes_sulu_translations.overwrite')}
                            </Checkbox>
                        </Form.Field>
                    </Form>
                }
            </Dialog>
        );
    }

    getToolbarItemConfig() {
        if (!translationStore.machineTranslation || !translationStore.editable) {
            return undefined;
        }

        return {
            type: 'button',
            icon: 'su-globe',
            label: this.running
                ? translate('eekes_sulu_translations.translate_selection_progress', {
                    done: this.done,
                    count: this.total,
                })
                : translate('eekes_sulu_translations.translate_selection'),
            loading: this.running,
            // Nothing ticked is nothing to do, and no reference language means no language
            // pair - every row would be refused for a reason that is not on this screen.
            disabled: 0 === this.selected.length || !this.referenceLocale,
            onClick: this.open,
        };
    }
}

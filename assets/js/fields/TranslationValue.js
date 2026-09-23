// @flow
import React from 'react';
import {observer} from 'mobx-react';
import {action, observable} from 'mobx';
import {translate} from 'sulu-admin-bundle/utils';
import {Icon} from 'sulu-admin-bundle/components';
// Not exported from `components`: Sulu keeps `Tooltip` to itself and imports it by path
// internally, so this is a deep import on purpose.
import Tooltip from 'sulu-admin-bundle/components/Tooltip';
import canMachineTranslate from './canMachineTranslate';
import IcuHelp from './IcuHelp';
import PlaceholderChips from './PlaceholderChips';
import PluralEditor from './PluralEditor';
import {parse} from './icuPlural';
import readTranslationData from './readTranslationData';
import resolveReference from './resolveReference';
import {describeError, translateField} from '../api/translations';
import translationStore from '../stores/translationStore';
import translationValue from './translationValue.scss';

/**
 * A text area, or the plural editor when the key is an ICU message and the message is one this
 * editor can put back together.
 *
 * The field decides, not the editor: nobody translating a website should have to know which of
 * the two kinds of string they are looking at.
 *
 * Above whichever of them it picks sits the reference panel: the same sentence in the language
 * the translator chose in the list toolbar, with the globe that machine translates it into the
 * box below.
 */
@observer
class TranslationValue extends React.Component<Object> {
    state = {raw: false};

    textareaRef: {current: null | HTMLTextAreaElement} = React.createRef();

    @observable translating: boolean = false;
    @observable undoValue: ?string = undefined;
    @observable translateError: ?string = undefined;

    handleChange = (event: SyntheticInputEvent<HTMLTextAreaElement>) => {
        this.props.onChange(event.currentTarget.value || undefined);
    };

    handleIcuChange = (value: string) => {
        this.props.onChange(value);
    };

    handleBlur = () => {
        const {onFinish} = this.props;

        if (onFinish) {
            onFinish();
        }
    };

    toggleRaw = () => {
        this.setState((state) => ({raw: !state.raw}));
    };

    get isIcu(): boolean {
        return this.data.icu;
    }

    get locale(): string {
        const {formInspector} = this.props;

        return (formInspector && formInspector.locale && formInspector.locale.get()) || 'en';
    }

    get data(): Object {
        return readTranslationData(this.props.formInspector);
    }

    /**
     * Shown only when there is something to translate *from* and an engine to do it. A button
     * that always fails is worse than no button.
     */
    get canTranslate(): boolean {
        return canMachineTranslate(this.data, this.reference, {
            configured: !!translationStore.machineTranslation,
            disabled: !!this.props.disabled,
        });
    }

    /**
     * The language a suggestion is translated *from*: the one the translator chose as their
     * reference, the same one the panel above shows. `resolveReference` already refuses to
     * return the language being edited, so there is no pair to check for here.
     */
    get reference(): Object {
        return resolveReference(this.data, this.locale);
    }

    @action handleTranslate = () => {
        const data = this.data;

        this.translating = true;
        this.translateError = undefined;

        translateField({id: data.id, locale: this.locale, from: this.reference.locale})
            .then(action((response) => {
                this.translating = false;
                // Remember what was there, so one click undoes the suggestion.
                this.undoValue = this.props.value;
                this.props.onChange(response.value);
            }))
            .catch(action((error) => {
                this.translating = false;
                this.translateError = describeError(error);
            }));
    };

    @action handleUndo = () => {
        this.props.onChange(this.undoValue);
        this.undoValue = undefined;
    };

    /**
     * The globe, next to the reference text it translates from.
     *
     * An icon rather than a sentence: it sits in the header of the reference panel, where the
     * language it would translate from is already written out, and a second copy of "from
     * English" there says nothing the editor cannot see. The sentence lives in the tooltip.
     *
     * **Sulu's `Tooltip`, not a `title` attribute.** `title` on the button is technically
     * correct and practically invisible: the browser waits about a second, draws it at the
     * pointer in the system style, and offers a keyboard nothing at all - it reads as "the
     * globe has no tooltip". Sulu's own tooltip opens on hover *and* on focus, in the admin's
     * style, like everything else on the screen. The attribute would also have gone nowhere
     * on `Icon`, which renders the props it knows and silently drops the rest.
     *
     * `aria-label` stays: a tooltip is not an accessible name, and the button has no text.
     */
    renderTranslateButton() {
        if (!this.canTranslate) {
            return null;
        }

        const label = this.translating
            ? translate('eekes_sulu_translations.translating')
            : translate('eekes_sulu_translations.translate_from', {locale: this.reference.locale});

        return (
            <span className={translationValue.referenceActions}>
                {this.undoValue !== undefined &&
                    <Tooltip label={translate('eekes_sulu_translations.undo')}>
                        <button
                            aria-label={translate('eekes_sulu_translations.undo')}
                            className={translationValue.iconAction}
                            onClick={this.handleUndo}
                            type="button"
                        >
                            <Icon name="su-process" />
                        </button>
                    </Tooltip>
                }
                <Tooltip label={label}>
                    <button
                        aria-label={label}
                        className={translationValue.iconAction}
                        disabled={this.translating}
                        onClick={this.handleTranslate}
                        type="button"
                    >
                        <Icon name="su-globe" />
                    </button>
                </Tooltip>
            </span>
        );
    }

    /**
     * The same sentence in the language the translator picked as their reference, directly
     * above the box they type the new one in.
     *
     * It used to live in the Context panel, which is gone for anybody without the
     * `translations_admin` right - so the one line on this screen that a translator cannot
     * work without was the one only a developer could see. It belongs to the input, not to
     * the developer-facing half of the key, and reading it half a screen away from the box
     * was never right either.
     *
     * Nothing is rendered when there is no reference: `resolveReference` returns none for the
     * language being edited, and an empty grey box promising a reference is worse than none.
     */
    renderReference() {
        const reference = this.reference;

        if (!reference.value) {
            return null;
        }

        return (
            <div className={translationValue.reference}>
                <div className={translationValue.referenceHeader}>
                    <span className={translationValue.referenceLabel}>
                        {translate('eekes_sulu_translations.reference_value')} ({reference.locale})
                    </span>
                    {this.renderTranslateButton()}
                </div>
                <p className={translationValue.referenceValue} lang={reference.locale}>{reference.value}</p>
                {this.translateError &&
                    <p className={translationValue.actionError}>{this.translateError}</p>
                }
            </div>
        );
    }

    /**
     * Pastes a placeholder where the cursor is, not at the end: an editor clicks the chip at
     * the moment they have reached the spot the name belongs in.
     */
    handleInsert = (token: string) => {
        const textarea = this.textareaRef.current;
        const value = this.props.value || '';

        if (!textarea) {
            this.props.onChange(value + token);

            return;
        }

        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;

        this.props.onChange(value.slice(0, start) + token + value.slice(end));

        // The value arrives through props on the next render, so the caret is restored after
        // it - otherwise it jumps to the end of the box the editor was typing in.
        window.requestAnimationFrame(() => {
            if (this.textareaRef.current) {
                this.textareaRef.current.focus();
                this.textareaRef.current.setSelectionRange(start + token.length, start + token.length);
            }
        });
    };

    renderPlaceholders(insertable: boolean = true) {
        const {disabled, value} = this.props;

        return (
            <PlaceholderChips
                disabled={disabled}
                onInsert={insertable ? this.handleInsert : undefined}
                placeholders={this.data.placeholders}
                value={value}
            />
        );
    }

    renderTextarea() {
        const {disabled, value} = this.props;

        return (
            <textarea
                className={translationValue.textarea}
                disabled={disabled}
                lang={this.locale}
                onBlur={this.handleBlur}
                onChange={this.handleChange}
                ref={this.textareaRef}
                rows={4}
                value={value || ''}
            />
        );
    }

    renderEditor() {
        const {disabled, value} = this.props;

        if (!this.isIcu || this.state.raw) {
            return (
                <div>
                    {this.renderTextarea()}
                    {this.renderPlaceholders()}
                    {this.isIcu &&
                        <div className={translationValue.actions}>
                            <button className={translationValue.action} onClick={this.toggleRaw} type="button">
                                {translate('eekes_sulu_translations.plural_editor')}
                            </button>
                            <IcuHelp />
                        </div>
                    }
                </div>
            );
        }

        const parsed = parse(value);

        if (!parsed) {
            // The message is ICU but not a shape this editor can round-trip. Say so, and get
            // out of the way.
            return (
                <div>
                    {this.renderTextarea()}
                    {this.renderPlaceholders()}
                    <div className={translationValue.actions}><IcuHelp /></div>
                    <p className={translationValue.note}>{translate('eekes_sulu_translations.icu_unparsable')}</p>
                </div>
            );
        }

        return (
            <div>
                <PluralEditor
                    argument={parsed.argument}
                    branches={parsed.branches}
                    disabled={disabled}
                    locale={this.locale}
                    onChange={this.handleIcuChange}
                />
                {/*
                    Listed, not insertable: the plural editor is a box per category and there
                    is no single cursor to paste into. `{count}` is usually already spoken for
                    by the `#` in each branch anyway.
                */}
                {this.renderPlaceholders(false)}
                <div className={translationValue.actions}>
                    <button className={translationValue.action} onClick={this.toggleRaw} type="button">
                        {translate('eekes_sulu_translations.plural_raw')}
                    </button>
                    <IcuHelp />
                </div>
            </div>
        );
    }

    /**
     * The reference above the editor, always in that order: the editor reads the sentence,
     * then writes it. Which of the three editors is below it is the field's decision and not
     * the translator's - see `renderEditor`.
     */
    render() {
        return (
            <div>
                {this.renderReference()}
                {this.renderEditor()}
            </div>
        );
    }
}

export default TranslationValue;

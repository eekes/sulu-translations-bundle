// @flow
import React from 'react';
import {createPortal} from 'react-dom';
import {observer} from 'mobx-react';
import {action, observable} from 'mobx';
import {translate} from 'sulu-admin-bundle/utils';
import {Icon} from 'sulu-admin-bundle/components';
import PlaceholderChips from '../fields/PlaceholderChips';
import {missingIn} from '../fields/placeholders';
import nextCell, {CELL_ATTRIBUTE} from './nextCell';
import {describeError, saveValue} from '../api/translations';
import inlineValueEditor from './inlineValueEditor.scss';

const MIN_WIDTH = 360;
const VIEWPORT_MARGIN = 12;

/**
 * The cell an editor actually works in.
 *
 * Collapsed it is a one-line box, so the table keeps its rhythm and a row never changes
 * height. Focused it becomes a textarea that **floats over** the rows below it: translations
 * run to three lines more often than not, and an editor who cannot see the end of the
 * sentence they are typing is being asked to translate blind.
 *
 * The textarea is rendered into `document.body` through a portal and positioned with the
 * cell's own bounding box. A plain absolutely positioned element inside the cell is at the
 * mercy of every `overflow` and `transform` between here and the table, and Sulu's list is
 * a scroll container - the portal takes that whole class of problem off the table.
 *
 * Enter saves, Shift+Enter is a newline, Escape reverts, and Tab saves and opens the row
 * below - Shift+Tab the one above - because a screen of forty sentences to type is a
 * spreadsheet and gets treated like one. A validation failure - a placeholder the translation
 * dropped - is rendered **on the cell** and the text is kept, because a toast that disappears
 * while the editor is looking at forty rows tells them nothing about which one was wrong; it
 * is also why Tab waits for the write and stays put when it is refused.
 */
@observer
class InlineValueEditor extends React.Component<{
    domain: string,
    id: number,
    locale: string,
    onSaved: (value: ?string) => void,
    placeholders: ?Array<string>,
    value: ?string,
}> {
    @observable draft: ?string = this.props.value;
    @observable editing: boolean = false;
    @observable saving: boolean = false;
    @observable error: ?string = undefined;
    @observable position: ?{left: number, top: number, width: number} = undefined;

    collapsedRef: {current: null | HTMLDivElement} = React.createRef();
    textareaRef: {current: null | HTMLTextAreaElement} = React.createRef();

    componentWillUnmount() {
        this.stopTracking();
    }

    @action handleOpen = () => {
        if (this.editing) {
            return;
        }

        this.draft = this.props.value;
        this.error = undefined;
        this.editing = true;
        this.measure();

        window.addEventListener('scroll', this.measure, true);
        window.addEventListener('resize', this.measure);

        // The textarea only exists after the next render.
        window.requestAnimationFrame(() => {
            const textarea = this.textareaRef.current;

            if (textarea) {
                textarea.focus();
                textarea.setSelectionRange(textarea.value.length, textarea.value.length);
                this.grow(textarea);
            }
        });
    };

    stopTracking() {
        window.removeEventListener('scroll', this.measure, true);
        window.removeEventListener('resize', this.measure);
    }

    /**
     * Keeps the floating textarea glued to its cell while the list scrolls underneath it.
     */
    @action measure = () => {
        const collapsed = this.collapsedRef.current;

        if (!collapsed) {
            return;
        }

        const rect = collapsed.getBoundingClientRect();
        const width = Math.min(
            Math.max(rect.width, MIN_WIDTH),
            window.innerWidth - rect.left - VIEWPORT_MARGIN
        );

        this.position = {left: rect.left, top: rect.top, width};
    };

    grow(textarea: HTMLTextAreaElement) {
        // Grow downwards over the rows below, up to a point - beyond that the textarea
        // scrolls, because a box taller than the window is worse than a small one.
        textarea.style.height = 'auto';
        textarea.style.height = `${Math.min(textarea.scrollHeight, Math.round(window.innerHeight * 0.5))}px`;
    }

    @action handleChange = (event: SyntheticInputEvent<HTMLTextAreaElement>) => {
        this.draft = event.currentTarget.value;
        this.grow(event.currentTarget);
    };

    /**
     * A chip pastes at the cursor. `PlaceholderChips` suppresses the mousedown that would
     * otherwise blur the textarea - and blurring commits the write, which would save the very
     * value the editor clicked the chip to fix.
     */
    @action handleInsert = (token: string) => {
        const textarea = this.textareaRef.current;
        const draft = this.draft || '';

        if (!textarea) {
            this.draft = draft + token;

            return;
        }

        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;

        this.draft = draft.slice(0, start) + token + draft.slice(end);

        window.requestAnimationFrame(() => {
            const current = this.textareaRef.current;

            if (current) {
                current.focus();
                current.setSelectionRange(start + token.length, start + token.length);
                this.grow(current);
            }
        });
    };

    handleKeyDown = (event: SyntheticKeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.cancel();
        }

        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            this.commit();
        }

        if (event.key === 'Tab') {
            // The browser's own Tab would leave the table entirely: the open textarea is in a
            // portal on `document.body`, so what follows it in tab order is whatever follows
            // the page, not the next row.
            event.preventDefault();
            this.moveTo(event.shiftKey ? -1 : 1);
        }
    };

    /**
     * Save, then open the row above or below - the way a spreadsheet behaves, which is what a
     * screen of forty sentences to type is.
     *
     * **After the save, not beside it.** Moving on immediately would read better and lie: a
     * write refused for a dropped placeholder keeps its overlay open with the text still in
     * it, and that overlay is no use to anybody once the cursor is two rows further down. So a
     * refusal stays put with its message, and only a write that landed moves.
     */
    moveTo(direction: number) {
        this.commit().then(() => {
            if (this.error) {
                return;
            }

            const cells = Array.prototype.slice.call(document.querySelectorAll(`[${CELL_ATTRIBUTE}]`));
            const target = nextCell(cells, this.collapsedRef.current, direction);

            // Focusing it is all it takes: the collapsed cell opens on focus, exactly as it
            // does when an editor tabs into the table from outside.
            if (target) {
                target.focus();
            }
        });
    }

    @action cancel = () => {
        this.draft = this.props.value;
        this.error = undefined;
        this.close();
    };

    @action close = () => {
        this.editing = false;
        this.position = undefined;
        this.stopTracking();
    };

    /**
     * Always a promise, so Tab can wait for the write before it moves on, and never two
     * requests for one edit: blurring to reach the next cell would otherwise commit a second
     * time while the first is still in flight.
     */
    @action commit = (): Promise<void> => {
        if (this.saving) {
            return Promise.resolve();
        }

        if (this.draft === this.props.value) {
            this.close();

            return Promise.resolve();
        }

        const {id, locale, onSaved} = this.props;

        this.saving = true;

        return saveValue(id, locale, this.draft)
            .then(action((response) => {
                this.saving = false;
                this.error = undefined;
                onSaved(response.value);
                this.close();
            }))
            .catch(action((error) => {
                this.saving = false;
                this.error = describeError(error);

                // Stay open with the text still in it: the editor is the only one who can fix
                // a placeholder they dropped, and they need to see what they typed.
                const textarea = this.textareaRef.current;

                if (textarea) {
                    textarea.focus();
                }
            }));
    };

    renderOverlay() {
        const {locale} = this.props;
        const position = this.position;

        if (!this.editing || !position) {
            return null;
        }

        return createPortal(
            <div
                className={inlineValueEditor.overlay}
                style={{left: position.left, top: position.top, width: position.width}}
            >
                <textarea
                    className={this.error ? inlineValueEditor.textareaWithError : inlineValueEditor.textarea}
                    disabled={this.saving}
                    lang={locale}
                    onBlur={this.commit}
                    onChange={this.handleChange}
                    onKeyDown={this.handleKeyDown}
                    ref={this.textareaRef}
                    rows={1}
                    value={this.draft || ''}
                />
                {/*
                    `describeError` hands over the whole sentence, placeholder wording
                    included where it belongs. Prefixing it here instead would blame the
                    placeholders for a refused write or an unreachable database.
                */}
                <div className={inlineValueEditor.chips}>
                    <PlaceholderChips
                        onInsert={this.handleInsert}
                        placeholders={this.props.placeholders}
                        value={this.draft}
                    />
                </div>
                {this.error
                    ? <span className={inlineValueEditor.error}>{this.error}</span>
                    : <span className={inlineValueEditor.hint}>{translate('eekes_sulu_translations.inline_hint')}</span>
                }
            </div>,
            document.body
        );
    }

    render() {
        const value = this.props.value;
        // Computed from the saved value, not from the draft: this is the state of the row as
        // it stands, which is what somebody scrolling the list is reading.
        const missing = missingIn(this.props.placeholders, value);

        return (
            <div className={inlineValueEditor.container}>
                <div
                    className={this.editing ? inlineValueEditor.collapsedEditing : inlineValueEditor.collapsed}
                    /* How the cell above and below find this one - see `nextCell`. */
                    {...{[CELL_ATTRIBUTE]: true}}
                    onClick={this.handleOpen}
                    onFocus={this.handleOpen}
                    ref={this.collapsedRef}
                    role="textbox"
                    tabIndex={0}
                >
                    {/*
                        A hint, not a button: the whole cell is the click target. Faint until
                        the row is hovered, because forty of these down a page would be
                        louder than the translations they sit next to.

                        Leading the text rather than trailing it, so the column reads as one
                        straight edge instead of pencils drifting with each sentence's length.
                    */}
                    <Icon className={inlineValueEditor.pencil} name="su-pen" />

                    {value
                        ? <span className={inlineValueEditor.text}>{value}</span>
                        : <span className={inlineValueEditor.placeholder}>
                            {translate('eekes_sulu_translations.add_translation')}
                        </span>
                    }

                    {/*
                        The one thing worth seeing without opening a row: this sentence is
                        going to render with a hole in it. Nothing is shown for a row that is
                        simply untranslated - most of the screen is that, and marking it all
                        marks nothing.
                    */}
                    {missing.length > 0 &&
                        /*
                            The span carries the tooltip: Sulu's `Icon` renders the props it
                            knows and silently drops the rest, `title` among them - so an icon
                            with a `title` on it hovers over as nothing at all.
                        */
                        <span
                            className={inlineValueEditor.warning}
                            title={translate('eekes_sulu_translations.placeholders_missing', {placeholders: missing.join(' ')})}
                        >
                            <Icon name="su-exclamation-triangle" />
                        </span>
                    }
                </div>
                {this.renderOverlay()}
            </div>
        );
    }
}

export default InlineValueEditor;

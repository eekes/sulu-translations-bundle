// @flow
import React from 'react';
import {observer} from 'mobx-react';
import {translate} from 'sulu-admin-bundle/utils';
import groupOccurrences from './groupOccurrences';
import readTranslationData from './readTranslationData';
import translationContext from './translationContext.scss';

/**
 * The developer-facing half of a key: what it is called, which catalogue it is in, what
 * created it, which templates ask for it and when the site last rendered it.
 *
 * A custom field rather than a handful of disabled inputs, because none of this is editable
 * and all of it is read while looking at something else.
 *
 * **The reference sentence is not here.** This whole panel disappears for anybody without the
 * `translations_admin` right (see `AdminFieldsMetadataVisitor`), and the one line a translator
 * cannot work without must not be the one only a developer can see. It lives above the input
 * now, in `TranslationValue`, which is also where it is read.
 */
@observer
class TranslationContext extends React.Component<Object> {
    get data(): Object {
        return readTranslationData(this.props.formInspector);
    }

    /**
     * Every file the extractor found this key in - templates and PHP alike - one line per
     * file with its line numbers behind it. All of them, not a preview: "where is this text
     * used" is the question the panel exists to answer, and a truncated answer to that is
     * worse than none. The list scrolls instead.
     */
    renderOccurrences(occurrences: Array<Object>) {
        return (
            <ul className={translationContext.occurrences}>
                {groupOccurrences(occurrences).map((occurrence) => (
                    <li key={occurrence.path}>
                        <code>{occurrence.path}</code>
                        {occurrence.lines.length > 0 &&
                            <span className={translationContext.lines}>:{occurrence.lines.join(', ')}</span>
                        }
                    </li>
                ))}
            </ul>
        );
    }

    render() {
        const data = this.data;
        const occurrences = data.occurrences;

        return (
            <div className={translationContext.container}>
                <dl className={translationContext.list}>
                    <dt>{translate('eekes_sulu_translations.key')}</dt>
                    <dd><code>{data.translationKey}</code></dd>

                    <dt>{translate('eekes_sulu_translations.domain')}</dt>
                    <dd><code>{data.domain}</code></dd>

                    <dt>{translate('eekes_sulu_translations.source')}</dt>
                    <dd>{data.source ? translate(`eekes_sulu_translations.source.${data.source}`) : '-'}</dd>

                    <dt>{translate('eekes_sulu_translations.last_used_at')}</dt>
                    <dd>{data.lastUsedAt || translate('eekes_sulu_translations.never_used')}</dd>

                    {occurrences.length > 0 &&
                        <React.Fragment>
                            <dt>
                                {translate('eekes_sulu_translations.occurrences')}
                                <span className={translationContext.count}>
                                    {groupOccurrences(occurrences).length}
                                </span>
                            </dt>
                            <dd>{this.renderOccurrences(occurrences)}</dd>
                        </React.Fragment>
                    }
                </dl>
            </div>
        );
    }
}

export default TranslationContext;

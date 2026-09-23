// @flow
import translationStore from '../stores/translationStore';
import type {TranslationData} from './readTranslationData';

export type Reference = {
    locale: ?string,
    value: ?string,
};

/**
 * Which language is shown next to the one being edited, and what it says there.
 *
 * **Resolved here rather than by the endpoint.** The reference locale is the translator's own
 * choice from the list toolbar, kept in their browser because it is a personal preference
 * rather than part of what the page is showing. Sulu can carry a router attribute or a fixed
 * request parameter into a form request, and that is neither - so the form ships every
 * locale's value and this picks one. Before that the form quietly used the *source* locale
 * instead, which meant a translator who set the reference to French while editing English saw
 * no reference at all.
 *
 * Nothing is shown when the reference is the language being edited: the same sentence twice
 * is noise, and for machine translation it is not a language pair at all.
 */
export default function resolveReference(data: TranslationData, locale: ?string): Reference {
    const preferred = translationStore.referenceLocale || translationStore.sourceLocale;

    if (!preferred || preferred === locale) {
        return {locale: undefined, value: undefined};
    }

    return {locale: preferred, value: data.values[preferred] || undefined};
}

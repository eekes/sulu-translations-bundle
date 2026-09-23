// @flow
import {Requester} from 'sulu-admin-bundle/services';
import {translate} from 'sulu-admin-bundle/utils';

const ACTION_BASE = '/admin/api/translation-actions';

/**
 * Every call the admin JavaScript makes. Kept in one place so the endpoints are visible at a
 * glance and so a validation failure is turned into something a component can render.
 */

/**
 * **Sulu's `Requester` rejects with the raw `Response`, not with the body it carries.** So a
 * `catch` block gets an object with no `message` and no `detail` on it, and anything reading
 * those fields silently describes every single failure as "unknown" - which is how a database
 * error ends up on screen as a placeholder complaint.
 *
 * Every write here goes through this instead: it reads the body once, off the one response
 * that is allowed to be read once, and rejects with something a component can render. The
 * status travels along, because a 403 and a 422 are not the same news.
 */
function withParsedError(promise: Object): Object {
    return promise.catch((response) => {
        if (!response || 'function' !== typeof response.json) {
            // A network failure or an abort: not a response at all.
            return Promise.reject(response);
        }

        return response.json().then(
            (body) => Promise.reject({...body, status: response.status}),
            // A gateway or a fatal before the serialiser: there is no JSON to read.
            () => Promise.reject({status: response.status}),
        );
    });
}

export function saveValue(id: number, locale: string, value: ?string): Promise<Object> {
    return withParsedError(Requester.put(`/admin/api/translation-keys/${id}?locale=${locale}`, {value}));
}

export function searchAndReplace(payload: Object): Promise<Object> {
    return Requester.post(`${ACTION_BASE}/search-replace`, payload);
}

export function copyLocale(payload: Object): Promise<Object> {
    return Requester.post(`${ACTION_BASE}/copy-locale`, payload);
}

export function machineTranslate(payload: Object): Promise<Object> {
    return Requester.post(`${ACTION_BASE}/machine-translate`, payload);
}

/**
 * A suggestion for one field. The server does not store it - the editor reads it, changes
 * what they want and presses save.
 */
export function translateField(payload: Object): Promise<Object> {
    return withParsedError(Requester.post(`${ACTION_BASE}/translate-field`, payload));
}

export function importTranslations(formData: FormData): Promise<Object> {
    // Not Requester: it serialises JSON, and this is a file upload.
    return fetch(`${ACTION_BASE}/import`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        body: formData,
    }).then((response) => response.json().then((data) => {
        if (!response.ok) {
            throw new Error(data.message || 'The import failed.');
        }

        return data;
    }));
}

export function restoreVersion(versionId: number): Promise<Object> {
    return Requester.post(`/admin/api/translation-versions/${versionId}?action=restore`, {});
}

/**
 * The whole sentence to put in front of the editor, not a fragment for a caller to decorate.
 *
 * A 422 from the write path carries the placeholders that did not match, and that is the only
 * case that gets the placeholder wording. Everything else says what actually went wrong: a
 * refused write, a database that is a migration behind, a key somebody deleted in another tab.
 * Blaming placeholders for all of them sends the editor off rewriting a sentence that was
 * never the problem.
 */
export function describeError(error: Object): string {
    // `context`, not `detail`: `detail` is where Sulu's own form view looks for the sentence
    // to put in front of the editor, so the API puts the sentence there and keeps its
    // structured data out of the way.
    const context = (error && error.context) || {};
    const missing = context.missing || [];
    const unexpected = context.unexpected || [];
    const stillInUse = context.stillInUse || [];

    // The other placeholder refusal, and the only one that is about a *different* row than the
    // one being saved: the source language may not drop an argument the translations are
    // still carrying, because those values would become unsavable and nothing would say so.
    if (stillInUse.length) {
        return translate('eekes_sulu_translations.placeholders_in_use', {
            placeholders: stillInUse.join(' '),
            locales: (context.locales || []).join(', '),
        });
    }

    if (missing.length || unexpected.length) {
        const parts = [translate('eekes_sulu_translations.placeholder_error')];

        if (missing.length) {
            parts.push(translate('eekes_sulu_translations.placeholders_missing', {placeholders: missing.join(', ')}));
        }

        if (unexpected.length) {
            parts.push(translate('eekes_sulu_translations.placeholders_unknown', {placeholders: unexpected.join(', ')}));
        }

        return parts.join(' ');
    }

    return (error && error.message) || translate('eekes_sulu_translations.save_failed');
}

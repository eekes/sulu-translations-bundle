// @flow
import type {TranslationData} from './readTranslationData';
import type {Reference} from './resolveReference';

export type MachineTranslationOptions = {
    configured: boolean,
    disabled?: boolean,
};

/**
 * Whether the globe in the reference header does anything, and therefore whether it is shown
 * at all. A button that always fails is worse than no button.
 *
 * Four things have to hold, and each of them is a different failure if it does not:
 *
 * - **an engine is configured** - `machine_translation.service` is optional, and most projects
 *   never set it;
 * - **the field is editable** - a read-only domain or a missing permission means the
 *   suggestion would land in a box nobody can save;
 * - **the key has an id** - the endpoint translates a stored key, so on the add form there is
 *   nothing to point it at yet;
 * - **there is something to translate from** - the reference language has to be another
 *   language (`resolveReference` refuses the one being edited) and it has to have a value.
 */
export default function canMachineTranslate(
    data: TranslationData,
    reference: Reference,
    options: MachineTranslationOptions,
): boolean {
    if (!options.configured || options.disabled) {
        return false;
    }

    if (!data.id) {
        return false;
    }

    return !!reference.locale && !!reference.value;
}

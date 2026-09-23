// @flow
import {initializer} from 'sulu-admin-bundle/services';
import {fieldRegistry, listFieldTransformerRegistry} from 'sulu-admin-bundle/containers';
import {listItemActionRegistry, listToolbarActionRegistry} from 'sulu-admin-bundle/views/List';
import translationStore from './stores/translationStore';
import EditableValueTransformer from './transformers/EditableValueTransformer';
import ReferenceValueTransformer from './transformers/ReferenceValueTransformer';
import TranslationContext from './fields/TranslationContext';
import TranslationValue from './fields/TranslationValue';
import BulkActionsToolbarAction from './toolbarActions/BulkActionsToolbarAction';
import ClearActiveRowToolbarAction from './toolbarActions/ClearActiveRowToolbarAction';
import ReferenceLocaleToolbarAction from './toolbarActions/ReferenceLocaleToolbarAction';
import TranslateSelectionToolbarAction from './toolbarActions/TranslateSelectionToolbarAction';
import RestoreVersionItemAction from './itemActions/RestoreVersionItemAction';

/**
 * The inline editor needs to know which language the list is showing. The list adapter does
 * not hand that to a transformer, and the transformer is constructed once, so it reads the
 * locale from the store instead - which the config hook keeps current.
 */
let currentLocale = () => {
    const match = window.location.hash.match(/\/translations\/([a-zA-Z_-]+)/);

    return (match && match[1]) || translationStore.defaultLocale;
};

initializer.addUpdateConfigHook('eekes_sulu_translations', (config: Object, initialized: boolean) => {
    translationStore.setConfig(config || {});

    if (initialized) {
        return;
    }

    listFieldTransformerRegistry.add('eekes_sulu_translations.editable_value', new EditableValueTransformer(currentLocale));
    listFieldTransformerRegistry.add('eekes_sulu_translations.reference_value', new ReferenceValueTransformer(currentLocale));

    fieldRegistry.add('translation_value', TranslationValue);
    fieldRegistry.add('translation_context', TranslationContext);

    listToolbarActionRegistry.add('eekes_sulu_translations.bulk_actions', BulkActionsToolbarAction);
    listToolbarActionRegistry.add('eekes_sulu_translations.clear_active_row', ClearActiveRowToolbarAction);
    listToolbarActionRegistry.add('eekes_sulu_translations.reference_locale', ReferenceLocaleToolbarAction);
    listToolbarActionRegistry.add('eekes_sulu_translations.translate_selection', TranslateSelectionToolbarAction);

    listItemActionRegistry.add('eekes_sulu_translations.restore_version', RestoreVersionItemAction);
});

export {translationStore};

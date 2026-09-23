<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Functional;

use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;

/**
 * The form metadata the admin JavaScript is handed.
 *
 * A field that is registered but misconfigured does not fail a PHP test, a container lint or
 * a webpack build - it throws inside React when an editor opens the form, which is the most
 * expensive place to find out. So the metadata is asserted here: the field types are the ones
 * we actually register, and any select carries the options its component insists on.
 */
class AdminFormMetadataTest extends TranslationsTestCase
{
    public function testTheDetailsFormHasOnlyTheFieldsWeRegister(): void
    {
        self::assertSame(
            [
                'context' => 'translation_context',
                'value' => 'translation_value',
                'icu' => 'checkbox',
                'protected' => 'checkbox',
            ],
            $this->fieldTypes('translation_details'),
        );
    }

    public function testTheAddFormHasOnlyTheFieldsWeRegister(): void
    {
        self::assertSame(
            [
                'translationKey' => 'text_line',
                'domain' => 'text_line',
                'icu' => 'checkbox',
                'protected' => 'checkbox',
                // The ICU-aware field, as on the details form. A plain `text_area` here makes
                // the ICU checkbox look broken: it saves the flag and nothing on the screen
                // changes, because only this field reacts to it.
                'value' => 'translation_value',
            ],
            $this->fieldTypes('translation_add'),
        );
    }

    /**
     * Sulu's SingleSelect throws `The "values" schema option of the SingleSelect field-type
     * must be an array!` when the options are missing. Nothing we ship is a select any more,
     * and if one comes back it has to bring its values with it.
     */
    public function testEverySelectCarriesItsValues(): void
    {
        foreach (['translation_details', 'translation_add'] as $formKey) {
            foreach ($this->fields($formKey) as $name => $field) {
                if (!\in_array($field->getType(), ['single_select', 'select'], true)) {
                    continue;
                }

                $options = $field->getOptions();

                self::assertArrayHasKey(
                    'values',
                    $options,
                    \sprintf('"%s.%s" is a select without a "values" option and will throw in the admin.', $formKey, $name),
                );
            }
        }
    }

    /**
     * The JSON the admin JavaScript actually downloads, not the objects behind it.
     *
     * Everything else in this class asks the metadata provider directly, which proves the
     * server builds the right thing but not that it survives serialisation and the cached
     * provider on the way to the browser. When a panel is blank in the admin and every other
     * test is green, this is the one that says which side to look at.
     */
    public function testTheMetadataEndpointServesTheContextField(): void
    {
        $this->client->jsonRequest('GET', '/admin/metadata/form/' . TranslationKey::FORM_KEY . '?locale=en');

        self::assertResponseIsSuccessful();

        /** @var array{form: array<string, array{type: string}>} $response */
        $response = \json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertArrayHasKey('context', $response['form']);
        self::assertSame('translation_context', $response['form']['context']['type']);
    }

    /**
     * @return array<string, string>
     */
    private function fieldTypes(string $formKey): array
    {
        return \array_map(
            static fn (FieldMetadata $field): string => $field->getType(),
            $this->fields($formKey),
        );
    }

    /**
     * @return array<string, FieldMetadata>
     */
    private function fields(string $formKey): array
    {
        /** @var MetadataProviderRegistry $registry */
        $registry = self::getContainer()->get('sulu_admin.metadata_provider_registry');

        $metadata = $registry->getMetadataProvider('form')->getMetadata($formKey, 'en', []);

        self::assertInstanceOf(FormMetadata::class, $metadata);

        $fields = [];

        foreach ($metadata->getItems() as $name => $item) {
            if ($item instanceof FieldMetadata) {
                $fields[$name] = $item;
            }
        }

        return $fields;
    }
}

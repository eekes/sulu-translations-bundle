<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Model;

/**
 * Where a key came from. Purely informational, but it is the first thing anyone asks when a key
 * they do not recognise shows up in the list.
 */
enum TranslationSource: string
{
    /** Imported from a translation file. */
    case File = 'file';

    /** Found by the extractor while scanning templates and PHP. */
    case Code = 'code';

    /** Asked for by a `trans()` call that nothing could answer. */
    case Runtime = 'runtime';

    /** Typed by a person in the admin. */
    case Manual = 'manual';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_column(self::cases(), 'value');
    }
}

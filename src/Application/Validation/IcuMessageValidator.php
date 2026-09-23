<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Validation;

use Eekes\SuluTranslationsBundle\Domain\Exception\InvalidIcuMessageException;

/**
 * Parses an ICU message with the intl extension before it is stored.
 *
 * An unbalanced brace in an ICU message is not a typo an editor can see; it is a runtime
 * exception on the page that renders it. The intl error message is passed through as-is
 * because it names the offset, and a friendlier paraphrase would lose that.
 */
class IcuMessageValidator
{
    public function isSupported(): bool
    {
        return \class_exists(\MessageFormatter::class);
    }

    /**
     * @throws InvalidIcuMessageException
     */
    public function validate(?string $message, string $locale): void
    {
        if (null === $message || '' === $message || !$this->isSupported()) {
            return;
        }

        $formatter = \MessageFormatter::create($locale, $message);

        if (null !== $formatter) {
            return;
        }

        $error = \intl_get_error_message();

        throw new InvalidIcuMessageException('' === $error ? 'unknown parse error' : $error, $locale);
    }
}

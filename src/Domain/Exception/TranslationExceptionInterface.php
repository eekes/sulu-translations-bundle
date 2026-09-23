<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Exception;

/**
 * Every exception this bundle throws on purpose implements it, so a consumer can catch the
 * bundle without catching Doctrine.
 */
interface TranslationExceptionInterface extends \Throwable
{
}

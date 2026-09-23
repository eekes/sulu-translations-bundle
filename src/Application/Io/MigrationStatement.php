<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Io;

/**
 * One `addSql()` call: the statement and its bound parameters.
 *
 * Parameters, never interpolation. A translation is arbitrary text - quotes, backslashes,
 * emoji, a stray `--` - and a generator that builds SQL by string concatenation produces a
 * migration that breaks on the one value nobody tested with, in production, during a deploy.
 * Doctrine's `addSql()` takes parameters, so there is no reason to escape anything by hand.
 */
final class MigrationStatement
{
    /**
     * @param list<mixed> $parameters
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $parameters = [],
    ) {
    }
}

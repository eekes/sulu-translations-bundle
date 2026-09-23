<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Unit\Application\Io;

use Eekes\SuluTranslationsBundle\Application\Io\CatalogueDomainSplitter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\MessageCatalogue;

class CatalogueDomainSplitterTest extends TestCase
{
    public function testItTellsIcuMessagesApartFromPlainOnes(): void
    {
        $catalogue = new MessageCatalogue('en', [
            'messages' => ['plain.key' => 'Plain'],
            'messages+intl-icu' => ['plural.key' => '{count, plural, one {# item} other {# items}}'],
        ]);

        self::assertSame([
            ['domain' => 'messages', 'icu' => false, 'messages' => ['plain.key' => 'Plain']],
            ['domain' => 'messages', 'icu' => true, 'messages' => ['plural.key' => '{count, plural, one {# item} other {# items}}']],
        ], (new CatalogueDomainSplitter())->split($catalogue));
    }

    public function testTheObviousLoopWouldHaveGotThisWrong(): void
    {
        $catalogue = new MessageCatalogue('en', [
            'messages+intl-icu' => ['plural.key' => '{count, plural, other {# items}}'],
        ]);

        // `all()` merges the ICU domain into the base one and strips the suffix, so iterating
        // it and testing the name marks every ICU message as plain. This is the regression
        // that shipped.
        self::assertSame(['messages'], \array_keys($catalogue->all()));

        $groups = (new CatalogueDomainSplitter())->split($catalogue);

        self::assertCount(1, $groups);
        self::assertTrue($groups[0]['icu']);
        self::assertSame('messages', $groups[0]['domain']);
    }

    public function testADomainWithOnlyPlainMessages(): void
    {
        $catalogue = new MessageCatalogue('en', ['validators' => ['required' => 'Required']]);

        $groups = (new CatalogueDomainSplitter())->split($catalogue);

        self::assertCount(1, $groups);
        self::assertFalse($groups[0]['icu']);
    }

    public function testSeveralDomainsAtOnce(): void
    {
        $catalogue = new MessageCatalogue('en', [
            'messages' => ['a' => 'A'],
            'validators+intl-icu' => ['b' => '{n, plural, other {#}}'],
        ]);

        $groups = (new CatalogueDomainSplitter())->split($catalogue);

        self::assertSame(
            [['messages', false], ['validators', true]],
            \array_map(static fn (array $group): array => [$group['domain'], $group['icu']], $groups),
        );
    }

    public function testAnEmptyCatalogue(): void
    {
        self::assertSame([], (new CatalogueDomainSplitter())->split(new MessageCatalogue('en')));
    }
}

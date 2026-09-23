<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Functional;

use Eekes\SuluTranslationsBundle\Application\Catalogue\ManagedDomains;
use Eekes\SuluTranslationsBundle\Application\Recorder\MissingMessageRecorder;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationSource;

/**
 * Runtime discovery: what the code asked for and nothing could answer becomes a row an editor
 * can see. This is what takes `translation:extract` out of the loop.
 */
class MissingMessageRecorderTest extends TranslationsTestCase
{
    public function testAMissingKeyIsWrittenOnFlush(): void
    {
        $recorder = $this->recorder();
        $recorder->recordMissing('discovered.by.runtime', 'messages');

        // Nothing is written until the response has been sent.
        self::assertSame(0, self::getKeyRepository()->countAll());

        $recorder->flush();

        $key = self::requireKey('discovered.by.runtime');
        self::assertSame(TranslationSource::Runtime, $key->getSource());
        // Discovery is not translation: the key exists in every locale and is empty in all of
        // them.
        self::assertCount(4, $key->getTranslations());
    }

    public function testTheSameKeyIsWrittenOnce(): void
    {
        $recorder = $this->recorder();
        $recorder->recordMissing('discovered.twice', 'messages');
        $recorder->recordMissing('discovered.twice', 'messages');
        $recorder->flush();

        self::assertNotNull(self::findKey('discovered.twice'));

        // A second request finds it and does nothing.
        $recorder = $this->recorder();
        $recorder->recordMissing('discovered.twice', 'messages');
        $recorder->flush();

        self::assertSame(1, self::getKeyRepository()->countAll());
    }

    public function testTheCapHolds(): void
    {
        $recorder = $this->recorder(limit: 3);

        for ($index = 0; $index < 50; ++$index) {
            $recorder->recordMissing('flood.' . $index, 'messages');
        }

        $recorder->flush();

        // A loop over a broken key must not be able to write a million rows.
        self::assertSame(3, self::getKeyRepository()->countAll());
    }

    public function testAnAbsurdIdIsIgnored(): void
    {
        $recorder = $this->recorder();
        $recorder->recordMissing('', 'messages');
        $recorder->recordMissing(\str_repeat('x', 1001), 'messages');
        $recorder->flush();

        self::assertSame(0, self::getKeyRepository()->countAll());
    }

    public function testADisabledRecorderRecordsNothing(): void
    {
        $recorder = $this->recorder(collectMissing: false, trackUsage: false);
        $recorder->recordMissing('never.recorded', 'messages');
        $recorder->flush();

        self::assertSame(0, self::getKeyRepository()->countAll());
        self::assertFalse($recorder->isEnabled());
    }

    public function testUsageIsTrackedEvenWhenNewKeysAreNotCollected(): void
    {
        // The two are independent settings. Tying them together made `track_usage: true` do
        // nothing whenever a project turned key collection off, which quietly emptied the
        // "Last shown" column and made the unused-keys report meaningless.
        self::givenTranslation('tracked.not.collected', 'en', 'Something');

        $recorder = $this->recorder(collectMissing: false, trackUsage: true);

        self::assertTrue($recorder->isEnabled());

        $recorder->recordMissing('should.not.be.created', 'messages');
        $recorder->recordUsed('tracked.not.collected', 'messages');
        $recorder->flush();

        self::getEntityManager()->clear();

        self::assertNull(self::findKey('should.not.be.created'));
        self::assertNotNull(self::requireKey('tracked.not.collected')->getLastUsedAt());
    }

    public function testADiscoveredKeyCountsAsShown(): void
    {
        // It rendered as its own id on a page, which is still "shown". Without this the
        // unused-keys report flags precisely the keys that most need translating.
        $recorder = $this->recorder();
        $recorder->recordMissing('shown.but.untranslated', 'messages');
        $recorder->flush();

        self::getEntityManager()->clear();

        self::assertNotNull(self::requireKey('shown.but.untranslated')->getLastUsedAt());
    }

    public function testUsageIsTracked(): void
    {
        self::givenTranslation('used.key', 'en', 'Something');
        self::assertNull(self::requireKey('used.key')->getLastUsedAt());

        $recorder = $this->recorder();
        $recorder->recordUsed('used.key', 'messages');
        $recorder->flush();

        self::getEntityManager()->clear();

        // The signal that makes the unused-keys report worth acting on: static extraction
        // alone cannot see a key a Twig extension builds.
        self::assertNotNull(self::requireKey('used.key')->getLastUsedAt());
    }

    public function testAFailureIsSwallowed(): void
    {
        $recorder = $this->recorder();
        $recorder->recordMissing('will.not.be.written', 'messages');

        self::getEntityManager()->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        self::getEntityManager()->getConnection()->executeStatement('DROP TABLE tl_translation');

        // Recording is a convenience. A read-only replica, a closed connection at the end of a
        // request or a missing table must never turn into an error the visitor sees.
        $recorder->flush();

        self::createSchema();
        self::getEntityManager()->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        self::assertSame(0, self::getKeyRepository()->countAll());
    }

    public function testAnIgnoredDomainIsNotRecorded(): void
    {
        // `admin` is Sulu's own catalogue, and every label one of its bundles ships without a
        // translation - `sulu_media.ai_generated` and a dozen like it - would otherwise land
        // in the list as work for a translator who has no idea what it is.
        $recorder = $this->recorder(ignoreDomains: ['admin']);
        $recorder->recordMissing('sulu_media.ai_generated', 'admin');
        $recorder->recordMissing('checkout.total', 'messages');
        $recorder->flush();

        self::assertNull(self::findKey('sulu_media.ai_generated', 'admin'));
        self::assertNotNull(self::findKey('checkout.total'));
    }

    public function testAnIgnoredDomainCanStillBeTranslatedOnPurpose(): void
    {
        // Overriding one of Sulu's own labels is a feature. Only *automatic* creation is
        // filtered, so a key somebody adds there by hand keeps working - `lastUsedAt` and all.
        self::getSynchronizer()->ensureKey('admin', 'sulu_admin.save', TranslationSource::Manual);
        self::getSynchronizer()->flush();

        $recorder = $this->recorder(ignoreDomains: ['admin']);
        $recorder->recordUsed('sulu_admin.save', 'admin');
        $recorder->flush();

        // `touchLastUsed` is one bulk statement, so the entity in memory knows nothing of it.
        self::getEntityManager()->clear();

        self::assertNotNull(self::requireKey('sulu_admin.save', 'admin')->getLastUsedAt());
    }

    /**
     * @param list<string> $ignoreDomains
     */
    private function recorder(
        bool $collectMissing = true,
        int $limit = 200,
        bool $trackUsage = true,
        array $ignoreDomains = [],
    ): MissingMessageRecorder {
        return new MissingMessageRecorder(
            self::getSynchronizer(),
            self::getKeyRepository(),
            $collectMissing,
            $limit,
            86400,
            $trackUsage,
            self::getDomainEventCollector(),
            null,
            new ManagedDomains($ignoreDomains),
        );
    }
}

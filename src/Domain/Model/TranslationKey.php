<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One translatable key, identified by its domain and its literal key.
 *
 * The key itself is TEXT because a project may use whole English sentences as keys; `keyHash`
 * exists only so the unique index has something of a fixed width to work with. See the
 * `architecture` skill.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tl_translation_key')]
#[ORM\UniqueConstraint(name: 'uniq_tl_translation_key', columns: ['domain', 'key_hash'])]
#[ORM\Index(name: 'idx_tl_translation_key_domain', columns: ['domain'])]
class TranslationKey
{
    public const RESOURCE_KEY = 'translation_keys';

    public const LIST_KEY = 'translation_keys';

    public const FORM_KEY = 'translation_details';

    public const ADD_FORM_KEY = 'translation_add';

    public const SECURITY_CONTEXT = 'sulu.settings.translations';

    /**
     * The developer half of the screen: the context panel, the ICU switch and the protected
     * switch. Getting any of them wrong breaks a page, and none of them is any business of
     * the person whose job is to write good Dutch.
     *
     * A second context, not a fifth permission type on the first one: Sulu's role matrix
     * renders one icon per permission type through `getActionIcon()`, which **throws** on
     * anything it does not know, so a custom type would take the whole roles screen down. A
     * second context is the supported way to say "separate thing, separately granted", and it
     * gets its own row with its own eye icon.
     */
    public const ADMIN_SECURITY_CONTEXT = 'sulu.settings.translations_admin';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $domain;

    /**
     * Named `translationKey` and not `key`: `KEY` is a DQL function, and a field called `key`
     * turns every list query that mentions it into a parse error.
     */
    #[ORM\Column(name: 'translation_key', type: Types::TEXT)]
    private string $translationKey;

    #[ORM\Column(name: 'key_hash', type: Types::STRING, length: 64)]
    private string $keyHash;

    /**
     * Whether the value belongs in Symfony's `<domain>+intl-icu` catalogue.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $icu = false;

    /**
     * Protected keys may be edited but never deleted.
     */
    #[ORM\Column(name: 'is_protected', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $protected = false;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: TranslationSource::class)]
    private TranslationSource $source;

    /**
     * The placeholders every language of this key has to carry, as writable tokens
     * (`%name%`, `{{ name }}`, `{count}`).
     *
     * Derived from the source-locale value and stored, rather than compared against the
     * sibling row on every write. Storing it is what lets the admin *show* an editor what a
     * string needs before they get it wrong, and what keeps the rule standing when the source
     * value is emptied - which used to switch the check off for every other language at once.
     *
     * `null` means nothing has declared them yet (a key discovered at runtime, or a row from
     * before this column existed) and nothing is enforced; `[]` means the source value was
     * read and has none.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $placeholders = null;

    /**
     * @var list<array{path: string, line: int}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $occurrences = null;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'orphaned_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $orphanedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<string, Translation>
     */
    #[ORM\OneToMany(targetEntity: Translation::class, mappedBy: 'translationKey', cascade: ['persist', 'remove'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    public function __construct(
        string $domain,
        string $key,
        TranslationSource $source = TranslationSource::Manual,
    ) {
        $this->domain = $domain;
        $this->translationKey = $key;
        $this->keyHash = self::hash($key);
        $this->source = $source;
        $this->createdAt = new \DateTimeImmutable();
        $this->translations = new ArrayCollection();
    }

    /**
     * The identity the unique index is built on. Never stored by a caller, never read by one.
     */
    public static function hash(string $key): string
    {
        return \hash('sha256', $key);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getKey(): string
    {
        return $this->translationKey;
    }

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    public function isIcu(): bool
    {
        return $this->icu;
    }

    public function setIcu(bool $icu): self
    {
        $this->icu = $icu;

        return $this;
    }

    /**
     * The catalogue domain the value has to be written to, which is not the domain an editor
     * sees: Symfony keeps ICU messages in a domain of their own.
     */
    public function getCatalogueDomain(): string
    {
        return $this->icu ? $this->domain . '+intl-icu' : $this->domain;
    }

    public function isProtected(): bool
    {
        return $this->protected;
    }

    public function setProtected(bool $protected): self
    {
        $this->protected = $protected;

        return $this;
    }

    public function getSource(): TranslationSource
    {
        return $this->source;
    }

    public function setSource(TranslationSource $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * @return list<array{path: string, line: int}>
     */
    public function getOccurrences(): array
    {
        return $this->occurrences ?? [];
    }

    /**
     * @param list<array{path: string, line: int}> $occurrences
     */
    public function setOccurrences(array $occurrences): self
    {
        $this->occurrences = [] === $occurrences ? null : $occurrences;

        return $this;
    }

    /**
     * @return list<string>|null null when nothing has declared them yet - which is not the
     *                           same as a key that has none, and the write path treats the
     *                           two differently
     */
    public function getPlaceholders(): ?array
    {
        return $this->placeholders;
    }

    /**
     * @param list<string>|null $placeholders
     */
    public function setPlaceholders(?array $placeholders): self
    {
        $this->placeholders = $placeholders;

        return $this;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): self
    {
        $this->lastUsedAt = $lastUsedAt;

        return $this;
    }

    public function getOrphanedAt(): ?\DateTimeImmutable
    {
        return $this->orphanedAt;
    }

    public function setOrphanedAt(?\DateTimeImmutable $orphanedAt): self
    {
        $this->orphanedAt = $orphanedAt;

        return $this;
    }

    public function isOrphaned(): bool
    {
        return null !== $this->orphanedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<string, Translation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function getTranslation(string $locale): ?Translation
    {
        $translation = $this->translations->get($locale);

        if ($translation instanceof Translation) {
            return $translation;
        }

        // A collection hydrated without its indexBy - the case when an entity was just created
        // in this unit of work - still has to answer correctly.
        foreach ($this->translations as $candidate) {
            if ($candidate->getLocale() === $locale) {
                return $candidate;
            }
        }

        return null;
    }

    public function addTranslation(Translation $translation): self
    {
        if (null === $this->getTranslation($translation->getLocale())) {
            $this->translations->set($translation->getLocale(), $translation);
            $translation->setKey($this);
        }

        return $this;
    }
}

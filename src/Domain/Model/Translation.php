<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Sulu\Component\Persistence\Model\AuditableInterface;
use Sulu\Component\Persistence\Model\AuditableTrait;

/**
 * The value of one key in one locale.
 *
 * A row exists for every configured locale from the moment the key does, even when nobody has
 * translated it - that is what makes "show me what is not translated" a list query instead of
 * an impossibility. See the `architecture` skill.
 *
 * `created`, `changed`, `creator` and `changer` are deliberately not mapped here: Sulu's
 * TimestampableSubscriber and UserBlameSubscriber add them to the metadata for every entity
 * implementing AuditableInterface, and mapping them twice is a Doctrine error.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tl_translation')]
#[ORM\UniqueConstraint(name: 'uniq_tl_translation', columns: ['translation_key_id', 'locale'])]
#[ORM\Index(name: 'idx_tl_translation_locale', columns: ['locale', 'is_translated'])]
class Translation implements AuditableInterface
{
    use AuditableTrait;

    public const RESOURCE_KEY = 'translations';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TranslationKey::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'translation_key_id', nullable: false, onDelete: 'CASCADE')]
    private TranslationKey $translationKey;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $locale;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $value = null;

    /**
     * Derived from the value and never set by a caller.
     *
     * A column and not a computed expression, because "translated / not translated" is the
     * filter an editor lives in, and Sulu's list can filter and sort a real boolean natively
     * while `value IS NULL` is not something its filter UI can express. It cannot drift:
     * `setValue()` is the only thing that writes either field.
     */
    #[ORM\Column(name: 'is_translated', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $translated = false;

    /**
     * When a generated migration last carried this value to the other environments.
     *
     * `null` means "never shipped", which is exactly what
     * `eekes:translations:generate-migration --from=database` selects: the values somebody
     * filled in locally and that no release has taken along yet. Compared against `changed`
     * it also answers "edited since it was shipped", which that command reports and refuses
     * to overwrite.
     *
     * It is set by the SQL the generator writes, not by the generator itself, so generating a
     * migration has no side effect and the mark lands on every machine that runs it.
     */
    #[ORM\Column(name: 'migrated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $migratedAt = null;

    /**
     * @var Collection<int, TranslationVersion>
     */
    #[ORM\OneToMany(targetEntity: TranslationVersion::class, mappedBy: 'translation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC', 'id' => 'DESC'])]
    private Collection $versions;

    public function __construct(TranslationKey $key, string $locale, ?string $value = null)
    {
        $this->translationKey = $key;
        $this->locale = $locale;
        $this->versions = new ArrayCollection();
        $this->setValue($value);
        $key->addTranslation($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): TranslationKey
    {
        return $this->translationKey;
    }

    public function setKey(TranslationKey $key): self
    {
        $this->translationKey = $key;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    /**
     * An empty string is stored as null, so "not translated" has exactly one representation -
     * which is what makes the untranslated filter a single `IS NULL`.
     */
    public function setValue(?string $value): self
    {
        $this->value = ('' === $value) ? null : $value;
        $this->translated = null !== $this->value;

        return $this;
    }

    public function isTranslated(): bool
    {
        return $this->translated;
    }

    public function getMigratedAt(): ?\DateTimeImmutable
    {
        return $this->migratedAt;
    }

    public function setMigratedAt(?\DateTimeImmutable $migratedAt): self
    {
        $this->migratedAt = $migratedAt;

        return $this;
    }

    /**
     * @return Collection<int, TranslationVersion>
     */
    public function getVersions(): Collection
    {
        return $this->versions;
    }

    public function addVersion(TranslationVersion $version): self
    {
        if (!$this->versions->contains($version)) {
            $this->versions->add($version);
        }

        return $this;
    }

    public function removeVersion(TranslationVersion $version): self
    {
        $this->versions->removeElement($version);

        return $this;
    }
}

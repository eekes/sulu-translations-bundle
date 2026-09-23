<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A value a translation used to have.
 *
 * Written on every change with the *previous* value, so the newest version is what the
 * translation said before the most recent edit. The activity trail says who changed something;
 * this says what it used to be, and lets an editor put it back in one click.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tl_translation_version')]
#[ORM\Index(name: 'idx_tl_translation_version_translation', columns: ['translation_id', 'created_at'])]
class TranslationVersion
{
    public const RESOURCE_KEY = 'translation_versions';

    public const LIST_KEY = 'translation_versions';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Translation::class, inversedBy: 'versions')]
    #[ORM\JoinColumn(name: 'translation_id', nullable: false, onDelete: 'CASCADE')]
    private Translation $translation;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $value;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Nullable on purpose: an import, a console command and a machine-translation run have no
     * user, and a foreign key to the user table would make deleting a user delete history.
     */
    #[ORM\Column(name: 'user_id', type: Types::INTEGER, nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(name: 'user_name', type: Types::STRING, length: 255, nullable: true)]
    private ?string $userName = null;

    public function __construct(
        Translation $translation,
        ?string $value,
        ?int $userId = null,
        ?string $userName = null,
    ) {
        $this->translation = $translation;
        $this->value = $value;
        $this->userId = $userId;
        $this->userName = $userName;
        $this->createdAt = new \DateTimeImmutable();

        $translation->addVersion($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTranslation(): Translation
    {
        return $this->translation;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getUserName(): ?string
    {
        return $this->userName;
    }
}

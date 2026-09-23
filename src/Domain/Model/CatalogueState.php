<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row, `id = 1`, holding the catalogue version counter.
 *
 * It is the only piece of state the website reads to decide whether its cached catalogue is
 * stale, and the reason a write in the admin kernel reaches the website kernel without a shared
 * cache pool. See the `runtime-catalogue` skill.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tl_translation_state')]
class CatalogueState
{
    public const ID = 1;

    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id = self::ID;

    #[ORM\Column(type: Types::BIGINT)]
    private int $version = 1;

    /**
     * Random, generated once when the row appears.
     *
     * The version alone is not enough to key a cache on. Drop the database and reimport it,
     * truncate the table in a test, restore a backup, and the counter starts again at one -
     * pointing at a cached catalogue whose contents belong to a different dataset. The token
     * makes every incarnation of the table its own namespace.
     */
    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $token;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $version = 1, ?string $token = null)
    {
        $this->version = $version;
        $this->token = $token ?? self::generateToken();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function generateToken(): string
    {
        return \bin2hex(\random_bytes(6));
    }

    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * What everything downstream actually keys on.
     */
    public function getFingerprint(): string
    {
        return $this->token . '.' . $this->version;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function bump(): self
    {
        ++$this->version;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

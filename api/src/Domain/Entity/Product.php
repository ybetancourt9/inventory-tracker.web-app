<?php

declare(strict_types=1);

namespace InventoryTracker\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * An inventory item.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'products',
    options: [
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'engine'    => 'InnoDB',
    ],
)]
#[ORM\UniqueConstraint(name: 'uniq_products_sku', columns: ['sku'])]
#[ORM\Index(name: 'idx_products_active_name', columns: ['is_active', 'name'])]
#[ORM\Index(name: 'idx_products_active_quantity', columns: ['is_active', 'quantity'])]
#[ORM\HasLifecycleCallbacks]
class Product
{
    public const SKU_MAX_LENGTH = 64;
    public const NAME_MAX_LENGTH = 128;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: self::SKU_MAX_LENGTH)]
    private string $sku;

    #[ORM\Column(type: Types::STRING, length: self::NAME_MAX_LENGTH)]
    private string $name;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $quantity = 0;

    /** Soft delete, so history and SKU uniqueness survive a removal. */
    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    private function __construct(string $sku, string $name, int $quantity)
    {
        $now = new DateTimeImmutable();

        $this->sku       = self::normaliseSku($sku);
        $this->name      = self::normaliseName($name);
        $this->quantity  = self::assertQuantity($quantity);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public static function create(string $sku, string $name, int $quantity = 0): self
    {
        return new self($sku, $name, $quantity);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity  = self::assertQuantity($quantity);
        $this->updatedAt = new DateTimeImmutable();
    }

    /** Backs the increment and decrement controls. */
    public function adjustQuantity(int $delta): void
    {
        $this->setQuantity($this->quantity + $delta);
    }

    public function rename(string $name): void
    {
        $this->name      = self::normaliseName($name);
        $this->updatedAt = new DateTimeImmutable();
    }

    public function deactivate(): void
    {
        $this->isActive  = false;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function activate(): void
    {
        $this->isActive  = true;
        $this->updatedAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    /** Uppercased so lookups and uniqueness do not depend on how it was typed. */
    public static function normaliseSku(string $sku): string
    {
        $sku = strtoupper(trim($sku));

        if ($sku === '') {
            throw new InvalidArgumentException('SKU must not be empty.');
        }

        if (mb_strlen($sku) > self::SKU_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('SKU must not exceed %d characters.', self::SKU_MAX_LENGTH)
            );
        }

        return $sku;
    }

    private static function normaliseName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('Name must not be empty.');
        }

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Name must not exceed %d characters.', self::NAME_MAX_LENGTH)
            );
        }

        return $name;
    }

    private static function assertQuantity(int $quantity): int
    {
        if ($quantity < 0) {
            throw new InvalidArgumentException('Quantity must not be negative.');
        }

        return $quantity;
    }
}

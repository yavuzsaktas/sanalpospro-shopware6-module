<?php declare(strict_types=1);

namespace SanalposproPayment\Core\Content\Installment;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * Entity for `sanalpospro_installment`.
 * Property names use camelCase mappings of Contract C snake_case columns.
 */
class InstallmentEntity extends Entity
{
    use EntityIdTrait;

    /** Issuing bank name (maps to bank_name column) */
    protected $bankName;

    /** Card type e.g. 'visa', 'mastercard' (maps to card_type column) */
    protected $cardType = null;

    /** Number of installments e.g. 3, 6, 9, 12 (maps to installment_count column) */
    protected $installmentCount;

    /** Interest / surcharge rate in percent (maps to interest_rate column) */
    protected $interestRate = 0.00;

    /** Whether this installment plan is currently active (maps to is_active column) */
    protected $isActive = true;

    // -------------------------------------------------------------------------
    // Getters & Setters
    // -------------------------------------------------------------------------

    public function getBankName(): string
    {
        return $this->bankName;
    }

    public function setBankName(string $bankName): void
    {
        $this->bankName = $bankName;
    }

    public function getCardType(): ?string
    {
        return $this->cardType;
    }

    public function setCardType(?string $cardType): void
    {
        $this->cardType = $cardType;
    }

    public function getInstallmentCount(): int
    {
        return $this->installmentCount;
    }

    public function setInstallmentCount(int $installmentCount): void
    {
        $this->installmentCount = $installmentCount;
    }

    public function getInterestRate(): float
    {
        return $this->interestRate;
    }

    public function setInterestRate(float $interestRate): void
    {
        $this->interestRate = $interestRate;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }
}
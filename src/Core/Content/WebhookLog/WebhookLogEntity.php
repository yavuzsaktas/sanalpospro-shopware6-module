<?php declare(strict_types=1);

namespace SanalposproPayment\Core\Content\WebhookLog;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * Entity for `sanalpospro_webhook_log`.
 * Property names use camelCase mappings of Contract C snake_case columns.
 */
class WebhookLogEntity extends Entity
{
    use EntityIdTrait;

    /** Shopware order_transaction.id (maps to order_tx_id column) */
    protected $orderTxId;

    /** PayThor gateway transaction_id (maps to paythor_tx_id column) */
    protected $paythorTxId = null;

    /** 'webhook' | 'callback' (maps to action column) */
    protected $action;

    /** 'success' | 'failed' | 'pending' | 'refunded' (maps to status column) */
    protected $status;

    /** Transaction amount (maps to amount column) */
    protected $amount = null;

    /** ISO 4217 currency code e.g. 'TRY' (maps to currency column) */
    protected $currency = null;

    /** Full raw JSON payload received (maps to raw_payload column) */
    protected $rawPayload = null;

    // -------------------------------------------------------------------------
    // Getters & Setters
    // -------------------------------------------------------------------------

    public function getOrderTxId(): string
    {
        return $this->orderTxId;
    }

    public function setOrderTxId(string $orderTxId): void
    {
        $this->orderTxId = $orderTxId;
    }

    public function getPaythorTxId(): ?string
    {
        return $this->paythorTxId;
    }

    public function setPaythorTxId(?string $paythorTxId): void
    {
        $this->paythorTxId = $paythorTxId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): void
    {
        $this->action = $action;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getAmount(): ?float
    {
        return $this->amount;
    }

    public function setAmount(?float $amount): void
    {
        $this->amount = $amount;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): void
    {
        $this->currency = $currency;
    }

    public function getRawPayload(): ?string
    {
        return $this->rawPayload;
    }

    public function setRawPayload(?string $rawPayload): void
    {
        $this->rawPayload = $rawPayload;
    }
}
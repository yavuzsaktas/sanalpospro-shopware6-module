<?php declare(strict_types=1);

namespace SanalposproPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1710000000InstallmentAndLog extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1710000000;
    }

    public function update(Connection $connection): void
    {
        // ── Webhook Log ────────────────────────────────────────────────────────
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `sanalpospro_webhook_log` (
                `id`            BINARY(16)    NOT NULL,
                `order_tx_id`   VARCHAR(128)  NOT NULL,
                `paythor_tx_id` VARCHAR(128)  NULL,
                `action`        VARCHAR(32)   NOT NULL,
                `status`        VARCHAR(32)   NOT NULL,
                `amount`        DECIMAL(20,4) NULL,
                `currency`      CHAR(3)       NULL,
                `raw_payload`   LONGTEXT      NULL,
                `created_at`    DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                `updated_at`    DATETIME(3)   NULL,
                PRIMARY KEY (`id`),
                KEY `idx_webhook_log_order_tx_id` (`order_tx_id`),
                KEY `idx_webhook_log_status`      (`status`),
                KEY `idx_webhook_log_created_at`  (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );

        // ── Installment ────────────────────────────────────────────────────────
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `sanalpospro_installment` (
                `id`                BINARY(16)   NOT NULL,
                `bank_name`         VARCHAR(128) NOT NULL,
                `card_type`         VARCHAR(64)  NULL,
                `installment_count` INT          NOT NULL,
                `interest_rate`     DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                `is_active`         TINYINT(1)   NOT NULL DEFAULT 1,
                `created_at`        DATETIME(3)  NOT NULL,
                `updated_at`        DATETIME(3)  NULL,
                PRIMARY KEY (`id`),
                KEY `idx_installment_bank_name` (`bank_name`),
                KEY `idx_installment_is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS `sanalpospro_webhook_log`');
        $connection->executeStatement('DROP TABLE IF EXISTS `sanalpospro_installment`');
    }
}
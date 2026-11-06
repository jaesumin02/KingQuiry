<?php
/**
 * Shared helpers for the administrator client, payment, and sales modules.
 *
 * The schema helpers keep existing KingQuiry databases usable without a
 * manual migration. They are idempotent and only add missing objects.
 */

function ensure_admin_management_schema(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $columnDefinitions = [
        'phone' => 'VARCHAR(30) DEFAULT NULL',
        'address' => 'VARCHAR(255) DEFAULT NULL',
        'accountStatus' => "ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active'",
    ];

    foreach ($columnDefinitions as $columnName => $columnDefinition) {
        $stmt = $conn->prepare(
            'SELECT COUNT(*) AS columnCount
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?'
        );
        $tableName = 'users';
        $stmt->bind_param('ss', $tableName, $columnName);
        $stmt->execute();
        $columnExists = (int) ($stmt->get_result()->fetch_assoc()['columnCount'] ?? 0) > 0;

        if (!$columnExists) {
            $conn->query("ALTER TABLE users ADD COLUMN `$columnName` $columnDefinition");
        }
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS payments (
            paymentID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            transactionID BIGINT UNSIGNED DEFAULT NULL,
            clientID INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            paymentType ENUM('Reservation', 'Down Payment', 'Installment', 'Full Payment', 'Other')
                NOT NULL DEFAULT 'Other',
            paymentMethod ENUM('Cash', 'Bank Transfer', 'GCash', 'Check', 'Other')
                NOT NULL DEFAULT 'Cash',
            referenceNo VARCHAR(100) DEFAULT NULL,
            status ENUM('Pending', 'Verified', 'Rejected') NOT NULL DEFAULT 'Pending',
            paidAt DATE NOT NULL,
            note VARCHAR(1000) DEFAULT NULL,
            recordedBy INT DEFAULT NULL,
            createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (paymentID),
            KEY idx_payments_transaction (transactionID),
            KEY idx_payments_client (clientID, paidAt),
            KEY idx_payments_status_date (status, paidAt),
            CONSTRAINT fk_payments_transaction
                FOREIGN KEY (transactionID)
                REFERENCES property_transactions (transactionID)
                ON DELETE SET NULL,
            CONSTRAINT fk_payments_client
                FOREIGN KEY (clientID)
                REFERENCES users (userID)
                ON DELETE CASCADE,
            CONSTRAINT fk_payments_recorded_by
                FOREIGN KEY (recordedBy)
                REFERENCES users (userID)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ensured = true;
}

function payment_type_options(): array
{
    return ['Reservation', 'Down Payment', 'Installment', 'Full Payment', 'Other'];
}

function payment_method_options(): array
{
    return ['Cash', 'Bank Transfer', 'GCash', 'Check', 'Other'];
}

function payment_status_options(): array
{
    return ['Pending', 'Verified', 'Rejected'];
}

function kq_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function kq_format_peso(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function kq_format_compact_peso(float $amount): string
{
    if ($amount >= 1000000) {
        return '₱' . number_format($amount / 1000000, 1) . 'M';
    }
    if ($amount >= 1000) {
        return '₱' . number_format($amount / 1000, 1) . 'K';
    }

    return '₱' . number_format($amount, 0);
}

function kq_log_admin_action(mysqli $conn, int $actorId, string $action): void
{
    if ($actorId <= 0 || $action === '') {
        return;
    }

    $stmt = $conn->prepare('INSERT INTO auditlogs (userID, action) VALUES (?, ?)');
    $stmt->bind_param('is', $actorId, $action);
    $stmt->execute();
}

/**
 * Bind a dynamic parameter list to a prepared statement safely.
 */
function kq_bind_dynamic(mysqli_stmt $stmt, string $types, array $values): void
{
    if ($types === '') {
        return;
    }

    $arguments = [$types];
    foreach (array_keys($values) as $key) {
        $arguments[] = &$values[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $arguments);
}

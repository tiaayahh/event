<?php

function ensureAuditLogsTable(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS audit_logs (
            log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            role VARCHAR(32) NULL,
            action VARCHAR(80) NOT NULL,
            target_type VARCHAR(80) DEFAULT NULL,
            target_id VARCHAR(80) DEFAULT NULL,
            metadata_json TEXT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_logs_user_created (user_id, created_at),
            INDEX idx_audit_logs_action_created (action, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $initialized = true;
}

function audit_log(
    PDO $pdo,
    ?int $userId,
    ?string $role,
    string $action,
    ?string $targetType = null,
    ?string $targetId = null,
    array $metadata = []
): void {
    try {
        ensureAuditLogsTable($pdo);

        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, role, action, target_type, target_id, metadata_json, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $role,
            $action,
            $targetType,
            $targetId,
            $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES),
            $_SERVER['REMOTE_ADDR'] ?? null,
            isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        error_log('audit_log failure: ' . $e->getMessage());
    }
}

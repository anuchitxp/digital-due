<?php
/**
 * inc/audit.php — append-only audit writer (docs/08 §3). Every meaningful action calls this.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function audit_write(string $action, ?string $entity = null, ?int $entityId = null, ?array $details = null): void
{
    $user = null;
    if (function_exists('current_user')) {
        $user = current_user();
    }
    x(
        'INSERT INTO dbo.audit_log (user_id, login_name, action, entity, entity_id, details_json)
         VALUES (?, ?, ?, ?, ?, ?)',
        [
            $user['id'] ?? null,
            $user['login_name'] ?? ($_SERVER['REMOTE_USER'] ?? null),
            $action,
            $entity,
            $entityId,
            $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
        ]
    );
}

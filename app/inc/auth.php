<?php
/**
 * inc/auth.php — identity (IIS Windows Auth via REMOTE_USER), roles, session policy,
 * and the evaluation status state machine (docs/08, docs/02 FR-3).
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $idle = (int)(config()['app']['session_idle_seconds'] ?? 900);
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
    // idle timeout (NFR-6)
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $idle) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();
}

/** Dev-only login simulation for Linux (no IIS Windows Auth there). MUST be off in prod. */
function current_login(): ?string
{
    session_boot();
    if (!empty($_GET['devu']) && (config()['app']['dev_impersonate'] ?? false)) {
        $_SESSION['dev_login'] = $_GET['devu'];
    }
    if (isset($_SESSION['dev_login'])) {
        return (string)$_SESSION['dev_login'];
    }
    $u = $_SERVER['REMOTE_USER'] ?? $_SERVER['LOGON_USER'] ?? '';
    return $u !== '' ? $u : null;
}

/** Current user row + roles, or null when not logged in / unknown account. */
function current_user(): ?array
{
    static $user = null;
    if ($user !== null) {
        return $user;
    }
    $login = current_login();
    if ($login === null) {
        return null;
    }
    $rows = q(
        'SELECT u.id, u.login_name, u.full_name, u.is_active
           FROM dbo.users u WHERE u.login_name = ?',
        [$login]
    );
    if (!$rows || !(int)$rows[0]['is_active']) {
        return null;
    }
    $roles = q(
        'SELECT r.code FROM dbo.user_roles ur
           JOIN dbo.roles r ON r.id = ur.role_id
          WHERE ur.user_id = ? ORDER BY r.code',
        [$rows[0]['id']]
    );
    $user = $rows[0];
    $user['roles'] = array_column($roles, 'code');
    $user['is_reviewer'] = in_array('due_reviewer', $user['roles'], true)
                        || in_array('admin', $user['roles'], true);
    return $user;
}

/** Enforce login; exits with 401 page when unauthenticated. */
function require_auth(): array
{
    $u = current_user();
    if ($u === null) {
        http_response_code(401);
        $dev = (config()['app']['dev_impersonate'] ?? false)
            ? '<p>Dev mode: add <code>?devu=HOSP%5Cdue_doc1</code> to the URL.</p>'
            : '<p>กรุณาเข้าสู่ระบบด้วย Windows Authentication ของโรงพยาบาล</p>';
        echo '<!doctype html><meta charset="utf-8"><title>401</title>'
           . '<div style="font-family:sans-serif;padding:40px"><h1>401 — ไม่ได้รับอนุญาต</h1>' . $dev . '</div>';
        exit;
    }
    return $u;
}

/** Enforce one of the given roles; exits with 403 otherwise. */
function require_role(array $roles): array
{
    $u = require_auth();
    if (!array_intersect($roles, $u['roles'])) {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>403</title>'
           . '<div style="font-family:sans-serif;padding:40px"><h1>403 — สิทธิไม่เพียงพอ</h1></div>';
        exit;
    }
    return $u;
}

/**
 * Status state machine — the ONLY place status transitions are decided (docs/05 §5).
 * Returns the new status, or null when the transition is illegal.
 */
function next_status(string $current, string $action, bool $isCreator): ?string
{
    $map = [
        'draft' => [
            'submit' => 'submitted',           // physician submits
            'withdraw' => 'withdrawn',
        ],
        'submitted' => [
            'start_review' => 'under_review',  // reviewer opens
            'approve' => 'approved',
            'approve_conditions' => 'approved_with_conditions',
            'info_request' => 'info_requested',
            'reject' => 'rejected',
        ],
        'under_review' => [
            'approve' => 'approved',
            'approve_conditions' => 'approved_with_conditions',
            'info_request' => 'info_requested',
            'reject' => 'rejected',
        ],
        'info_requested' => [
            'resubmit' => 'submitted',         // physician answers, resubmits
            'withdraw' => 'withdrawn',
        ],
    ];
    $next = $map[$current][$action] ?? null;
    if ($next === null) {
        return null;
    }
    // Separation of duties: the creator cannot record a final decision (docs/08 §2)
    if (in_array($action, ['approve', 'approve_conditions', 'reject'], true) && $isCreator) {
        return null;
    }
    return $next;
}

/** True when the action is legal for this user on this evaluation row. */
function can_transition(array $evaluation, string $action): bool
{
    $u = current_user();
    if ($u === null) {
        return false;
    }
    $isCreator = (int)$evaluation['created_by'] === (int)$u['id'];
    return next_status($evaluation['status'], $action, $isCreator) !== null;
}

/** Per-session CSRF token (docs/08 §6); render in every form, verify in every POST handler. */
function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['csrf'];
}

function csrf_verify(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('400 — invalid CSRF token');
    }
}

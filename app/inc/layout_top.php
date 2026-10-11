<?php
/**
 * inc/layout_top.php — page chrome (Thai-first). Set $PAGE before including:
 *   $PAGE = ['title' => '...', 'active' => 'home'|'new'|'review'|'reports'|'admin'];
 * Then: require __DIR__ . '/inc/layout_top.php';  ...page body...;  require .../layout_bottom.php;
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/criteria.php';

/** Escape helper — use for EVERY user/HIS-sourced value (docs/09 §5). */
function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

session_boot();
$U = require_auth();
$TITLE = $PAGE['title'] ?? 'DUE';
$ACTIVE = $PAGE['active'] ?? '';
$UNITS = config()['app']['units'];

$nav = [['home', 'index.php', 'หน้าหลัก']];
if (in_array('physician', $U['roles'], true) || in_array('clinic_staff', $U['roles'], true)) {
    $nav[] = ['new', 'evaluation_form.php', 'คำขอใหม่ / ต่อเนื่อง'];
}
if ($U['is_reviewer']) {
    $nav[] = ['review', 'review_queue.php', 'ตรวจสอบคำขอ'];
    $nav[] = ['admin', 'admin_criteria.php', 'เกณฑ์การใช้ยา'];
}
$nav[] = ['reports', 'reports.php', 'รายงาน'];
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($TITLE) ?> — DUE โรงพยาบาล (โปรโตไทป์)</title>
<link rel="stylesheet" href="assets/due.css">
</head>
<body>
<header class="app-header">
  <div class="app-icon">🫀</div>
  <div>
    <div class="app-title"><?= h($TITLE) ?></div>
    <div class="app-sub">ระบบประเมินการใช้ยา (DUE) — ยาลดไขมันราคาสูง · เกณฑ์ v1.0-draft (รอคณะกรรมการยืนยัน) · ใช้งานภายใน (offline)</div>
  </div>
</header>
<nav class="topnav">
  <?php foreach ($nav as [$key, $href, $label]): ?>
    <a href="<?= h($href) ?>"<?= $key === $ACTIVE ? ' class="active"' : '' ?>><?= h($label) ?></a>
  <?php endforeach; ?>
  <span class="who"><?= h($U['full_name']) ?> · <?= h(implode(', ', $U['roles'])) ?></span>
</nav>
<?php if (!empty($_GET['m'])): ?>
  <div class="flash ok"><?= h((string)$_GET['m']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['e'])): ?>
  <div class="flash err"><?= h((string)$_GET['e']) ?></div>
<?php endif; ?>

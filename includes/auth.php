<?php
date_default_timezone_set('Asia/Kolkata');   // match MySQL's CURDATE() on this machine
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/mongo.php';

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function csrf_check(): void {
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); exit('This form expired. Go back, reload the page and try again.'); }
}

function require_login(): void {
    if (empty($_SESSION['user_id'])) { header('Location: ' . base_url() . 'index.php'); exit; }
}
function require_admin(): void {
    require_login();
    if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); exit('This page is for admins only.'); }
}
function base_url(): string {
    return str_contains($_SERVER['SCRIPT_NAME'], '/admin/') ? '../' : '';
}
/** flash('Saved', 'ok', ['View history', 'history.php']) — the optional link shows on the right. */
function flash(?string $msg = null, string $type = 'ok', ?array $link = null) {
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $type, $link]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}

/* ---------- Presentation helpers ---------- */

/** Stroke icons on a 24x24 grid. */
function icon(string $name): string {
    $p = [
        'travel'      => '<path d="M5 17h14M5 17v-4.5L7 7.5h10l2 5V17M5 17v2M19 17v2"/><circle cx="8.5" cy="14" r="1"/><circle cx="15.5" cy="14" r="1"/>',
        'electricity' => '<path d="M13 2.5 5 13.5h6l-1 8 8-11h-6z"/>',
        'fuel'        => '<path d="M12 21a6 6 0 0 0 6-6c0-4.5-3.5-6.5-4.5-11-2.5 2-3 4.5-3 6.5-1-.6-1.6-1.6-1.8-2.6C7.2 9.4 6 11.6 6 15a6 6 0 0 0 6 6z"/>',
        'food'        => '<path d="M3.5 11.5h17a8.5 8.5 0 0 1-17 0z"/><path d="M12 8.5c0-3 2-5 5-5 0 3-2 5-5 5z"/>',
        'waste'       => '<path d="M4 6.5h16M9.5 6.5V4h5v2.5M6 6.5l1 14h10l1-14M10 10.5v6M14 10.5v6"/>',
        'other'       => '<circle cx="12" cy="12" r="7.5"/><path d="M12 8.5v7M8.5 12h7"/>',
        'dashboard'   => '<path d="M4 13.5h6.5V4H4zM13.5 20H20v-9.5h-6.5zM13.5 7.5H20V4h-6.5zM4 20h6.5v-3.5H4z"/>',
        'plus'        => '<path d="M12 5v14M5 12h14"/>',
        'history'     => '<path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
        'report'      => '<path d="M14 3.5H6.5v17h11V7z"/><path d="M14 3.5V7h3.5M9.5 16.5v-3M12 16.5v-6M14.5 16.5V12"/>',
        'db'          => '<ellipse cx="12" cy="5.5" rx="7.5" ry="2.5"/><path d="M4.5 5.5v13c0 1.4 3.4 2.5 7.5 2.5s7.5-1.1 7.5-2.5v-13M4.5 12c0 1.4 3.4 2.5 7.5 2.5s7.5-1.1 7.5-2.5"/>',
        'sliders'     => '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
        'tree'        => '<path d="M12 21v-5M12 16c-3.9 0-6.5-2.2-6.5-5.2 0-2.4 1.6-4 3.2-4.5C9.3 4.3 10.6 3 12 3s2.7 1.3 3.3 3.3c1.6.5 3.2 2.1 3.2 4.5 0 3-2.6 5.2-6.5 5.2z"/>',
        'ac'          => '<rect x="3" y="5" width="18" height="8" rx="2"/><path d="M6.5 10h11M8 16.5c0 1.5-1 2-1 3.5M12 16.5c0 1.5-1 2-1 3.5M16 16.5c0 1.5-1 2-1 3.5"/>',
        'trend'       => '<path d="M3.5 17 9 11.5l3.5 3.5 8-8M15 7h5.5v5.5"/>',
        'check'       => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'alert'       => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5h.01"/>',
        'x'           => '<path d="M6 6l12 12M18 6 6 18"/>',
        'print'       => '<path d="M7 9V3.5h10V9M7 17H4.5V9.5h15V17H17"/><path d="M7 14h10v6.5H7z"/>',
        'download'    => '<path d="M12 4v11M7 10.5l5 5 5-5M5 20h14"/>',
        'arrow'       => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'info'        => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5h.01"/>',
        'link'        => '<path d="M14 4h6v6M20 4l-9 9M18 14v5.5H4.5V6H10"/>',
        'shield'      => '<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.2 7.5 9.5 4.3-1.3 7.5-4.9 7.5-9.5V6z"/><path d="M9 12l2 2 4-4"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($p[$name] ?? $p['other']) . '</svg>';
}

/** Colour + icon per category. Categories the admin adds later fall back to grey. */
function cat_meta(string $name): array {
    [$var, $icon] = [
        'Travel'       => ['--c-travel', 'travel'],
        'Electricity'  => ['--c-electricity', 'electricity'],
        'Cooking Fuel' => ['--c-fuel', 'fuel'],
        'Food'         => ['--c-food', 'food'],
        'Waste'        => ['--c-waste', 'waste'],
    ][$name] ?? ['--c-other', 'other'];
    return ['var' => $var, 'icon' => $icon, 'style' => "--cc:var($var)"];
}
function cat_chip(string $name): string {
    return '<span class="cat" style="' . cat_meta($name)['style'] . '"><i class="dot"></i>' . e($name) . '</span>';
}
function cat_icon(string $name): string {
    $m = cat_meta($name);
    return '<span class="ci" style="' . $m['style'] . '">' . icon($m['icon']) . '</span>';
}

/** 1 decimal under 100 kg, none above, tonnes from 1000. */
function kg(float $v, bool $unit = true): string {
    if (abs($v) >= 1000) return number_format($v / 1000, 2) . ($unit ? ' t' : '');
    return number_format($v, abs($v) < 100 ? 1 : 0) . ($unit ? ' kg' : '');
}

/* Everyday equivalents, so a number in kg means something.
   Tree: a mature tree absorbs roughly 22 kg CO2 a year (European Environment Agency).
   AC:   a 1.5-ton split AC draws ~1.5 kW; at the India grid factor (0.716) that is ~1.07 kg CO2 an hour.
   Car:  average petrol car, same value as the Travel factor in the database. */
const KG_PER_TREE_YEAR = 22.0;
const KG_PER_AC_HOUR   = 1.07;
const KG_PER_CAR_KM    = 0.17;

function user_budget(): float {
    static $b = null;
    if ($b === null) {
        $st = db()->prepare('SELECT monthly_budget_kg FROM users WHERE user_id = ?');
        $st->execute([$_SESSION['user_id']]);
        $b = (float)($st->fetchColumn() ?: 208);
    }
    return $b;
}

/** 50.00 → 50, 0.0350 → 0.035 (never strips zeros from whole numbers). */
function trimnum($v): string {
    $s = (string)$v;
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $s = mb_substr($parts[0] ?? '', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '');
    return mb_strtoupper($s ?: '?');
}

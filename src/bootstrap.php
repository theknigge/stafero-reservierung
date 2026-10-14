<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');

const MIN_SEATS = 2;  // kleinste Gruppe, die reservieren kann

require __DIR__ . '/layout.php';
require __DIR__ . '/db.php';
require __DIR__ . '/mail.php';

// --- Konfiguration (komplett über Umgebungsvariablen, siehe README / .env.example) ---

function env(string $key, string $default = ''): string
{
    $v = getenv($key);
    return ($v === false || trim($v) === '') ? $default : trim($v);
}

function cfg(string $key): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [
            'event_name'            => env('EVENT_NAME', 'STAFERO-Jubiläumsball'),
            'contact_email'         => env('CONTACT_EMAIL', 'reservierung@stafero.de'),
            'deadline'              => new DateTimeImmutable(env('DEADLINE', '2026-11-08 23:59:59')),
            'max_seats'             => max(MIN_SEATS, (int) env('MAX_SEATS', '20')),
            'admin_password'        => env('ADMIN_PASSWORD'),
            'db_path'               => env('DB_PATH', '/data/reservierungen.sqlite'),
            'app_url'               => env('APP_URL'),
            'smtp_host'             => env('SMTP_HOST'),
            'smtp_port'             => (int) env('SMTP_PORT', '587'),
            'smtp_secure'           => env('SMTP_SECURE', 'tls'),   // tls (STARTTLS) | ssl | none
            'smtp_user'             => env('SMTP_USER'),
            'smtp_pass'             => env('SMTP_PASS'),
            'mail_from'             => env('MAIL_FROM', env('SMTP_USER')),
            'mail_from_name'        => env('MAIL_FROM_NAME', 'STAFERO e.V.'),
            'mail_bcc'              => env('MAIL_BCC'),
        ];
    }
    return $cfg[$key];
}

// --- Session & Sicherheit ---

function is_https(): bool
{
    return ($_SERVER['HTTPS'] ?? '') === 'on'
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

session_name('stafero_res');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_check(): bool
{
    return hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''));
}

/** Client-IP hinter Easypanel/Traefik (und ggf. Cloudflare). */
function client_ip(): string
{
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        return end($parts);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

// --- Helfer ---

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function is_closed(): bool
{
    return new DateTimeImmutable('now') > cfg('deadline');
}

function deadline_label(): string
{
    return cfg('deadline')->format('d.m.Y');
}

function fmt_datetime(string $dt): string
{
    return (new DateTimeImmutable($dt))->format('d.m.Y H:i');
}

function plural(int $n, string $one, string $many): string
{
    return $n . ' ' . ($n === 1 ? $one : $many);
}

/** Vereinheitlicht Telefonnummern zum Vergleich: 0171 / +49 171 / 0049171 → 0171… */
function normalize_phone(string $raw): string
{
    $p = preg_replace('/[^\d+]/', '', $raw);
    if (str_starts_with($p, '+')) {
        $p = '00' . substr($p, 1);
    }
    $p = str_replace('+', '', $p);
    if (str_starts_with($p, '0049')) {
        $p = '0' . substr($p, 4);
    }
    return $p;
}

/** "Müller, Vanessa" – so steht der Name auf Schildern und Listen. */
function display_name(array $res): string
{
    return $res['last_name'] . ', ' . $res['first_name'];
}

/** Persönlicher Link zum Ändern der Reservierung. */
function change_url(string $code): string
{
    return app_url() . '?code=' . rawurlencode($code);
}

// --- Seitenrahmen ---

function page_header(string $title, string $subtitle): void
{
    ?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> – <?= h(cfg('event_name')) ?> – STAFERO e.V.</title>
<link rel="icon" href="assets/stafero-logo.png">
<link rel="stylesheet" href="assets/style.css?v=6">
</head>
<body>
<header class="site-header">
  <a href="https://www.stadtfest-ronnenberg.de/stafero-e-v/"><img src="assets/stafero-logo.png" width="500" height="500" alt="STAFERO e.V. – … und es ist Party in der Stadt!"></a>
</header>
<section class="hero">
  <h1><?= h($title) ?></h1>
  <p><?= h($subtitle) ?></p>
</section>
<?php
}

function page_footer(): void
{
    $mail = cfg('contact_email');
    ?>
<footer class="site-footer">
  <p>Fragen zur Reservierung? <a class="mail" href="mailto:<?= h($mail) ?>"><?= h($mail) ?></a></p>
  <a href="https://www.stadtfest-ronnenberg.de/impressum/">Impressum</a>
  <a href="https://www.stadtfest-ronnenberg.de/datenschutz/">Datenschutz</a>
</footer>
<script src="assets/plan.js?v=4" defer></script>
</body>
</html>
<?php
}

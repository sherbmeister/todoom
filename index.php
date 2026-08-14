<?php
declare(strict_types=1);

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

require __DIR__ . '/vendor/autoload.php';

const SESSION_LIFETIME = 2592000;
ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
session_set_cookie_params(['lifetime' => SESSION_LIFETIME, 'httponly' => true, 'samesite' => 'Lax', 'secure' => false]);
session_start();

const APP_NAME = 'Todoom';
// Change the release ID and date once per deployed update; sync_release advances the visible version.
const APP_RELEASE_ID = '20260812-mobile-archive-shortcuts';
const INITIAL_APP_VERSION = '1.0';
const APP_RELEASE_DATE = '12/08/2026';
const DB_FILE = __DIR__ . '/storage/todoom.sqlite';
const COLORS = ['#0e9f7d', '#2563eb', '#e4a100', '#dc2626', '#7c3aed', '#db2777', '#0891b2', '#65a30d', '#f97316', '#4f46e5', '#64748b', '#a16207'];
const COUNTRY_CODES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW';

main();

function main(): void
{
    $action = $_GET['a'] ?? 'dashboard';
    if (!users_exist() && !in_array($action, ['setup', 'qr', 'captcha', 'theme'], true)) {
        redirect('?a=setup');
    }
    match ($action) {
        'setup' => setup(),
        'register' => register(),
        'verify' => verify_email(),
        'verify-email-change' => verify_email_change(),
        'verify-pending' => verify_pending(),
        'login' => login(),
        'forgot-password' => forgot_password(),
        'reset-password' => reset_password(),
        'twofactor-login' => twofactor_login(),
        'mobile-biometric-login' => mobile_biometric_login(),
        'mobile-unlock' => mobile_unlock(),
        'logout' => logout(),
        'captcha' => captcha_image(),
        'theme' => toggle_theme(),
        'calendar' => calendar_view(),
        'archived-lists' => archived_lists_page(),
        'list-new' => list_form(),
        'list-edit' => list_edit(),
        'list-delete' => list_delete(),
        'list-archive' => list_archive(),
        'list-restore' => list_restore(),
        'list-reorder' => list_reorder(),
        'task-new' => task_form(),
        'task-create' => task_create(),
        'task-edit' => task_edit(),
        'task-toggle' => task_toggle(),
        'task-delete' => task_delete(),
        'tasks-bulk-delete' => tasks_bulk_delete(),
        'overdue-notified' => overdue_notified(),
        'profile' => profile(),
        'enable-2fa' => enable_2fa(),
        'disable-2fa' => disable_2fa(),
        'qr' => qr(),
        'admin' => admin_dashboard(),
        'admin-users' => admin_users(),
        'admin-activity' => admin_activity(),
        'admin-settings' => admin_settings(),
        default => dashboard(),
    };
}

function db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) return $db;
    if (!is_dir(__DIR__ . '/storage')) mkdir(__DIR__ . '/storage', 0775, true);
    $db = new PDO('sqlite:' . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL;');
    migrate($db);
    return $db;
}

function migrate(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            email TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user',
            status TEXT NOT NULL DEFAULT 'active',
            email_verified_at TEXT NULL,
            twofa_secret TEXT NULL,
            twofa_enabled INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS lists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            color TEXT NOT NULL DEFAULT '#0e9f7d',
            archived INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            list_id INTEGER NOT NULL REFERENCES lists(id) ON DELETE CASCADE,
            title TEXT NOT NULL,
            notes TEXT NULL,
            status TEXT NOT NULL DEFAULT 'open',
            priority TEXT NOT NULL DEFAULT 'medium',
            due_date TEXT NULL,
            tags TEXT NULL,
            completed_at TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS email_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            token TEXT NOT NULL UNIQUE,
            purpose TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS settings (
            name TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS login_attempts (
            attempt_key TEXT PRIMARY KEY,
            failed_count INTEGER NOT NULL DEFAULT 0,
            last_failed_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS activity_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
            username TEXT NOT NULL,
            action TEXT NOT NULL,
            details TEXT NULL,
            ip_address TEXT NULL,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS mobile_unlock_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            selector TEXT NOT NULL UNIQUE,
            validator_hash TEXT NOT NULL,
            device_label TEXT NULL,
            expires_at TEXT NOT NULL,
            last_used_at TEXT NULL,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS trusted_2fa_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            selector TEXT NOT NULL UNIQUE,
            validator_hash TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            last_used_at TEXT NULL,
            created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_activity_created ON activity_logs(created_at DESC);
        CREATE INDEX IF NOT EXISTS idx_activity_user ON activity_logs(user_id, created_at DESC);
        CREATE INDEX IF NOT EXISTS idx_mobile_unlock_user ON mobile_unlock_tokens(user_id, expires_at);
        CREATE INDEX IF NOT EXISTS idx_trusted_2fa_user ON trusted_2fa_tokens(user_id, expires_at);
    ");
    $defaults = [
        'allow_registration' => '1',
        'require_email_verification' => '1',
        'site_url' => base_url(),
        'mail_from_email' => 'todoom@quantumnet.space',
        'mail_from_name' => APP_NAME,
        'mail_envelope_sender' => 'todoom@quantumnet.space',
        'mail_reply_to' => 'mariuserbanica@proton.me',
        'app_version' => INITIAL_APP_VERSION,
        'last_patched' => APP_RELEASE_DATE,
        'app_release_id' => APP_RELEASE_ID,
        'site_default_theme' => 'light',
        'site_default_language' => 'en',
    ];
    $stmt = $db->prepare('INSERT OR IGNORE INTO settings (name, value) VALUES (?, ?)');
    foreach ($defaults as $name => $value) $stmt->execute([$name, $value]);
    sync_release($db);

    $columns = array_column($db->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    if (!in_array('country_code', $columns, true)) {
        $db->exec("ALTER TABLE users ADD COLUMN country_code TEXT NOT NULL DEFAULT 'GB'");
    }
    if (!in_array('avatar_path', $columns, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN avatar_path TEXT NULL');
    }
    if (!in_array('avatar_url', $columns, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN avatar_url TEXT NULL');
    }
    if (!in_array('pending_email', $columns, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN pending_email TEXT NULL');
    }
    if (!in_array('mobile_biometric_enabled', $columns, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN mobile_biometric_enabled INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('overdue_notify_method', $columns, true)) {
        $db->exec("ALTER TABLE users ADD COLUMN overdue_notify_method TEXT NOT NULL DEFAULT 'notifications'");
    }
    $listColumns = array_column($db->query('PRAGMA table_info(lists)')->fetchAll(), 'name');
    if (!in_array('icon_path', $listColumns, true)) {
        $db->exec('ALTER TABLE lists ADD COLUMN icon_path TEXT NULL');
    }
    if (!in_array('icon_url', $listColumns, true)) {
        $db->exec('ALTER TABLE lists ADD COLUMN icon_url TEXT NULL');
    }
    if (!in_array('archived', $listColumns, true)) {
        $db->exec('ALTER TABLE lists ADD COLUMN archived INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('position', $listColumns, true)) {
        $db->exec('ALTER TABLE lists ADD COLUMN position INTEGER NOT NULL DEFAULT 0');
        $db->exec('UPDATE lists SET position=id');
    }
    $taskColumns = array_column($db->query('PRAGMA table_info(tasks)')->fetchAll(), 'name');
    if (!in_array('overdue_notified_at', $taskColumns, true)) {
        $db->exec('ALTER TABLE tasks ADD COLUMN overdue_notified_at TEXT NULL');
    }
    if (!in_array('overdue_emailed_at', $taskColumns, true)) {
        $db->exec('ALTER TABLE tasks ADD COLUMN overdue_emailed_at TEXT NULL');
    }
    if (!in_array('due_soon_notified_at', $taskColumns, true)) {
        $db->exec('ALTER TABLE tasks ADD COLUMN due_soon_notified_at TEXT NULL');
    }
    if (!in_array('due_soon_emailed_at', $taskColumns, true)) {
        $db->exec('ALTER TABLE tasks ADD COLUMN due_soon_emailed_at TEXT NULL');
    }
}

function users_exist(): bool { return (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0; }
function now(): string { return gmdate('Y-m-d H:i:s'); }
function h(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf(): string { $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function check_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        flash('Your session expired. Please try again.', 'error');
        redirect($_SERVER['HTTP_REFERER'] ?? '?');
    }
}
function redirect(string $url): never { header('Location: ' . $url); exit; }
function log_activity(?array $u, string $action, string $details = ''): void
{
    $userId = $u ? (int) $u['id'] : null;
    $username = $u ? (string) $u['username'] : 'System';
    $ip = filter_var($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '', FILTER_VALIDATE_IP) ?: ($_SERVER['REMOTE_ADDR'] ?? null);
    db()->prepare('INSERT INTO activity_logs (user_id,username,action,details,ip_address,created_at) VALUES (?,?,?,?,?,?)')->execute([$userId, $username, $action, substr($details, 0, 240) ?: null, $ip, now()]);
}

function captcha_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 6; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $code;
}
function issue_captcha(string $purpose): string
{
    $now = time();
    foreach ($_SESSION['captchas'] ?? [] as $token => $challenge) {
        if (($challenge['expires'] ?? 0) < $now) unset($_SESSION['captchas'][$token]);
    }
    while (count($_SESSION['captchas'] ?? []) >= 12) array_shift($_SESSION['captchas']);
    $token = bin2hex(random_bytes(16));
    $_SESSION['captchas'][$token] = ['code' => captcha_code(), 'purpose' => $purpose, 'expires' => $now + 600];
    return $token;
}
function verify_captcha(string $purpose): bool
{
    $token = (string) ($_POST['captcha_token'] ?? '');
    $answer = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($_POST['captcha_code'] ?? '')));
    $challenge = $_SESSION['captchas'][$token] ?? null;
    unset($_SESSION['captchas'][$token]);
    return is_array($challenge)
        && ($challenge['purpose'] ?? '') === $purpose
        && (int) ($challenge['expires'] ?? 0) >= time()
        && hash_equals((string) ($challenge['code'] ?? ''), $answer);
}
function captcha_image(): never
{
    $token = (string) ($_GET['token'] ?? '');
    $challenge = $_SESSION['captchas'][$token] ?? null;
    if (!is_array($challenge) || (int) ($challenge['expires'] ?? 0) < time()) {
        http_response_code(404);
        exit;
    }
    if (!empty($_GET['refresh'])) {
        $_SESSION['captchas'][$token]['code'] = captcha_code();
        $_SESSION['captchas'][$token]['expires'] = time() + 600;
        $challenge = $_SESSION['captchas'][$token];
    }
    $lines = '';
    for ($i = 0; $i < 7; $i++) {
        $lines .= '<line x1="' . random_int(0, 220) . '" y1="' . random_int(0, 70) . '" x2="' . random_int(0, 220) . '" y2="' . random_int(0, 70) . '" />';
    }
    $letters = '';
    foreach (str_split((string) $challenge['code']) as $i => $letter) {
        $x = 27 + ($i * 33);
        $y = random_int(43, 52);
        $letters .= '<text x="' . $x . '" y="' . $y . '" transform="rotate(' . random_int(-12, 12) . ' ' . $x . ' ' . $y . ')">' . $letter . '</text>';
    }
    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="220" height="70" viewBox="0 0 220 70"><rect width="220" height="70" rx="8" fill="#eef6f4"/><g stroke="#7bb8ab" stroke-width="1.5" opacity=".55">' . $lines . '</g><g fill="#15241f" font-family="ui-monospace,monospace" font-size="30" font-weight="800">' . $letters . '</g></svg>';
    exit;
}
function captcha_field(string $purpose): string
{
    $token = issue_captcha($purpose);
    return '<div class="form-row captcha-field"><label>Verification code</label><div class="captcha-image-row"><img src="?a=captcha&amp;token=' . h($token) . '" data-captcha-image data-captcha-source="?a=captcha&amp;token=' . h($token) . '" alt="Visual verification code"><button class="secondary captcha-refresh" type="button" data-captcha-refresh aria-label="Generate a new verification code" title="New code">&#8635;</button></div><input type="hidden" name="captcha_token" value="' . h($token) . '"><input name="captcha_code" maxlength="6" minlength="6" inputmode="text" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="Enter the code shown" required><p class="muted field-help">The code is not case-sensitive.</p></div>';
}
function login_attempt_key(string $login): string
{
    return hash('sha256', strtolower(trim($login)));
}
function login_failure_count(string $login): int
{
    if ($login === '') return 0;
    $key = login_attempt_key($login);
    $stmt = db()->prepare('SELECT failed_count,last_failed_at FROM login_attempts WHERE attempt_key=?');
    $stmt->execute([$key]);
    $attempt = $stmt->fetch();
    if (!$attempt) return 0;
    if ((string) $attempt['last_failed_at'] < gmdate('Y-m-d H:i:s', time() - 1800)) {
        db()->prepare('DELETE FROM login_attempts WHERE attempt_key=?')->execute([$key]);
        return 0;
    }
    return (int) $attempt['failed_count'];
}
function record_login_failure(string $login): int
{
    $key = login_attempt_key($login);
    db()->exec("DELETE FROM login_attempts WHERE last_failed_at < datetime('now','-1 day')");
    db()->prepare('INSERT INTO login_attempts (attempt_key,failed_count,last_failed_at) VALUES (?,1,?) ON CONFLICT(attempt_key) DO UPDATE SET failed_count=login_attempts.failed_count+1,last_failed_at=excluded.last_failed_at')->execute([$key, now()]);
    return login_failure_count($login);
}
function clear_login_failures(string $login): void
{
    db()->prepare('DELETE FROM login_attempts WHERE attempt_key=?')->execute([login_attempt_key($login)]);
}
function is_mobile_app_request(): bool
{
    return !empty($_POST['is_mobile_app']) || !empty($_GET['mobile_app']) || str_contains((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'com.myname.todoom');
}
function mobile_unlock_cookie_options(int $expires): array
{
    return ['expires' => $expires, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax'];
}
function set_mobile_unlock_cookie(string $selector, string $validator, int $expires): void
{
    setcookie('todoom_mobile_unlock', $selector . ':' . $validator, mobile_unlock_cookie_options($expires));
}
function clear_mobile_unlock_cookie(): void
{
    setcookie('todoom_mobile_unlock', '', mobile_unlock_cookie_options(time() - 3600));
}

function secure_request(): bool
{
    $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https' || str_contains((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"scheme":"https"');
}

function trusted_2fa_cookie_options(int $expires): array
{
    return ['expires' => $expires, 'path' => '/', 'secure' => secure_request(), 'httponly' => true, 'samesite' => 'Lax'];
}

function clear_trusted_2fa_cookie(): void
{
    setcookie('todoom_trusted_2fa', '', trusted_2fa_cookie_options(time() - 3600));
}

function issue_trusted_2fa_token(array $u): void
{
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + 60 * 60 * 24 * 21;
    db()->prepare('INSERT INTO trusted_2fa_tokens (user_id,selector,validator_hash,expires_at,created_at) VALUES (?,?,?,?,?)')
        ->execute([(int) $u['id'], hash('sha256', $selector), hash('sha256', $validator), gmdate('Y-m-d H:i:s', $expires), now()]);
    db()->prepare('DELETE FROM trusted_2fa_tokens WHERE user_id=? AND id NOT IN (SELECT id FROM trusted_2fa_tokens WHERE user_id=? ORDER BY created_at DESC LIMIT 5)')
        ->execute([(int) $u['id'], (int) $u['id']]);
    setcookie('todoom_trusted_2fa', $selector . ':' . $validator, trusted_2fa_cookie_options($expires));
}

function trusted_2fa_valid(array $u): bool
{
    $cookie = (string) ($_COOKIE['todoom_trusted_2fa'] ?? '');
    if (!str_contains($cookie, ':')) return false;
    [$selector, $validator] = explode(':', $cookie, 2);
    if (!preg_match('/^[a-f0-9]{18}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) return false;
    db()->prepare('DELETE FROM trusted_2fa_tokens WHERE expires_at<=?')->execute([now()]);
    $stmt = db()->prepare('SELECT * FROM trusted_2fa_tokens WHERE selector=? AND expires_at>?');
    $stmt->execute([hash('sha256', $selector), now()]);
    $token = $stmt->fetch();
    if (!$token || (int) $token['user_id'] !== (int) $u['id'] || !hash_equals((string) $token['validator_hash'], hash('sha256', $validator))) {
        clear_trusted_2fa_cookie();
        return false;
    }
    db()->prepare('UPDATE trusted_2fa_tokens SET last_used_at=? WHERE id=?')->execute([now(), $token['id']]);
    return true;
}

function revoke_trusted_2fa_tokens(int $userId): void
{
    db()->prepare('DELETE FROM trusted_2fa_tokens WHERE user_id=?')->execute([$userId]);
    clear_trusted_2fa_cookie();
}

function issue_mobile_unlock_token(array $u): void
{
    if ((int) ($u['mobile_biometric_enabled'] ?? 0) !== 1) {
        db()->prepare('UPDATE users SET mobile_biometric_enabled=1 WHERE id=?')->execute([$u['id']]);
        $u['mobile_biometric_enabled'] = 1;
    }
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + 60 * 60 * 24 * 30;
    db()->prepare('INSERT INTO mobile_unlock_tokens (user_id,selector,validator_hash,device_label,expires_at,created_at) VALUES (?,?,?,?,?,?)')->execute([
        $u['id'],
        hash('sha256', $selector),
        hash('sha256', $validator),
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Todoom Android'), 0, 160),
        gmdate('Y-m-d H:i:s', $expires),
        now(),
    ]);
    db()->prepare('DELETE FROM mobile_unlock_tokens WHERE user_id=? AND id NOT IN (SELECT id FROM mobile_unlock_tokens WHERE user_id=? ORDER BY created_at DESC LIMIT 5)')->execute([$u['id'], $u['id']]);
    set_mobile_unlock_cookie($selector, $validator, $expires);
}
function mobile_unlock_user(): ?array
{
    $cookie = (string) ($_COOKIE['todoom_mobile_unlock'] ?? '');
    if (!str_contains($cookie, ':')) return null;
    [$selector, $validator] = explode(':', $cookie, 2);
    if (!preg_match('/^[a-f0-9]{18}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) return null;
    db()->prepare('DELETE FROM mobile_unlock_tokens WHERE expires_at<=?')->execute([now()]);
    $stmt = db()->prepare('SELECT u.*, t.user_id unlock_user_id, t.selector unlock_selector, t.validator_hash unlock_validator_hash FROM mobile_unlock_tokens t JOIN users u ON u.id=t.user_id WHERE t.selector=? AND t.expires_at>? AND u.status="active" AND u.email_verified_at IS NOT NULL');
    $stmt->execute([hash('sha256', $selector), now()]);
    $row = $stmt->fetch();
    if (!$row || !hash_equals((string) $row['unlock_validator_hash'], hash('sha256', $validator)) || (int) ($row['mobile_biometric_enabled'] ?? 0) !== 1) {
        clear_mobile_unlock_cookie();
        return null;
    }
    return $row;
}
function has_mobile_unlock_token(): bool
{
    return mobile_unlock_user() !== null;
}
function mobile_unlock(): void
{
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'message' => 'Your session expired. Please sign in again.']);
        return;
    }
    $u = mobile_unlock_user();
    if (!$u) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Mobile unlock is not available. Please sign in once with your password.']);
        return;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) ($u['unlock_user_id'] ?? $u['id']);
    db()->prepare('UPDATE mobile_unlock_tokens SET last_used_at=? WHERE selector=?')->execute([now(), (string) $u['unlock_selector']]);
    log_activity($u, 'account.mobile_unlocked', 'Trusted Android fingerprint');
    echo json_encode(['ok' => true, 'redirect' => '?']);
}

function base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'todoom.quantumnet.space:78');
}
function app_url(string $query = ''): string
{
    return rtrim(setting('site_url', base_url()), '/') . '/' . ($query !== '' ? '?' . ltrim($query, '?') : '');
}
function setting(string $name, ?string $fallback = null): string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE name = ?');
    $stmt->execute([$name]);
    $value = $stmt->fetchColumn();
    return $value === false ? (string) $fallback : (string) $value;
}
function set_setting(string $name, string $value): void
{
    db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value')->execute([$name, $value]);
}
function flash(?string $msg = null, string $type = 'ok'): ?string
{
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return null; }
    if (empty($_SESSION['flash'])) return null;
    [$message, $type] = $_SESSION['flash']; unset($_SESSION['flash']);
    return '<div class="flash ' . h($type === 'error' ? 'error' : '') . '">' . h($message) . '</div>';
}
function format_date(?string $date): string
{
    if (!$date) return '';
    $day = substr($date, 0, 10);
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
    return $dt ? $dt->format('d/m/Y') : $day;
}
function form_date(?string $date): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) ? (string) $date : date('Y-m-d');
}
function created_at_from_date(string $date, ?string $fallback = null): string
{
    $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    $time = $fallback && preg_match('/\d{2}:\d{2}:\d{2}$/', $fallback) ? substr($fallback, 11, 8) : gmdate('H:i:s');
    return $day . ' ' . $time;
}
function next_app_version(string $version): string
{
    if (!preg_match('/^(\d+)\.(\d+)$/', $version, $parts)) return INITIAL_APP_VERSION;
    $major = (int) $parts[1];
    $minor = (int) $parts[2];
    return $minor >= 50 ? ($major + 1) . '.0' : $major . '.' . ($minor + 1);
}
function sync_release(PDO $db): void
{
    $stmt = $db->prepare('SELECT value FROM settings WHERE name=?');
    $stmt->execute(['app_release_id']);
    if ((string) $stmt->fetchColumn() === APP_RELEASE_ID) return;
    $stmt->execute(['app_version']);
    $version = next_app_version((string) ($stmt->fetchColumn() ?: INITIAL_APP_VERSION));
    $save = $db->prepare('INSERT INTO settings (name,value) VALUES (?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value');
    foreach (['app_version' => $version, 'last_patched' => APP_RELEASE_DATE, 'app_release_id' => APP_RELEASE_ID] as $name => $value) {
        $save->execute([$name, $value]);
    }
}
function country_code(?string $code): string
{
    $code = strtoupper((string) $code);
    return in_array($code, explode(' ', COUNTRY_CODES), true) ? $code : 'GB';
}
function country_flag(?string $code): string
{
    $code = country_code($code);
    return html_entity_decode('&#' . (127397 + ord($code[0])) . ';&#' . (127397 + ord($code[1])) . ';', ENT_NOQUOTES, 'UTF-8');
}
function countries(): array
{
    static $countries = null;
    if (is_array($countries)) return $countries;
    foreach (explode(' ', COUNTRY_CODES) as $code) {
        $name = class_exists(Locale::class) ? Locale::getDisplayRegion('und_' . $code, 'en') : $code;
        $countries[$code] = is_string($name) && $name !== '' ? $name : $code;
    }
    asort($countries, SORT_NATURAL | SORT_FLAG_CASE);
    return $countries;
}
function country_options(?string $selected): string
{
    $selected = country_code($selected);
    $html = '';
    foreach (countries() as $code => $name) {
        $html .= '<option value="' . h($code) . '" ' . ($code === $selected ? 'selected' : '') . '>' . h(country_flag($code) . ' ' . $name) . '</option>';
    }
    return $html;
}
function app_languages(): array
{
    return ['en' => 'English', 'fr' => 'Français', 'es' => 'Español', 'de' => 'Deutsch', 'it' => 'Italiano', 'pt' => 'Português', 'el' => 'Ελληνικά', 'ro' => 'Română', 'ru' => 'Русский', 'tr' => 'Türkçe'];
}
function overdue_notify_options(): array
{
    return ['none' => 'Off', 'notifications' => 'Notifications', 'email' => 'E-mail'];
}
function overdue_notify_method(array $u): string
{
    $method = (string) ($u['overdue_notify_method'] ?? 'notifications');
    return array_key_exists($method, overdue_notify_options()) ? $method : 'notifications';
}
function password_strength(string $password): array
{
    $missing = [];
    if (strlen($password) < 12) $missing[] = 'at least 12 characters';
    if (!preg_match('/[a-z]/', $password)) $missing[] = 'a lowercase letter';
    if (!preg_match('/[A-Z]/', $password)) $missing[] = 'an uppercase letter';
    if (!preg_match('/\d/', $password)) $missing[] = 'a number';
    if (!preg_match('/[^A-Za-z0-9]/', $password)) $missing[] = 'a symbol';
    return [$missing === [], $missing];
}
function password_help(): string
{
    return '<div class="password-security" data-password-security data-score="0"><div class="password-meter" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div><p class="password-warning" data-password-warning>Use at least 12 characters with uppercase, lowercase, a number, and a symbol.</p></div>';
}
function mail_delivery_note(): string
{
    return '<p class="muted mail-delivery-note">Can\'t find the email? Check your spam or junk folder for messages from Todoom.</p>';
}
function avatar_src(array $u): string
{
    if (!empty($u['avatar_url'])) return (string) $u['avatar_url'];
    if (!empty($u['avatar_path'])) return (string) $u['avatar_path'];
    return '';
}
function avatar_html(array $u, string $class = 'avatar'): string
{
    $src = avatar_src($u);
    if ($src !== '') return '<img class="' . h($class) . '" src="' . h($src) . '" alt="">';
    return '<span class="' . h($class . ' avatar-fallback') . '">' . h(strtoupper(substr((string) $u['username'], 0, 1))) . '</span>';
}
function valid_avatar_url(string $url): bool
{
    return $url === '' || (filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true));
}
function save_avatar_upload(array $u): ?string
{
    if (empty($_FILES['avatar_file']['tmp_name']) || ($_FILES['avatar_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($_FILES['avatar_file']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || (int) ($_FILES['avatar_file']['size'] ?? 0) > 2 * 1024 * 1024) {
        flash('Upload an avatar image smaller than 2MB.', 'error');
        redirect('?a=profile');
    }
    $info = @getimagesize($_FILES['avatar_file']['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) {
        flash('Avatar must be a JPG, PNG, GIF, or WebP image.', 'error');
        redirect('?a=profile');
    }
    $dir = __DIR__ . '/assets/uploads/avatars';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = 'user-' . (int) $u['id'] . '-' . bin2hex(random_bytes(8)) . '.' . $types[$info[2]];
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($_FILES['avatar_file']['tmp_name'], $dest)) {
        flash('Avatar upload failed. Please try again.', 'error');
        redirect('?a=profile');
    }
    return 'assets/uploads/avatars/' . $name;
}
function list_icon_src(array $list): string
{
    if (!empty($list['icon_url'])) return (string) $list['icon_url'];
    if (!empty($list['icon_path'])) return (string) $list['icon_path'];
    return '';
}
function list_icon_html(array $list, string $class = 'list-icon'): string
{
    $src = list_icon_src($list);
    if ($src !== '') return '<img class="' . h($class) . '" src="' . h($src) . '" alt="" referrerpolicy="no-referrer">';
    return '<i class="' . h($class . ' swatch') . '" style="background:' . h($list['color'] ?? COLORS[0]) . '"></i>';
}
function save_list_icon_upload(array $u): ?string
{
    if (empty($_FILES['icon_file']['tmp_name']) || ($_FILES['icon_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($_FILES['icon_file']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || (int) ($_FILES['icon_file']['size'] ?? 0) > 2 * 1024 * 1024) {
        flash('Upload a list icon smaller than 2MB.', 'error');
        redirect($_SERVER['REQUEST_URI']);
    }
    $info = @getimagesize($_FILES['icon_file']['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) {
        flash('List icon must be a JPG, PNG, GIF, or WebP image.', 'error');
        redirect($_SERVER['REQUEST_URI']);
    }
    $dir = __DIR__ . '/assets/uploads/list-icons';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = 'list-' . (int) $u['id'] . '-' . bin2hex(random_bytes(8)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($_FILES['icon_file']['tmp_name'], $dir . '/' . $name)) {
        flash('List icon upload failed. Please try again.', 'error');
        redirect($_SERVER['REQUEST_URI']);
    }
    return 'assets/uploads/list-icons/' . $name;
}
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND status = "active"');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}
function require_login(): array
{
    $u = current_user();
    if (!$u) { flash('Please sign in first.', 'error'); redirect('?a=login'); }
    return $u;
}
function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') { http_response_code(403); exit('Forbidden.'); }
    return $u;
}

function toggle_theme(): void
{
    $next = ($_COOKIE['theme'] ?? '') === 'dark' ? 'light' : 'dark';
    setcookie('theme', $next, ['expires' => time() + 31536000, 'path' => '/', 'secure' => false, 'httponly' => false, 'samesite' => 'Lax']);
    redirect($_SERVER['HTTP_REFERER'] ?? '?');
}

function layout(string $title, string $content): void
{
    $u = current_user();
    $defaultTheme = setting('site_default_theme', 'light') === 'dark' ? 'dark' : 'light';
    $theme = in_array($_COOKIE['theme'] ?? '', ['light', 'dark'], true) ? (string) $_COOKIE['theme'] : $defaultTheme;
    $languages = app_languages();
    $defaultLanguage = isset($languages[setting('site_default_language', 'en')]) ? setting('site_default_language', 'en') : 'en';
    $language = isset($languages[$_COOKIE['language'] ?? '']) ? (string) $_COOKIE['language'] : $defaultLanguage;
    $languageOptions = select_options($languages, $language);
    $mobileLock = $u && (int) ($u['mobile_biometric_enabled'] ?? 0) === 1 ? ' data-mobile-session-lock data-mobile-lock-csrf="' . h(csrf()) . '"' : '';
    echo '<!doctype html><html lang="' . h($language) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' - ' . APP_NAME . '</title><link rel="icon" href="assets/icon.svg"><link rel="stylesheet" href="assets/style.css?v=20260812-mobile-archive-shortcuts"></head><body data-theme="' . h($theme) . '"' . $mobileLock . '>';
    echo '<div class="shell"><header class="topbar"><a class="brand" href="?"><img src="assets/icon.svg" alt=""><span>' . APP_NAME . '</span></a><nav class="nav">';
    if ($u) echo '<a href="?">Dashboard</a><a href="?a=calendar">Calendar</a><a href="?a=task-new">New task</a><a href="?a=list-new">New list</a>';
    if ($u) {
        $adminLink = $u['role'] === 'admin' ? '<a href="?a=admin">Admin Panel</a>' : '';
        echo '<details class="user-menu" data-user-menu><summary>' . avatar_html($u, 'nav-avatar') . '<span class="user-content">' . h($u['username']) . '</span><span class="menu-chevron" aria-hidden="true">&#8964;</span></summary><div class="user-menu-panel"><a href="?a=profile">Preferences</a><a href="?a=archived-lists">Archive</a>' . $adminLink . '<button type="button" data-version-log-open>Version</button><a href="?a=logout">Sign Out</a></div></details>';
    } else {
        echo '<a href="?a=login">Sign in</a>';
        if (setting('allow_registration', '1') === '1') echo '<a href="?a=register">Register</a>';
    }
    $mobileBottomNav = $u ? '<nav class="mobile-bottom-nav" aria-label="Mobile navigation"><a href="?"><span>Home</span></a><a href="?a=calendar"><span>Calendar</span></a><a class="bottom-add" href="?a=task-new" aria-label="New task">+</a><a href="#mobile-lists"><span>Lists</span></a><a href="?a=profile"><span>Settings</span></a></nav>' : '';
    echo '</nav></header><main class="wrap">' . (flash() ?? '') . $content . '</main>' . $mobileBottomNav . '<footer class="footer"><button class="footer-brand version-opener" type="button" data-version-log-open><img src="assets/icon.svg" alt=""><span>&copy; 2026 Todoom v' . h(setting('app_version', INITIAL_APP_VERSION)) . '</span></button><p><a href="mailto:mariuserbanica@proton.me">mariuserbanica@proton.me</a> <span>&middot;</span> <a href="https://mariusserbanica.co.uk/" target="_blank" rel="noopener noreferrer">mariusserbanica.co.uk</a></p></footer></div>' . changelog_html() . '<script src="assets/i18n.js?v=20260807-admin-order"></script><script src="assets/app.js?v=20260810-update-headsup"></script></body></html>';
}

function changelog_html(): string
{
    return '<dialog class="version-dialog" data-version-log aria-labelledby="version-log-title"><div class="version-dialog-shell"><header class="version-dialog-header"><div><p class="version-kicker">Todoom</p><h2 id="version-log-title">Release History</h2></div><button class="icon-button" type="button" data-version-log-close aria-label="Close version history" title="Close">&times;</button></header><div class="release-list">'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.21</h3><time datetime="2026-08-12">12/08/2026</time></div><section class="release-group patched"><h4>Patched</h4><ul><li>Removed mobile Archive shortcuts so archived lists are reached from the user dropdown only.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.20</h3><time datetime="2026-08-12">12/08/2026</time></div><section class="release-group patched"><h4>Patched</h4><ul><li>Dashboard totals now include tasks from archived lists.</li><li>Archived lists can be opened directly to view their tasks while remaining hidden from active task views.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.19</h3><time datetime="2026-08-12">12/08/2026</time></div><section class="release-group patched"><h4>Patched</h4><ul><li>Archive moved from the main navigation into the user dropdown.</li><li>The mobile app bottom navigation now includes direct access to archived lists.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.18</h3><time datetime="2026-08-12">12/08/2026</time></div><section class="release-group added"><h4>Added</h4><ul><li>Lists can now be archived from the active dashboard.</li><li>Archived Lists page lets users review, edit, delete, and reactivate archived lists.</li></ul></section><section class="release-group patched"><h4>Patched</h4><ul><li>Archived list tasks are hidden from active task views, stats, reminders, calendar counts, and list reordering until restored.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.17</h3><time datetime="2026-08-10">10/08/2026</time></div><section class="release-group added"><h4>Added</h4><ul><li>Calendar tab with month and day views for due tasks.</li><li>Highlighted days and months show where tasks are scheduled.</li></ul></section><section class="release-group patched"><h4>Patched</h4><ul><li>Language selection moved from the navigation bar into User Settings preferences.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.16</h3><time datetime="2026-08-10">10/08/2026</time></div><section class="release-group fixed"><h4>Fixed</h4><ul><li>List icons now display larger in the web list sidebar and mobile list manager.</li><li>The selected list icon is larger on phone screens so the active list stands out.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.15</h3><time datetime="2026-08-10">10/08/2026</time></div><section class="release-group fixed"><h4>Fixed</h4><ul><li>Rebuilt the phone dashboard as a simpler mobile app layout with a list selector, compact task cards, and bottom navigation.</li><li>Removed the cramped mobile side-panel layout from the installed Android app view.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.13</h3><time datetime="2026-08-08">08/08/2026</time></div><section class="release-group fixed"><h4>Fixed</h4><ul><li>Older installed Todoom Android apps now still show the update prompt even before they have the native version-check plugin.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.12</h3><time datetime="2026-08-08">08/08/2026</time></div><section class="release-group added"><h4>Added</h4><ul><li>Installed Todoom Android app now checks Quantum Appstore for newer APK versions and shows an update popup.</li><li>The popup opens Quantum Appstore so the update can be installed from the private catalogue.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.11</h3><time datetime="2026-08-08">08/08/2026</time></div><section class="release-group fixed"><h4>Fixed</h4><ul><li>Android app sessions now persist after closing the app instead of forcing username and password login each time.</li><li>The fingerprint unlock cache is now short-lived, so reopening the app asks for fingerprint again while normal navigation stays smooth.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.14</h3><time datetime="2026-08-10">10/08/2026</time></div><section class="release-group added"><h4>Added</h4><ul><li>Phone-first dashboard with task content shown before list management.</li><li>Mobile list switcher, collapsible filters, compact task cards, and a bottom New Task action.</li></ul></section><section class="release-group fixed"><h4>Fixed</h4><ul><li>Mobile navigation no longer forces users through the full desktop list column before reaching tasks.</li><li>Task controls, status filters, and list chips now use easier thumb targets on narrow screens.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.10</h3><time datetime="2026-08-08">08/08/2026</time></div><section class="release-group added"><h4>Added</h4><ul><li>Trusted Android app unlock keeps users signed in for up to 30 days after the first password login.</li><li>Opening Todoom on Android now asks for fingerprint or device unlock instead of requiring password login each time.</li></ul></section><section class="release-group patched"><h4>Patched</h4><ul><li>Signing out removes the trusted mobile unlock token from that device.</li><li>The login page can now unlock directly with fingerprint when the server session has expired but the trusted mobile token is still valid.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.9</h3><time datetime="2026-08-08">08/08/2026</time></div><section class="release-group added"><h4>Added</h4><ul><li>Android heads-up reminders for tasks due today, due tomorrow, and overdue tasks.</li><li>Mobile in-app reminder popup for due soon and overdue tasks when Todoom is open.</li></ul></section><section class="release-group patched"><h4>Patched</h4><ul><li>Due-soon reminders are tracked separately from overdue reminders so both can alert at the right time.</li><li>The Android reminder notification channel now uses high-importance sound and vibration behavior for heads-up banners.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.8</h3><time datetime="2026-08-08">08/08/2026</time></div><section class="release-group added"><h4>Added</h4><ul><li>Overdue task attention panel and browser popups on the web app.</li><li>User preference for overdue reminders: off, notifications, or e-mail.</li><li>Android app support for native overdue task notifications.</li></ul></section><section class="release-group patched"><h4>Patched</h4><ul><li>Overdue e-mail reminders are sent once per overdue task until the task is edited.</li><li>Browser and mobile notification reminders are marked after delivery to avoid repeated alerts.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.7</h3><time datetime="2026-08-08">08/08/2026</time></div><section class="release-group fixed"><h4>Fixed</h4><ul><li>Mobile account menu now opens reliably from the username/avatar button.</li><li>List taps now jump directly to the selected list tasks on mobile.</li><li>Todoom Android now uses the Todoom launcher icon instead of the Quantum Appstore icon.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.6</h3><time datetime="2026-08-08">08/08/2026</time></div><section class="release-group added"><h4>Added</h4><ul><li>Mobile fingerprint/device-unlock login can replace the 2FA code inside the installed Todoom Android app after the account password is accepted.</li><li>A protected user setting controls whether mobile biometric login is allowed for the account.</li></ul></section><section class="release-group fixed"><h4>Fixed</h4><ul><li>The phone layout now uses compact navigation, thumb-friendly task cards, and better spacing in forms, filters, lists, and settings.</li></ul></section><section class="release-group patched"><h4>Patched</h4><ul><li>Mobile-only biometric login still requires the current account password before the biometric step.</li><li>Dashboard controls now wrap more predictably on narrow screens.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.5</h3><time datetime="2026-08-07">07/08/2026</time></div><section class="release-group added"><h4>Added</h4><ul><li>Draggable list ordering with saved positions.</li><li>Administrator controls for default theme, default language, users, and activity history.</li><li>A username menu for preferences, administration, versions, and sign out.</li><li>A live password strength meter with specific security guidance.</li></ul></section><section class="release-group fixed"><h4>Fixed</h4><ul><li>Selected lists now show their name and custom icon above the task view.</li></ul></section><section class="release-group patched"><h4>Patched</h4><ul><li>Account, list, and task activity is now recorded for administrator review.</li><li>Theme controls use a compact settings layout.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.4</h3><time datetime="2026-08-07">07/08/2026</time></div><section class="release-group added"><h4>Added</h4><ul><li>Spam-folder guidance after registration and password recovery emails.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.3</h3><time datetime="2026-08-07">07/08/2026</time></div><section class="release-group added"><h4>Added</h4><ul><li>Email password recovery with secure, single-use reset links.</li><li>Completion bars beneath every list name.</li><li>Task descriptions expand only when selected.</li></ul></section><section class="release-group patched"><h4>Patched</h4><ul><li>Password reset links expire after one hour and account lookup remains private.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.2</h3><time datetime="2026-08-07">07/08/2026</time></div><section class="release-group added"><h4>Added</h4><ul><li>Visual verification codes are required when registering an account.</li><li>Login verification appears after two incorrect password attempts.</li></ul></section><section class="release-group patched"><h4>Patched</h4><ul><li>Failed login attempts are tracked per login for 30 minutes.</li><li>Verification challenges are single-use, expire after 10 minutes, and can be refreshed.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.1</h3><time datetime="2026-08-07">07/08/2026</time></div><section class="release-group added"><h4>Added</h4><ul><li>Interactive release history opened from version links.</li><li>Detailed Added, Fixed, and Patched notes for every Todoom release.</li></ul></section><section class="release-group patched"><h4>Patched</h4><ul><li>Advanced the release counter once for this update.</li><li>Added translated changelog controls and release labels.</li></ul></section></article>'
        . '<article class="release-entry"><div class="release-heading"><h3>v1.0</h3><time datetime="2026-08-07">07/08/2026</time></div><section class="release-group added"><h4>Added</h4><ul><li>Multiple todo lists with custom colors, icons, task editing, priorities, dates, tags, filters, and bulk actions.</li><li>User and administrator accounts with email confirmation and QR-based two-factor authentication.</li><li>Profile pictures, country flags, light and dark themes, and ten interface languages.</li></ul></section><section class="release-group fixed"><h4>Fixed</h4><ul><li>Completed tasks remain visible in Done and All views.</li><li>Login token validation and theme switching now work reliably.</li></ul></section><section class="release-group patched"><h4>Patched</h4><ul><li>Standardized displayed dates to DD/MM/YYYY.</li><li>Strengthened password guidance and protected sensitive account changes with the current password.</li><li>Introduced persistent release tracking with automatic version rollover.</li></ul></section></article>'
        . '</div></div></dialog>';
}

function setup(): void
{
    if (users_exist()) redirect('?');
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $username = trim($_POST['username'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
            $error = 'Use a username, valid email, and password of at least 10 characters.';
        } else {
            $stmt = db()->prepare('INSERT INTO users (username,email,password_hash,role,email_verified_at,created_at) VALUES (?,?,?,"admin",?,?)');
            $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), now(), now()]);
            $_SESSION['user_id'] = (int) db()->lastInsertId();
            seed_default_list($_SESSION['user_id']);
            log_activity(['id' => $_SESSION['user_id'], 'username' => $username], 'account.created', 'Administrator');
            flash('Admin account created. Welcome to Todoom.');
            redirect('?');
        }
    }
    layout('Set up', '<section class="panel panel-pad"><h1>Set Up Todoom</h1><p class="muted">Create the first verified administrator account.</p>' . ($error ? '<div class="flash error">' . h($error) . '</div>' : '') . '<form method="post" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><div class="form-row"><label>Username</label><input name="username" required></div><div class="form-row"><label>Email</label><input type="email" name="email" required></div><div class="form-row"><label>Password</label><input type="password" name="password" minlength="10" required data-password-check>' . password_help() . '</div><button>Create admin</button></form></section>');
}

function seed_default_list(int $userId): int
{
    $stmt = db()->prepare('INSERT INTO lists (user_id,name,color,position,created_at,updated_at) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$userId, 'Inbox', COLORS[0], 1, now(), now()]);
    return (int) db()->lastInsertId();
}

function dashboard(): void
{
    $u = require_login();
    $lists = user_lists($u);
    if (!$lists) { seed_default_list((int) $u['id']); $lists = user_lists($u); }
    $activeList = (int) ($_GET['list'] ?? 0);
    $activeListData = $activeList ? maybe_find_list($activeList, $u) : null;
    if ($activeList && !$activeListData) $activeList = 0;
    $requestedStatus = $_GET['status'] ?? 'open';
    $status = in_array($requestedStatus, ['open', 'done', 'all', 'overdue'], true) ? $requestedStatus : 'open';
    $q = trim($_GET['q'] ?? '');
    $requestedPriorityOrder = $_GET['priority_order'] ?? '';
    $priorityOrder = in_array($requestedPriorityOrder, ['high_low', 'low_high'], true) ? $requestedPriorityOrder : '';
    $requestedDateType = $_GET['date_type'] ?? 'due';
    $dateType = in_array($requestedDateType, ['due', 'added'], true) ? $requestedDateType : 'due';
    $filterDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['filter_date'] ?? '')) ? (string) $_GET['filter_date'] : '';
    $tasks = tasks_for($u, $activeList, $status, $q, $priorityOrder, $dateType, $filterDate);
    $stats = stats_for($u);
    $notifyMethod = overdue_notify_method($u);
    if ($notifyMethod === 'email') process_due_email_notifications($u);
    $overdueTasks = overdue_tasks_for($u);
    $dueSoonTasks = due_soon_tasks_for($u);
    $notificationTasks = $notifyMethod === 'notifications' ? array_merge(overdue_tasks_for($u, true), due_soon_tasks_for($u, true)) : [];
    $overdueAttention = overdue_attention_html(array_merge($overdueTasks, $dueSoonTasks), $notificationTasks, $notifyMethod);
    $sidebar = '<aside class="panel panel-pad list-panel"><div class="sidebar-heading"><h2>Lists</h2><span class="drag-help">Drag to reorder</span></div><div class="list-nav"><a class="list-link ' . ($activeList === 0 ? 'active' : '') . '" href="?#tasks"><span>All active</span><span class="pill">' . (int) $stats['open'] . '</span></a><a class="list-link archive-link" href="?a=archived-lists"><span>Archived lists</span><span class="pill">' . count(archived_lists($u)) . '</span></a><div class="sortable-lists" data-list-sort data-reorder-url="?a=list-reorder" data-csrf="' . h(csrf()) . '">';
    foreach ($lists as $list) {
        $completion = (int) $list['total_count'] > 0 ? (int) round(((int) $list['done_count'] / (int) $list['total_count']) * 100) : 0;
        $listStats = '<div class="list-counts"><span><strong>' . (int) $list['overdue_count'] . '</strong> Overdue</span><span><strong>' . (int) $list['open_count'] . '</strong> Open</span><span><strong>' . (int) $list['done_count'] . '</strong> Done</span><span><strong>' . (int) $list['total_count'] . '</strong> Total</span></div>';
        $progress = '<span class="list-progress-line"><span class="list-progress" role="progressbar" aria-label="Completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $completion . '" style="--list-progress:' . h($list['color']) . '"><span style="width:' . $completion . '%"></span></span><small>' . $completion . '%</small></span>';
        $sidebar .= '<div class="list-item" draggable="true" data-list-id="' . (int) $list['id'] . '"><a class="list-link ' . ($activeList === (int) $list['id'] ? 'active' : '') . '" href="?list=' . (int) $list['id'] . '#tasks"><span class="drag-handle" title="Drag to reorder" aria-hidden="true">&#8942;&#8942;</span><span class="list-link-content"><span class="list-identity">' . list_icon_html($list) . '<span class="user-content">' . h($list['name']) . '</span></span>' . $progress . '</span><span class="pill">' . (int) $list['open_count'] . '</span></a>' . $listStats . '<div class="list-actions"><a href="?a=list-edit&id=' . (int) $list['id'] . '">Edit</a><a href="?a=list-archive&id=' . (int) $list['id'] . '&csrf=' . h(csrf()) . '" data-confirm="Archive this list? Its tasks will be hidden from active views.">Archive</a><a class="danger-link" data-confirm="Delete this list and all its tasks?" href="?a=list-delete&id=' . (int) $list['id'] . '&csrf=' . h(csrf()) . '">Delete</a></div></div>';
    }
    $sidebar .= '</div></div><br><a class="btn secondary" href="?a=list-new">New list</a></aside>';
    $releasePanel = '<section class="panel version-panel" aria-label="Todoom release information"><div><span>Version</span><button class="version-value" type="button" data-version-log-open>v' . h(setting('app_version', INITIAL_APP_VERSION)) . '</button></div><div><span>Last patched</span><strong>' . h(setting('last_patched', APP_RELEASE_DATE)) . '</strong></div></section>';
    $sidebar = '<div class="sidebar-stack">' . $sidebar . $releasePanel . '</div>';
    $statusTabs = '<div class="btn-row status-tabs"><a class="btn ' . ($status === 'open' ? '' : 'secondary') . '" href="' . h(dashboard_url($activeList, 'open', $q, $priorityOrder, $dateType, $filterDate)) . '">Open</a><a class="btn ' . ($status === 'done' ? '' : 'secondary') . '" href="' . h(dashboard_url($activeList, 'done', $q, $priorityOrder, $dateType, $filterDate)) . '">Done</a><a class="btn ' . ($status === 'all' ? '' : 'secondary') . '" href="' . h(dashboard_url($activeList, 'all', $q, $priorityOrder, $dateType, $filterDate)) . '">All</a><a class="btn ' . ($status === 'overdue' ? '' : 'secondary') . '" href="' . h(dashboard_url($activeList, 'overdue', $q, $priorityOrder, $dateType, $filterDate)) . '">Overdue</a></div>';
    $filters = '<form class="filters-grid" method="get"><div class="form-row"><label>Search</label><input name="q" value="' . h($q) . '" placeholder="Search tasks, notes, tags"></div><div class="form-row"><label>Status</label><select name="status">' . select_options(['open' => 'Open', 'done' => 'Done', 'all' => 'All', 'overdue' => 'Overdue'], $status) . '</select></div><div class="form-row"><label>Priority order</label><select name="priority_order">' . select_options(['' => 'Default', 'high_low' => 'High to low', 'low_high' => 'Low to high'], $priorityOrder) . '</select></div><div class="form-row"><label>Date field</label><select name="date_type">' . select_options(['due' => 'Due date', 'added' => 'Added date'], $dateType) . '</select></div><div class="form-row"><label>Date</label><input type="date" name="filter_date" value="' . h($filterDate) . '"></div>' . ($activeList ? '<input type="hidden" name="list" value="' . $activeList . '">' : '') . '<div class="btn-row filter-actions"><button>Filter</button><a class="btn secondary" href="' . h($activeList ? '?list=' . $activeList : '?') . '">Reset</a></div></form>';
    $currentListLabel = $activeListData && (int) ($activeListData['archived'] ?? 0) === 1 ? 'Archived list' : 'Current list';
    $taskCreateUrl = '?a=task-new' . ($activeList && $activeListData && (int) ($activeListData['archived'] ?? 0) === 0 ? '&list=' . $activeList : '');
    $currentHeading = $activeListData
        ? '<div class="current-list-heading">' . list_icon_html($activeListData, 'current-list-icon') . '<div><h1 class="user-content">' . h($activeListData['name']) . '</h1><p class="muted">' . h($currentListLabel) . '</p></div></div>'
        : '<div><h1>Your Tasks</h1><p class="muted">Plan it, tick it, move on.</p></div>';
    $hasActiveFilters = $q !== '' || $priorityOrder !== '' || $dateType !== 'due' || $filterDate !== '';
    $filterSummaryText = $hasActiveFilters ? 'Filters active' : 'Filter & sort';
    $listOptions = '<option value="0"' . ($activeList === 0 ? ' selected' : '') . '>All lists</option>';
    if ($activeListData && (int) ($activeListData['archived'] ?? 0) === 1) {
        $listOptions .= '<option value="' . (int) $activeListData['id'] . '" selected>' . h($activeListData['name'] . ' (archived)') . '</option>';
    }
    $mobileManageRows = '<div class="mobile-list-manager-rows">';
    foreach ($lists as $list) {
        $listOptions .= '<option value="' . (int) $list['id'] . '"' . ($activeList === (int) $list['id'] ? ' selected' : '') . '>' . h($list['name']) . '</option>';
        $mobileManageRows .= '<div class="mobile-list-manager-row"><span class="list-identity">' . list_icon_html($list) . '<strong class="user-content">' . h($list['name']) . '</strong></span><span class="muted">' . (int) $list['open_count'] . ' open</span><a href="?list=' . (int) $list['id'] . '#tasks">Open</a><a href="?a=list-edit&id=' . (int) $list['id'] . '">Edit</a><a href="?a=list-archive&id=' . (int) $list['id'] . '&csrf=' . h(csrf()) . '" data-confirm="Archive this list?">Archive</a></div>';
    }
    $mobileManageRows .= '</div>';
    $mobileTop = '<section class="mobile-home-panel"><div class="mobile-title-row">' . $currentHeading . '</div><form class="mobile-list-picker" method="get"><label>List</label><select name="list" data-mobile-list-select>' . $listOptions . '</select></form><div class="mobile-stats-row"><a href="' . h(dashboard_url($activeList, 'open', $q, $priorityOrder, $dateType, $filterDate)) . '"><strong>' . (int) $stats['open'] . '</strong><span>Open</span></a><a href="' . h(dashboard_url($activeList, 'done', $q, $priorityOrder, $dateType, $filterDate)) . '"><strong>' . (int) $stats['done'] . '</strong><span>Done</span></a><a href="' . h(dashboard_url($activeList, 'overdue', $q, $priorityOrder, $dateType, $filterDate)) . '"><strong>' . (int) $stats['overdue'] . '</strong><span>Late</span></a><span><strong>' . (int) $stats['today'] . '</strong><span>Today</span></span></div></section>';
    $mobileListManager = '<details class="mobile-list-manager" id="mobile-lists"><summary>Manage lists</summary>' . $mobileManageRows . '<div class="btn-row"><a class="btn secondary" href="?a=list-new">New list</a></div></details>';
    $main = $overdueAttention . $mobileTop . '<section class="grid stats dashboard-stats"><a class="panel stat" href="' . h(dashboard_url($activeList, 'open', $q, $priorityOrder, $dateType, $filterDate)) . '"><span class="muted">Open</span><strong>' . (int) $stats['open'] . '</strong></a><a class="panel stat" href="' . h(dashboard_url($activeList, 'done', $q, $priorityOrder, $dateType, $filterDate)) . '"><span class="muted">Done</span><strong>' . (int) $stats['done'] . '</strong></a><a class="panel stat" href="' . h(dashboard_url($activeList, 'overdue', $q, $priorityOrder, $dateType, $filterDate)) . '"><span class="muted">Overdue</span><strong>' . (int) $stats['overdue'] . '</strong></a><div class="panel stat"><span class="muted">Due today</span><strong>' . (int) $stats['today'] . '</strong></div></section><br><section class="panel panel-pad task-panel" id="tasks"><div class="page-title">' . $currentHeading . '<a class="btn new-task-top" href="' . h($taskCreateUrl) . '">New task</a></div>' . $statusTabs . '<details class="filter-drawer"' . ($hasActiveFilters ? ' open' : '') . ' data-filter-drawer><summary><span>' . h($filterSummaryText) . '</span><small>Search, priority, dates</small></summary>' . $filters . '</details>' . task_list($tasks) . '</section>' . $mobileListManager;
    layout('Dashboard', '<div class="grid two dashboard-layout">' . $sidebar . '<div class="dashboard-main">' . $main . '</div></div>');
}

function dashboard_url(int $listId = 0, string $status = 'open', string $q = '', string $priorityOrder = '', string $dateType = 'due', string $filterDate = ''): string
{
    $params = [];
    if ($listId) $params['list'] = $listId;
    if ($status !== 'open') $params['status'] = $status;
    if ($q !== '') $params['q'] = $q;
    if ($priorityOrder !== '') $params['priority_order'] = $priorityOrder;
    if ($dateType !== 'due') $params['date_type'] = $dateType;
    if ($filterDate !== '') $params['filter_date'] = $filterDate;
    return '?' . http_build_query($params);
}

function calendar_date(string $value): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    return $date && $date->format('Y-m-d') === $value ? $date : new DateTimeImmutable('today', new DateTimeZone('UTC'));
}
function calendar_url(string $view, DateTimeImmutable $date): string
{
    return '?a=calendar&view=' . rawurlencode($view) . '&date=' . rawurlencode($date->format('Y-m-d'));
}
function calendar_counts_by_day(array $u, string $start, string $end): array
{
    $stmt = db()->prepare('SELECT t.due_date, COUNT(*) total, SUM(CASE WHEN t.status="open" THEN 1 ELSE 0 END) open_count FROM tasks t JOIN lists l ON l.id=t.list_id WHERE t.user_id=? AND l.archived=0 AND t.due_date BETWEEN ? AND ? GROUP BY t.due_date');
    $stmt->execute([$u['id'], $start, $end]);
    $counts = [];
    foreach ($stmt as $row) {
        $counts[(string) $row['due_date']] = ['total' => (int) $row['total'], 'open' => (int) $row['open_count']];
    }
    return $counts;
}
function calendar_counts_by_month(array $u, int $year): array
{
    $stmt = db()->prepare('SELECT substr(t.due_date,1,7) ym, COUNT(*) total, SUM(CASE WHEN t.status="open" THEN 1 ELSE 0 END) open_count FROM tasks t JOIN lists l ON l.id=t.list_id WHERE t.user_id=? AND l.archived=0 AND t.due_date BETWEEN ? AND ? GROUP BY ym');
    $stmt->execute([$u['id'], sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)]);
    $counts = [];
    foreach ($stmt as $row) {
        $counts[(string) $row['ym']] = ['total' => (int) $row['total'], 'open' => (int) $row['open_count']];
    }
    return $counts;
}
function calendar_tasks_for_day(array $u, string $date): array
{
    $stmt = db()->prepare('SELECT t.*, l.name list_name, l.color list_color, l.icon_path list_icon_path, l.icon_url list_icon_url FROM tasks t JOIN lists l ON l.id=t.list_id WHERE t.user_id=? AND l.archived=0 AND t.due_date=? ORDER BY t.status, CASE t.priority WHEN "high" THEN 1 WHEN "medium" THEN 2 ELSE 3 END, t.created_at DESC');
    $stmt->execute([$u['id'], $date]);
    return $stmt->fetchAll();
}
function calendar_month_task_count(array $counts): int
{
    return array_sum(array_map(fn($row) => (int) $row['total'], $counts));
}
function calendar_view(): void
{
    $u = require_login();
    $mode = ($_GET['view'] ?? 'month') === 'day' ? 'day' : 'month';
    $focused = calendar_date((string) ($_GET['date'] ?? gmdate('Y-m-d')));
    $monthStart = $focused->modify('first day of this month');
    $monthEnd = $focused->modify('last day of this month');
    $prevMonth = $monthStart->modify('-1 month');
    $nextMonth = $monthStart->modify('+1 month');
    $dayCounts = calendar_counts_by_day($u, $monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d'));
    $monthCounts = calendar_counts_by_month($u, (int) $focused->format('Y'));
    $today = gmdate('Y-m-d');

    $monthTabs = '<div class="calendar-month-strip">';
    for ($month = 1; $month <= 12; $month++) {
        $date = new DateTimeImmutable(sprintf('%04d-%02d-01', (int) $focused->format('Y'), $month), new DateTimeZone('UTC'));
        $key = $date->format('Y-m');
        $count = (int) ($monthCounts[$key]['total'] ?? 0);
        $monthTabs .= '<a class="' . h('calendar-month-pill ' . ($date->format('Y-m') === $focused->format('Y-m') ? 'active ' : '') . ($count ? 'has-tasks' : '')) . '" href="' . h(calendar_url('month', $date)) . '"><span>' . h($date->format('M')) . '</span>' . ($count ? '<strong>' . $count . '</strong>' : '') . '</a>';
    }
    $monthTabs .= '</div>';

    $weekdays = '<div class="calendar-weekdays"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>';
    $cells = '';
    for ($blank = 1; $blank < (int) $monthStart->format('N'); $blank++) {
        $cells .= '<span class="calendar-day blank"></span>';
    }
    for ($day = 1; $day <= (int) $monthEnd->format('j'); $day++) {
        $date = $monthStart->setDate((int) $monthStart->format('Y'), (int) $monthStart->format('m'), $day);
        $key = $date->format('Y-m-d');
        $count = (int) ($dayCounts[$key]['total'] ?? 0);
        $open = (int) ($dayCounts[$key]['open'] ?? 0);
        $classes = 'calendar-day' . ($count ? ' has-tasks' : '') . ($open && $key < $today ? ' overdue' : '') . ($key === $today ? ' today' : '') . ($key === $focused->format('Y-m-d') ? ' selected' : '');
        $cells .= '<a class="' . h($classes) . '" href="' . h(calendar_url('day', $date)) . '"><span>' . $day . '</span>' . ($count ? '<strong>' . $count . '</strong>' : '') . '</a>';
    }
    $calendarGrid = '<section class="panel calendar-panel"><div class="calendar-nav"><a class="btn secondary" href="' . h(calendar_url('month', $prevMonth)) . '">Previous</a><div><h1>' . h($focused->format('F Y')) . '</h1><p class="muted">' . calendar_month_task_count($dayCounts) . ' task' . (calendar_month_task_count($dayCounts) === 1 ? '' : 's') . '</p></div><a class="btn secondary" href="' . h(calendar_url('month', $nextMonth)) . '">Next</a></div>' . $monthTabs . $weekdays . '<div class="calendar-grid">' . $cells . '</div></section>';

    $viewTabs = '<div class="btn-row calendar-view-tabs"><a class="btn ' . ($mode === 'month' ? '' : 'secondary') . '" href="' . h(calendar_url('month', $focused)) . '">Month</a><a class="btn ' . ($mode === 'day' ? '' : 'secondary') . '" href="' . h(calendar_url('day', $focused)) . '">Day</a><a class="btn secondary" href="?a=task-new">New task</a></div>';
    $dayTasks = calendar_tasks_for_day($u, $focused->format('Y-m-d'));
    $dayPanel = '<section class="panel panel-pad calendar-day-panel"><div class="page-title"><div><h2>' . h($focused->format('l, d/m/Y')) . '</h2><p class="muted">' . count($dayTasks) . ' task' . (count($dayTasks) === 1 ? '' : 's') . ' due</p></div><div class="btn-row"><a class="btn secondary" href="' . h(calendar_url('day', $focused->modify('-1 day'))) . '">Previous day</a><a class="btn secondary" href="' . h(calendar_url('day', $focused->modify('+1 day'))) . '">Next day</a></div></div>' . task_list($dayTasks) . '</section>';

    $monthTaskPreview = '';
    if ($mode === 'month') {
        $monthTaskPreview = '<section class="panel panel-pad calendar-day-panel"><h2>' . h($focused->format('F')) . '</h2><div class="calendar-agenda-list">';
        foreach ($dayCounts as $date => $count) {
            if ((int) $count['total'] > 0) {
                $monthTaskPreview .= '<a href="' . h(calendar_url('day', calendar_date($date))) . '"><strong>' . h(format_date($date)) . '</strong><span>' . (int) $count['total'] . ' task' . ((int) $count['total'] === 1 ? '' : 's') . '</span></a>';
            }
        }
        $monthTaskPreview .= $dayCounts ? '</div></section>' : '<div class="empty">No due tasks this month.</div></section>';
    }
    layout('Calendar', '<div class="calendar-shell">' . $viewTabs . $calendarGrid . ($mode === 'day' ? $dayPanel : $monthTaskPreview) . '</div>');
}

function user_lists(array $u, bool $includeArchived = false): array
{
    $sql = 'SELECT l.*,
        (SELECT COUNT(*) FROM tasks t WHERE t.list_id = l.id AND t.status = "open") open_count,
        (SELECT COUNT(*) FROM tasks t WHERE t.list_id = l.id AND t.status = "done") done_count,
        (SELECT COUNT(*) FROM tasks t WHERE t.list_id = l.id) total_count,
        (SELECT COUNT(*) FROM tasks t WHERE t.list_id = l.id AND t.status = "open" AND t.due_date IS NOT NULL AND t.due_date < date("now")) overdue_count
        FROM lists l WHERE l.user_id = ?';
    if (!$includeArchived) $sql .= ' AND l.archived = 0';
    $sql .= ' ORDER BY l.archived, l.position, l.name';
    $stmt = db()->prepare($sql);
    $stmt->execute([$u['id']]);
    return $stmt->fetchAll();
}
function archived_lists(array $u): array
{
    $sql = 'SELECT l.*,
        (SELECT COUNT(*) FROM tasks t WHERE t.list_id = l.id AND t.status = "open") open_count,
        (SELECT COUNT(*) FROM tasks t WHERE t.list_id = l.id AND t.status = "done") done_count,
        (SELECT COUNT(*) FROM tasks t WHERE t.list_id = l.id) total_count,
        (SELECT COUNT(*) FROM tasks t WHERE t.list_id = l.id AND t.status = "open" AND t.due_date IS NOT NULL AND t.due_date < date("now")) overdue_count
        FROM lists l WHERE l.user_id = ? AND l.archived = 1 ORDER BY l.updated_at DESC, l.name';
    $stmt = db()->prepare($sql);
    $stmt->execute([$u['id']]);
    return $stmt->fetchAll();
}
function archived_lists_page(): void
{
    $u = require_login();
    $lists = archived_lists($u);
    $rows = '';
    foreach ($lists as $list) {
        $completion = (int) $list['total_count'] > 0 ? (int) round(((int) $list['done_count'] / (int) $list['total_count']) * 100) : 0;
        $counts = '<div class="list-counts archive-counts"><span><strong>' . (int) $list['overdue_count'] . '</strong> Overdue</span><span><strong>' . (int) $list['open_count'] . '</strong> Open</span><span><strong>' . (int) $list['done_count'] . '</strong> Done</span><span><strong>' . (int) $list['total_count'] . '</strong> Total</span></div>';
        $progress = '<span class="list-progress-line"><span class="list-progress" role="progressbar" aria-label="Completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $completion . '" style="--list-progress:' . h($list['color']) . '"><span style="width:' . $completion . '%"></span></span><small>' . $completion . '%</small></span>';
        $rows .= '<article class="archive-list-card"><div class="archive-list-main"><span class="list-identity">' . list_icon_html($list, 'list-icon-large') . '<span><strong class="user-content">' . h($list['name']) . '</strong><small>Archived ' . h(format_date((string) $list['updated_at'])) . '</small></span></span>' . $progress . $counts . '</div><div class="btn-row archive-list-actions"><a class="btn secondary" href="?list=' . (int) $list['id'] . '#tasks">Open</a><a class="btn" href="?a=list-restore&id=' . (int) $list['id'] . '&csrf=' . h(csrf()) . '">Reactivate</a><a class="btn secondary" href="?a=list-edit&id=' . (int) $list['id'] . '">Edit</a><a class="btn danger" data-confirm="Delete this archived list and all its tasks?" href="?a=list-delete&id=' . (int) $list['id'] . '&csrf=' . h(csrf()) . '&from=archive">Delete</a></div></article>';
    }
    if ($rows === '') $rows = '<div class="empty">No archived lists yet.</div>';
    $html = '<section class="panel panel-pad"><div class="page-title"><div><h1>Archived Lists</h1><p class="muted">Archived lists are hidden from active tasks, calendar, list ordering, and reminders until you reactivate them.</p></div><div class="btn-row"><a class="btn secondary" href="?">Dashboard</a><a class="btn secondary" href="?a=list-new">New list</a></div></div><div class="archive-list-grid">' . $rows . '</div></section>';
    layout('Archived lists', $html);
}
function find_list(int $id, array $u): array
{
    $stmt = db()->prepare('SELECT * FROM lists WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $u['id']]);
    $list = $stmt->fetch();
    if (!$list) { http_response_code(404); exit('List not found.'); }
    return $list;
}
function maybe_find_list(int $id, array $u): ?array
{
    $stmt = db()->prepare('SELECT * FROM lists WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $u['id']]);
    $list = $stmt->fetch();
    return $list ?: null;
}
function list_form(array $list = []): void
{
    $u = require_login();
    $editing = isset($list['id']);
    $name = $list['name'] ?? '';
    $color = $list['color'] ?? COLORS[0];
    $iconPreview = list_icon_src($list) !== '' ? '<div class="list-icon-preview">' . list_icon_html($list, 'list-icon-large') . '<span>Current list icon</span></div>' : '';
    $html = '<section class="panel panel-pad"><h1>' . ($editing ? 'Edit List' : 'New List') . '</h1><form method="post" enctype="multipart/form-data" action="' . ($editing ? '?a=list-edit&id=' . (int) $list['id'] : '?a=list-new') . '" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><div class="form-row"><label>Name</label><input name="name" value="' . h($name) . '" required></div><div class="form-row"><label>Color</label><div class="color-grid">';
    foreach (COLORS as $c) $html .= '<label class="color-choice" style="--choice:' . h($c) . '"><input type="radio" name="color" value="' . h($c) . '" ' . ($c === $color ? 'checked' : '') . '><span></span></label>';
    $html .= '</div></div>' . $iconPreview . '<div class="inline-grid"><div class="form-row"><label>Upload list icon</label><input type="file" name="icon_file" accept="image/png,image/jpeg,image/gif,image/webp"></div><div class="form-row"><label>List icon link</label><input type="url" name="icon_url" value="' . h($list['icon_url'] ?? '') . '" placeholder="https://example.com/icon.png"></div></div>' . ($editing && list_icon_src($list) !== '' ? '<label class="checkbox-line"><input type="checkbox" name="remove_icon" value="1"> Remove current list icon</label>' : '') . '<div class="btn-row"><button>Save list</button><a class="btn secondary" href="?">Cancel</a>' . ($editing ? '<a class="btn danger" data-confirm="Delete this list and all its tasks?" href="?a=list-delete&id=' . (int) $list['id'] . '&csrf=' . h(csrf()) . '">Delete list</a>' : '') . '</div></form></section>';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $name = trim($_POST['name'] ?? '');
        $color = in_array($_POST['color'] ?? '', COLORS, true) ? $_POST['color'] : COLORS[0];
        if ($name === '') { flash('List name is required.', 'error'); redirect($_SERVER['REQUEST_URI']); }
        $iconUrl = trim($_POST['icon_url'] ?? '');
        if (!valid_avatar_url($iconUrl)) { flash('Use a valid http or https list icon link.', 'error'); redirect($_SERVER['REQUEST_URI']); }
        $iconPath = save_list_icon_upload($u);
        if (!empty($_POST['remove_icon'])) { $iconPath = null; $iconUrl = ''; }
        elseif ($iconPath === null) $iconPath = $list['icon_path'] ?? null;
        else $iconUrl = '';
        if ($editing) {
            db()->prepare('UPDATE lists SET name=?, color=?, icon_path=?, icon_url=?, updated_at=? WHERE id=? AND user_id=?')->execute([$name, $color, $iconPath, $iconUrl !== '' ? $iconUrl : null, now(), $list['id'], $u['id']]);
            log_activity($u, 'list.updated', $name);
        } else {
            $positionStmt = db()->prepare('SELECT COALESCE(MAX(position),0)+1 FROM lists WHERE user_id=?');
            $positionStmt->execute([$u['id']]);
            $position = (int) $positionStmt->fetchColumn();
            db()->prepare('INSERT INTO lists (user_id,name,color,icon_path,icon_url,position,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$u['id'], $name, $color, $iconPath, $iconUrl !== '' ? $iconUrl : null, $position, now(), now()]);
            log_activity($u, 'list.created', $name);
        }
        flash('List saved.');
        redirect('?');
    }
    layout($editing ? 'Edit list' : 'New list', $html);
}
function list_edit(): void { $u = require_login(); list_form(find_list((int) ($_GET['id'] ?? 0), $u)); }
function list_delete(): void
{
    $u = require_login();
    if (!hash_equals($_SESSION['csrf'] ?? '', $_GET['csrf'] ?? '')) { flash('Your session expired. Please try again.', 'error'); redirect('?'); }
    $list = find_list((int) ($_GET['id'] ?? 0), $u);
    db()->prepare('DELETE FROM lists WHERE id=? AND user_id=?')->execute([$list['id'], $u['id']]);
    log_activity($u, 'list.deleted', (string) $list['name']);
    flash('List deleted.');
    redirect(($_GET['from'] ?? '') === 'archive' ? '?a=archived-lists' : '?');
}
function list_archive(): void
{
    $u = require_login();
    if (!hash_equals($_SESSION['csrf'] ?? '', $_GET['csrf'] ?? '')) { flash('Your session expired. Please try again.', 'error'); redirect('?'); }
    $list = find_list((int) ($_GET['id'] ?? 0), $u);
    db()->prepare('UPDATE lists SET archived=1, updated_at=? WHERE id=? AND user_id=?')->execute([now(), $list['id'], $u['id']]);
    log_activity($u, 'list.archived', (string) $list['name']);
    flash('List archived.');
    redirect('?');
}
function list_restore(): void
{
    $u = require_login();
    if (!hash_equals($_SESSION['csrf'] ?? '', $_GET['csrf'] ?? '')) { flash('Your session expired. Please try again.', 'error'); redirect('?a=archived-lists'); }
    $list = find_list((int) ($_GET['id'] ?? 0), $u);
    $positionStmt = db()->prepare('SELECT COALESCE(MAX(position),0)+1 FROM lists WHERE user_id=? AND archived=0');
    $positionStmt->execute([$u['id']]);
    $position = (int) $positionStmt->fetchColumn();
    db()->prepare('UPDATE lists SET archived=0, position=?, updated_at=? WHERE id=? AND user_id=?')->execute([$position, now(), $list['id'], $u['id']]);
    log_activity($u, 'list.restored', (string) $list['name']);
    flash('List reactivated.');
    redirect('?list=' . (int) $list['id']);
}
function list_reorder(): void
{
    check_csrf();
    $u = require_login();
    $ids = json_decode((string) ($_POST['ids'] ?? ''), true);
    $ids = is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
    $stmt = db()->prepare('SELECT id FROM lists WHERE user_id=? AND archived=0 ORDER BY position,id');
    $stmt->execute([$u['id']]);
    $owned = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $expected = $owned; $submitted = $ids; sort($expected); sort($submitted);
    header('Content-Type: application/json');
    if (!$ids || $expected !== $submitted) { http_response_code(422); echo json_encode(['ok' => false]); return; }
    db()->beginTransaction();
    $update = db()->prepare('UPDATE lists SET position=?,updated_at=? WHERE id=? AND user_id=?');
    foreach ($ids as $position => $id) $update->execute([$position + 1, now(), $id, $u['id']]);
    db()->commit();
    log_activity($u, 'lists.reordered', count($ids) . ' lists');
    echo json_encode(['ok' => true]);
}

function tasks_for(array $u, int $listId, string $status, string $q, string $priorityOrder = '', string $dateType = 'due', string $filterDate = ''): array
{
    $where = ['t.user_id = ?'];
    $args = [$u['id']];
    if ($listId) { $where[] = 't.list_id = ?'; $args[] = $listId; }
    else $where[] = 'l.archived = 0';
    if ($status === 'open') $where[] = 't.status = "open"';
    elseif ($status === 'done') $where[] = 't.status = "done"';
    elseif ($status === 'overdue') $where[] = 't.status = "open" AND t.due_date IS NOT NULL AND t.due_date < date("now")';
    if ($q !== '') { $where[] = '(t.title LIKE ? OR t.notes LIKE ? OR t.tags LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; $args[] = "%$q%"; }
    if ($filterDate !== '') {
        if ($dateType === 'added') $where[] = 'date(t.created_at) = ?';
        else $where[] = 't.due_date = ?';
        $args[] = $filterDate;
    }
    $prioritySort = $priorityOrder === 'low_high'
        ? 'CASE t.priority WHEN "low" THEN 1 WHEN "medium" THEN 2 ELSE 3 END'
        : 'CASE t.priority WHEN "high" THEN 1 WHEN "medium" THEN 2 ELSE 3 END';
    $dateSort = $dateType === 'added'
        ? 'date(t.created_at) DESC, t.created_at DESC'
        : 't.due_date IS NULL, t.due_date, t.created_at DESC';
    $stmt = db()->prepare('SELECT t.*, l.name list_name, l.color list_color, l.icon_path list_icon_path, l.icon_url list_icon_url FROM tasks t JOIN lists l ON l.id=t.list_id WHERE ' . implode(' AND ', $where) . ' ORDER BY t.status, ' . $prioritySort . ', ' . $dateSort);
    $stmt->execute($args);
    return $stmt->fetchAll();
}
function stats_for(array $u): array
{
    $stmt = db()->prepare('SELECT status, COUNT(*) c FROM tasks WHERE user_id=? GROUP BY status');
    $stmt->execute([$u['id']]);
    $stats = ['open' => 0, 'done' => 0, 'overdue' => 0, 'today' => 0];
    foreach ($stmt as $r) $stats[$r['status']] = (int) $r['c'];
    $stmt = db()->prepare('SELECT COUNT(*) FROM tasks WHERE user_id=? AND status="open" AND due_date IS NOT NULL AND due_date < date("now")');
    $stmt->execute([$u['id']]); $stats['overdue'] = (int) $stmt->fetchColumn();
    $stmt = db()->prepare('SELECT COUNT(*) FROM tasks WHERE user_id=? AND status="open" AND due_date = date("now")');
    $stmt->execute([$u['id']]); $stats['today'] = (int) $stmt->fetchColumn();
    return $stats;
}
function overdue_tasks_for(array $u, bool $onlyUnnotified = false, bool $onlyUnemailed = false): array
{
    $where = ['t.user_id=?', 'l.archived=0', 't.status="open"', 't.due_date IS NOT NULL', 't.due_date < date("now")'];
    if ($onlyUnnotified) $where[] = 't.overdue_notified_at IS NULL';
    if ($onlyUnemailed) $where[] = 't.overdue_emailed_at IS NULL';
    $stmt = db()->prepare('SELECT t.id,t.title,t.due_date,t.priority,l.name list_name FROM tasks t JOIN lists l ON l.id=t.list_id WHERE ' . implode(' AND ', $where) . ' ORDER BY t.due_date, CASE t.priority WHEN "high" THEN 1 WHEN "medium" THEN 2 ELSE 3 END, t.title LIMIT 25');
    $stmt->execute([$u['id']]);
    return array_map(fn($task) => $task + ['reminder_type' => 'overdue'], $stmt->fetchAll());
}
function due_soon_tasks_for(array $u, bool $onlyUnnotified = false, bool $onlyUnemailed = false): array
{
    $where = ['t.user_id=?', 'l.archived=0', 't.status="open"', 't.due_date IS NOT NULL', 't.due_date >= date("now")', 't.due_date <= date("now","+1 day")'];
    if ($onlyUnnotified) $where[] = 't.due_soon_notified_at IS NULL';
    if ($onlyUnemailed) $where[] = 't.due_soon_emailed_at IS NULL';
    $stmt = db()->prepare('SELECT t.id,t.title,t.due_date,t.priority,l.name list_name FROM tasks t JOIN lists l ON l.id=t.list_id WHERE ' . implode(' AND ', $where) . ' ORDER BY t.due_date, CASE t.priority WHEN "high" THEN 1 WHEN "medium" THEN 2 ELSE 3 END, t.title LIMIT 25');
    $stmt->execute([$u['id']]);
    return array_map(fn($task) => $task + ['reminder_type' => 'due_soon'], $stmt->fetchAll());
}
function overdue_attention_html(array $tasks, array $notificationTasks, string $method): string
{
    if (!$tasks || $method === 'none') return '';
    $count = count($tasks);
    $overdueCount = count(array_filter($tasks, fn($task) => ($task['reminder_type'] ?? '') === 'overdue'));
    $dueSoonCount = $count - $overdueCount;
    $heading = $overdueCount && $dueSoonCount
        ? $count . ' tasks need attention'
        : ($overdueCount ? $overdueCount . ' overdue task' . ($overdueCount === 1 ? '' : 's') : $dueSoonCount . ' task' . ($dueSoonCount === 1 ? '' : 's') . ' due soon');
    $items = '';
    foreach (array_slice($tasks, 0, 5) as $task) {
        $state = ($task['reminder_type'] ?? '') === 'overdue' ? 'Overdue' : 'Due soon';
        $items .= '<li><strong class="user-content">' . h($task['title']) . '</strong><span>' . h($state . ' · ' . $task['list_name'] . ' · Due ' . format_date($task['due_date'])) . '</span></li>';
    }
    $more = $count > 5 ? '<p class="muted">+' . ($count - 5) . ' more task reminder' . ($count - 5 === 1 ? '' : 's') . '.</p>' : '';
    $mode = $method === 'email' ? 'E-mail reminders are on.' : 'Browser and mobile notifications are on.';
    $payload = json_encode(array_map(fn($task) => [
        'id' => (int) $task['id'],
        'title' => (string) $task['title'],
        'list' => (string) $task['list_name'],
        'due' => format_date((string) $task['due_date']),
        'priority' => (string) $task['priority'],
        'type' => (string) ($task['reminder_type'] ?? 'overdue'),
    ], $notificationTasks), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $viewUrl = $overdueCount ? '?status=overdue#tasks' : '?status=open#tasks';
    return '<section class="overdue-alert panel" data-overdue-alert><div><p class="alert-kicker">Attention</p><h2>' . h($heading) . '</h2><p class="muted">' . h($mode) . ' Change this in User Settings.</p></div><ul>' . $items . '</ul>' . $more . '<div class="btn-row"><a class="btn" href="' . h($viewUrl) . '">View tasks</a><a class="btn secondary" href="?a=profile">Reminder settings</a></div><script type="application/json" id="todoom-overdue-payload" data-method="' . h($method) . '" data-csrf="' . h(csrf()) . '">' . ($payload ?: '[]') . '</script></section><br>';
}
function process_due_email_notifications(array $u): void
{
    $overdue = overdue_tasks_for($u, false, true);
    $dueSoon = due_soon_tasks_for($u, false, true);
    $tasks = array_merge($overdue, $dueSoon);
    if (!$tasks) return;
    if (!send_due_reminder_email($u, $tasks)) return;
    mark_reminders($u, $overdue, 'overdue_emailed_at');
    mark_reminders($u, $dueSoon, 'due_soon_emailed_at');
    log_activity($u, 'task.due_email_sent', count($tasks) . ' due reminders');
}
function send_due_reminder_email(array $u, array $tasks): bool
{
    $lines = [];
    foreach ($tasks as $task) {
        $state = ($task['reminder_type'] ?? '') === 'overdue' ? 'OVERDUE' : 'DUE SOON';
        $lines[] = '- [' . $state . '] ' . $task['title'] . ' [' . ucfirst((string) $task['priority']) . '] in ' . $task['list_name'] . ', due ' . format_date((string) $task['due_date']);
    }
    $subject = count($tasks) . ' Todoom task reminder' . (count($tasks) === 1 ? '' : 's');
    $message = "Hello {$u['username']},\n\nThese Todoom tasks need attention:\n\n" . implode("\n", $lines) . "\n\nOpen Todoom to update or complete them:\n" . app_url('status=overdue') . "\n\nTodoom";
    $headers = ['From: ' . setting('mail_from_name', APP_NAME) . ' <' . setting('mail_from_email', 'todoom@quantumnet.space') . '>', 'Reply-To: ' . setting('mail_reply_to', 'mariuserbanica@proton.me'), 'Content-Type: text/plain; charset=UTF-8', 'X-Mailer: ' . APP_NAME];
    return @mail($u['email'], $subject, $message, implode("\r\n", $headers), '-f' . setting('mail_envelope_sender', 'todoom@quantumnet.space'));
}
function mark_reminders(array $u, array $tasks, string $column): void
{
    if (!$tasks || !in_array($column, ['overdue_notified_at', 'overdue_emailed_at', 'due_soon_notified_at', 'due_soon_emailed_at'], true)) return;
    $ids = array_map('intval', array_column($tasks, 'id'));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare("UPDATE tasks SET $column=? WHERE user_id=? AND id IN ($placeholders)")->execute(array_merge([now(), $u['id']], $ids));
}
function overdue_notified(): void
{
    check_csrf();
    $u = require_login();
    $items = json_decode((string) ($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) {
        $ids = json_decode((string) ($_POST['ids'] ?? '[]'), true);
        $items = array_map(fn($id) => ['id' => $id, 'type' => 'overdue'], is_array($ids) ? $ids : []);
    }
    header('Content-Type: application/json');
    $overdue = [];
    $dueSoon = [];
    foreach ($items as $item) {
        $id = (int) ($item['id'] ?? 0);
        if ($id <= 0) continue;
        if (($item['type'] ?? '') === 'due_soon') $dueSoon[] = ['id' => $id];
        else $overdue[] = ['id' => $id];
    }
    mark_reminders($u, $overdue, 'overdue_notified_at');
    mark_reminders($u, $dueSoon, 'due_soon_notified_at');
    echo json_encode(['ok' => true]);
}
function task_list(array $tasks): string
{
    if (!$tasks) return '<div class="empty">No tasks match this view.</div>';
    $html = '<form method="post" action="?a=tasks-bulk-delete" class="bulk-form" data-bulk-form><input type="hidden" name="csrf" value="' . h(csrf()) . '"><div class="bulk-toolbar"><label><input type="checkbox" data-select-all> Select all</label><button class="danger" data-confirm="Delete selected tasks?" disabled data-bulk-delete>Delete selected</button></div><div class="task-list">';
    foreach ($tasks as $t) {
        $done = $t['status'] === 'done';
        $dueClass = (!$done && $t['due_date'] && $t['due_date'] < gmdate('Y-m-d')) ? ' priority-high' : '';
        $taskList = ['color' => $t['list_color'], 'icon_path' => $t['list_icon_path'], 'icon_url' => $t['list_icon_url']];
        $description = $t['notes'] ? '<details class="task-description" data-task-description><summary><span data-description-label>Show description</span></summary><p class="muted user-content">' . nl2br(h($t['notes'])) . '</p></details>' : '';
        $html .= '<article class="task ' . ($done ? 'done' : '') . '"><label class="select-task"><input type="checkbox" name="task_ids[]" value="' . (int) $t['id'] . '"><span>Select</span></label><a class="check" title="Toggle done" href="?a=task-toggle&id=' . (int) $t['id'] . '&csrf=' . h(csrf()) . '"><span>✓</span></a><div><div class="task-title user-content">' . h($t['title']) . '</div>' . $description . '<div class="task-meta"><span class="pill list-identity">' . list_icon_html($taskList, 'task-list-icon') . '<span class="user-content">' . h($t['list_name']) . '</span></span><span class="priority-' . h($t['priority']) . '">' . h(ucfirst($t['priority'])) . '</span><span>Added on ' . h(format_date($t['created_at'])) . '</span>' . ($t['due_date'] ? '<span class="' . $dueClass . '">Due ' . h(format_date($t['due_date'])) . '</span>' : '') . ($t['tags'] ? '<span class="user-content">' . h($t['tags']) . '</span>' : '') . '</div></div><div class="btn-row"><a class="btn secondary" href="?a=task-edit&id=' . (int) $t['id'] . '">Edit</a><a class="btn danger" data-confirm="Delete this task?" href="?a=task-delete&id=' . (int) $t['id'] . '&csrf=' . h(csrf()) . '">Delete</a></div></article>';
    }
    return $html . '</div></form>';
}
function find_task(int $id, array $u): array
{
    $stmt = db()->prepare('SELECT * FROM tasks WHERE id=? AND user_id=?');
    $stmt->execute([$id, $u['id']]);
    $task = $stmt->fetch();
    if (!$task) { http_response_code(404); exit('Task not found.'); }
    return $task;
}
function task_form(array $task = []): void
{
    $u = require_login();
    $lists = user_lists($u);
    if (!$lists) { seed_default_list((int) $u['id']); $lists = user_lists($u); }
    $editing = isset($task['id']);
    $selectedList = (int) ($task['list_id'] ?? ($_GET['list'] ?? $lists[0]['id']));
    $addedDate = form_date(substr((string) ($task['created_at'] ?? ''), 0, 10));
    $options = '';
    foreach ($lists as $l) $options .= '<option value="' . (int) $l['id'] . '" ' . ($selectedList === (int) $l['id'] ? 'selected' : '') . '>' . h($l['name']) . '</option>';
    $html = '<section class="panel panel-pad"><h1>' . ($editing ? 'Edit Task' : 'New Task') . '</h1><form method="post" action="' . ($editing ? '?a=task-edit&id=' . (int) $task['id'] : '?a=task-create') . '" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><div class="form-row"><label>Title</label><input name="title" value="' . h($task['title'] ?? '') . '" required></div><div class="inline-grid"><div class="form-row"><label>List</label><select class="user-content" name="list_id">' . $options . '</select></div><div class="form-row"><label>Priority</label><select name="priority">' . select_options(['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'], $task['priority'] ?? 'medium') . '</select></div></div><div class="inline-grid"><div class="form-row"><label>Added date</label><input type="date" name="added_date" value="' . h($addedDate) . '"></div><div class="form-row"><label>Due date</label><input type="date" name="due_date" value="' . h($task['due_date'] ?? '') . '"></div></div><div class="inline-grid"><div class="form-row"><label>Tags</label><input name="tags" value="' . h($task['tags'] ?? '') . '" placeholder="work, house, urgent"></div></div><div class="form-row"><label>Notes</label><textarea name="notes">' . h($task['notes'] ?? '') . '</textarea></div><div class="btn-row"><button>Save task</button><a class="btn secondary" href="?">Cancel</a></div></form></section>';
    layout($editing ? 'Edit task' : 'New task', $html);
}
function task_payload(array $u, array $task = []): array
{
    $title = trim($_POST['title'] ?? '');
    if ($title === '') { flash('Task title is required.', 'error'); redirect($_SERVER['HTTP_REFERER'] ?? '?'); }
    $list = find_list((int) ($_POST['list_id'] ?? 0), $u);
    if ((int) ($list['archived'] ?? 0) === 1) {
        flash('Reactivate this list before adding or moving tasks into it.', 'error');
        redirect($_SERVER['HTTP_REFERER'] ?? '?a=archived-lists');
    }
    $priority = in_array($_POST['priority'] ?? 'medium', ['low','medium','high'], true) ? $_POST['priority'] : 'medium';
    $due = trim($_POST['due_date'] ?? '');
    $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null;
    $addedAt = created_at_from_date(trim($_POST['added_date'] ?? ''), $task['created_at'] ?? null);
    return [$list['id'], $title, trim($_POST['notes'] ?? ''), $priority, $due, trim($_POST['tags'] ?? ''), $addedAt];
}
function task_create(): void
{
    check_csrf(); $u = require_login(); [$list, $title, $notes, $priority, $due, $tags, $addedAt] = task_payload($u);
    db()->prepare('INSERT INTO tasks (user_id,list_id,title,notes,priority,due_date,tags,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$u['id'], $list, $title, $notes, $priority, $due, $tags, $addedAt, now()]);
    log_activity($u, 'task.created', $title);
    flash('Task created.'); redirect('?list=' . (int) $list);
}
function task_edit(): void
{
    $u = require_login(); $task = find_task((int) ($_GET['id'] ?? 0), $u);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { task_form($task); return; }
    check_csrf(); [$list, $title, $notes, $priority, $due, $tags, $addedAt] = task_payload($u, $task);
    db()->prepare('UPDATE tasks SET list_id=?, title=?, notes=?, priority=?, due_date=?, tags=?, created_at=?, updated_at=?, overdue_notified_at=NULL, overdue_emailed_at=NULL, due_soon_notified_at=NULL, due_soon_emailed_at=NULL WHERE id=? AND user_id=?')->execute([$list, $title, $notes, $priority, $due, $tags, $addedAt, now(), $task['id'], $u['id']]);
    log_activity($u, 'task.updated', $title);
    flash('Task updated.'); redirect('?list=' . (int) $list);
}
function task_toggle(): void
{
    $u = require_login();
    if (!hash_equals($_SESSION['csrf'] ?? '', $_GET['csrf'] ?? '')) { flash('Your session expired. Please try again.', 'error'); redirect('?'); }
    $task = find_task((int) ($_GET['id'] ?? 0), $u);
    $done = $task['status'] !== 'done';
    db()->prepare('UPDATE tasks SET status=?, completed_at=?, updated_at=?, overdue_notified_at=?, overdue_emailed_at=?, due_soon_notified_at=?, due_soon_emailed_at=? WHERE id=? AND user_id=?')->execute([$done ? 'done' : 'open', $done ? now() : null, now(), $done ? $task['overdue_notified_at'] : null, $done ? $task['overdue_emailed_at'] : null, $done ? $task['due_soon_notified_at'] : null, $done ? $task['due_soon_emailed_at'] : null, $task['id'], $u['id']]);
    log_activity($u, $done ? 'task.completed' : 'task.reopened', (string) $task['title']);
    redirect(dashboard_url((int) $task['list_id'], $done ? 'done' : 'open'));
}
function task_delete(): void
{
    $u = require_login();
    if (!hash_equals($_SESSION['csrf'] ?? '', $_GET['csrf'] ?? '')) { flash('Your session expired. Please try again.', 'error'); redirect('?'); }
    $task = find_task((int) ($_GET['id'] ?? 0), $u);
    db()->prepare('DELETE FROM tasks WHERE id=? AND user_id=?')->execute([$task['id'], $u['id']]);
    log_activity($u, 'task.deleted', (string) $task['title']);
    flash('Task deleted.'); redirect('?');
}

function tasks_bulk_delete(): void
{
    check_csrf();
    $u = require_login();
    $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['task_ids'] ?? []), fn($id) => $id > 0)));
    if (!$ids) {
        flash('Select at least one task to delete.', 'error');
        redirect($_SERVER['HTTP_REFERER'] ?? '?');
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("DELETE FROM tasks WHERE user_id = ? AND id IN ($placeholders)");
    $stmt->execute(array_merge([$u['id']], $ids));
    $deleted = $stmt->rowCount();
    log_activity($u, 'tasks.deleted', $deleted . ' tasks');
    flash($deleted . ' task' . ($deleted === 1 ? '' : 's') . ' deleted.');
    redirect($_SERVER['HTTP_REFERER'] ?? '?');
}

function register(): void
{
    if (setting('allow_registration', '1') !== '1') { http_response_code(403); exit('Registration is closed.'); }
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $username = trim($_POST['username'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        if (!verify_captcha('register')) $error = 'Verification code is incorrect or expired. Try a new code.';
        elseif ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) $error = 'Use a username, valid email, and password of at least 10 characters.';
        else {
            try {
                $verified = setting('require_email_verification', '1') === '1' ? null : now();
                db()->prepare('INSERT INTO users (username,email,password_hash,role,email_verified_at,created_at) VALUES (?,?,?,"user",?,?)')->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $verified, now()]);
                $id = (int) db()->lastInsertId(); seed_default_list($id);
                log_activity(['id' => $id, 'username' => $username], 'account.registered');
                if ($verified === null) {
                    $_SESSION['pending_verification_email'] = $email;
                    $_SESSION['verification_mail_message'] = send_verification($id, $email, $username) ? 'A verification email has been sent.' : 'Your account was created, but the mail server could not send the message. Please use resend.';
                    redirect('?a=verify-pending');
                }
                flash('Account created. You can sign in now.'); redirect('?a=login');
            } catch (Throwable $e) { $error = 'That username or email is already registered.'; }
        }
    }
    layout('Register', auth_form('Create Account', '?a=register', $error, true, 'register'));
}
function login(): void
{
    $error = '';
    $login = trim($_POST['login'] ?? '');
    $captchaRequired = login_failure_count($login) >= 2;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        if ($captchaRequired && !verify_captcha('login')) {
            $error = 'Verification code is required after repeated failed logins. Try a new code.';
        } else {
            $stmt = db()->prepare('SELECT * FROM users WHERE email=? OR username=?');
            $stmt->execute([$login, $login]);
            $u = $stmt->fetch();
            if (!$u || !password_verify($_POST['password'] ?? '', $u['password_hash']) || $u['status'] !== 'active') {
                $captchaRequired = record_login_failure($login) >= 2;
                $error = $captchaRequired ? 'Invalid login details. A verification code is required for the next attempt.' : 'Invalid login details.';
            } else {
                clear_login_failures($login);
                if (!$u['email_verified_at']) { $_SESSION['pending_verification_email'] = $u['email']; redirect('?a=verify-pending'); }
                if ((int) $u['twofa_enabled'] === 1 && !trusted_2fa_valid($u)) {
                    $_SESSION['pending_2fa_user_id'] = (int) $u['id'];
                    $_SESSION['pending_mobile_app_login'] = is_mobile_app_request() ? 1 : 0;
                    redirect('?a=twofactor-login');
                }
                session_regenerate_id(true); $_SESSION['user_id'] = (int) $u['id']; if (is_mobile_app_request()) issue_mobile_unlock_token($u); log_activity($u, 'account.login'); flash('Signed in.'); redirect('?');
            }
        }
    }
    layout('Sign in', auth_form('Sign In', '?a=login', $error, false, $captchaRequired ? 'login' : null));
}
function auth_form(string $heading, string $action, string $error, bool $register, ?string $captchaPurpose = null): string
{
    $identity = $register
        ? '<div class="form-row"><label>Username</label><input name="username" value="' . h($_POST['username'] ?? '') . '" required></div><div class="form-row"><label>Email</label><input type="email" name="email" value="' . h($_POST['email'] ?? '') . '" required></div>'
        : '<div class="form-row"><label>Email or username</label><input name="login" value="' . h($_POST['login'] ?? '') . '" required></div>';
    $captcha = $captchaPurpose !== null ? captcha_field($captchaPurpose) : '';
    $recovery = !$register ? '<p class="auth-help"><a href="?a=forgot-password">Forgot password?</a></p>' : '';
    $mobileUnlock = (!$register && has_mobile_unlock_token())
        ? '<div class="mobile-biometric-login mobile-trusted-unlock" data-mobile-trusted-unlock hidden><div class="divider-label"><span>or</span></div><button class="secondary biometric-button" type="button" data-mobile-unlock data-csrf="' . h(csrf()) . '">Unlock with fingerprint</button><p class="muted field-help" data-mobile-unlock-status></p></div>'
        : '';
    return '<section class="panel panel-pad auth-panel"><h1>' . h($heading) . '</h1>' . ($error ? '<div class="flash error">' . h($error) . '</div>' : '') . '<form method="post" action="' . h($action) . '" class="form-grid" data-auth-form><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="is_mobile_app" value="0" data-mobile-app-field>' . $identity . '<div class="form-row"><label>Password</label><input type="password" name="password" minlength="10" required' . ($register ? ' data-password-check' : '') . '>' . ($register ? password_help() : '') . '</div>' . $captcha . '<button>' . h($heading) . '</button></form>' . $mobileUnlock . $recovery . '</section>';
}
function send_password_reset(array $u): bool
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM email_tokens WHERE user_id=? AND purpose="password_reset"')->execute([$u['id']]);
    db()->prepare('INSERT INTO email_tokens (user_id,token,purpose,expires_at,created_at) VALUES (?,?,"password_reset",?,?)')->execute([$u['id'], hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 3600), now()]);
    $link = app_url('a=reset-password&email=' . rawurlencode($u['email']) . '&token=' . urlencode($token));
    $subject = 'Reset your Todoom password';
    $message = "Hello {$u['username']},\n\nUse this link to reset your Todoom password:\n\n{$link}\n\nThis link expires in one hour. If you did not request it, you can ignore this email.\n\nTodoom";
    $headers = ['From: ' . setting('mail_from_name', APP_NAME) . ' <' . setting('mail_from_email', 'todoom@quantumnet.space') . '>', 'Reply-To: ' . setting('mail_reply_to', 'mariuserbanica@proton.me'), 'Content-Type: text/plain; charset=UTF-8', 'X-Mailer: ' . APP_NAME];
    return @mail($u['email'], $subject, $message, implode("\r\n", $headers), '-f' . setting('mail_envelope_sender', 'todoom@quantumnet.space'));
}
function forgot_password(): void
{
    $sent = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $email = strtolower(trim($_POST['email'] ?? ''));
        $stmt = db()->prepare('SELECT * FROM users WHERE email=? AND status="active" AND email_verified_at IS NOT NULL');
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if ($u) {
            $recent = db()->prepare('SELECT created_at FROM email_tokens WHERE user_id=? AND purpose="password_reset" ORDER BY created_at DESC LIMIT 1');
            $recent->execute([$u['id']]);
            $last = $recent->fetchColumn();
            if (!$last || $last < gmdate('Y-m-d H:i:s', time() - 60)) send_password_reset($u);
        }
        $sent = true;
    }
    $message = $sent ? '<div class="flash">If an active account matches that email, a password reset link has been sent.</div>' . mail_delivery_note() : '<p class="muted">Enter your confirmed account email to receive a password reset link.</p>';
    layout('Forgot password', '<section class="panel panel-pad auth-panel"><h1>Forgot Password</h1>' . $message . '<form method="post" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><div class="form-row"><label>Email</label><input type="email" name="email" value="' . h($_POST['email'] ?? '') . '" required></div><button>Send reset link</button></form><p class="auth-help"><a href="?a=login">Back to sign in</a></p></section>');
}
function password_reset_record(string $email, string $token): ?array
{
    if (strlen($token) !== 64) return null;
    $stmt = db()->prepare('SELECT t.*,u.username,u.email FROM email_tokens t JOIN users u ON u.id=t.user_id WHERE u.email=? AND u.status="active" AND t.token=? AND t.purpose="password_reset" AND t.expires_at>?');
    $stmt->execute([$email, hash('sha256', $token), now()]);
    return $stmt->fetch() ?: null;
}
function reset_password(): void
{
    $email = strtolower(trim($_GET['email'] ?? ''));
    $token = (string) ($_GET['token'] ?? '');
    $reset = password_reset_record($email, $token);
    if (!$reset) {
        layout('Reset password', '<section class="panel panel-pad auth-panel"><h1>Reset Password</h1><div class="flash error">This password reset link is invalid or expired.</div><p class="auth-help"><a href="?a=forgot-password">Request a new reset link</a></p></section>');
        return;
    }
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $password = $_POST['new_password'] ?? '';
        if ($password !== ($_POST['confirm_password'] ?? '')) $error = 'New password confirmation does not match.';
        elseif (strlen($password) < 10) $error = 'Use a password of at least 10 characters.';
        else {
            db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
            db()->prepare('DELETE FROM email_tokens WHERE user_id=? AND purpose="password_reset"')->execute([$reset['user_id']]);
            revoke_trusted_2fa_tokens((int) $reset['user_id']);
            clear_login_failures((string) $reset['email']);
            clear_login_failures((string) $reset['username']);
            log_activity(['id' => (int) $reset['user_id'], 'username' => (string) $reset['username']], 'account.password_reset');
            flash('Password reset complete. You can sign in now.');
            redirect('?a=login');
        }
    }
    $form = '<section class="panel panel-pad auth-panel"><h1>Reset Password</h1>' . ($error ? '<div class="flash error">' . h($error) . '</div>' : '') . '<form method="post" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><div class="form-row"><label>New password</label><input type="password" name="new_password" minlength="10" autocomplete="new-password" required data-password-check>' . password_help() . '</div><div class="form-row"><label>Confirm new password</label><input type="password" name="confirm_password" minlength="10" autocomplete="new-password" required></div><button>Reset password</button></form></section>';
    layout('Reset password', $form);
}
function send_verification(int $userId, string $email, string $username): bool
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM email_tokens WHERE user_id=? AND purpose="verify"')->execute([$userId]);
    db()->prepare('INSERT INTO email_tokens (user_id,token,purpose,expires_at,created_at) VALUES (?,?,"verify",?,?)')->execute([$userId, hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 86400), now()]);
    $link = app_url('a=verify&email=' . rawurlencode($email) . '&token=' . urlencode($token));
    $subject = 'Confirm your Todoom email';
    $message = "Hello {$username},\n\nConfirm your email address to activate your Todoom account:\n\n{$link}\n\nThis link expires in 24 hours.\n\nTodoom";
    $headers = ['From: ' . setting('mail_from_name', APP_NAME) . ' <' . setting('mail_from_email', 'todoom@quantumnet.space') . '>', 'Reply-To: ' . setting('mail_reply_to', 'mariuserbanica@proton.me'), 'Content-Type: text/plain; charset=UTF-8', 'X-Mailer: ' . APP_NAME];
    return @mail($email, $subject, $message, implode("\r\n", $headers), '-f' . setting('mail_envelope_sender', 'todoom@quantumnet.space'));
}
function send_email_change_verification(int $userId, string $email, string $username): bool
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM email_tokens WHERE user_id=? AND purpose="email_change"')->execute([$userId]);
    db()->prepare('INSERT INTO email_tokens (user_id,token,purpose,expires_at,created_at) VALUES (?,?,"email_change",?,?)')->execute([$userId, hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 86400), now()]);
    $link = app_url('a=verify-email-change&email=' . rawurlencode($email) . '&token=' . urlencode($token));
    $subject = 'Confirm your new Todoom email';
    $message = "Hello {$username},\n\nConfirm this new email address for your Todoom account:\n\n{$link}\n\nThis link expires in 24 hours.\n\nTodoom";
    $headers = ['From: ' . setting('mail_from_name', APP_NAME) . ' <' . setting('mail_from_email', 'todoom@quantumnet.space') . '>', 'Reply-To: ' . setting('mail_reply_to', 'mariuserbanica@proton.me'), 'Content-Type: text/plain; charset=UTF-8', 'X-Mailer: ' . APP_NAME];
    return @mail($email, $subject, $message, implode("\r\n", $headers), '-f' . setting('mail_envelope_sender', 'todoom@quantumnet.space'));
}
function verify_email(): void
{
    $email = strtolower(trim($_GET['email'] ?? '')); $token = (string) ($_GET['token'] ?? '');
    $stmt = db()->prepare('SELECT t.* FROM email_tokens t JOIN users u ON u.id=t.user_id WHERE u.email=? AND t.token=? AND t.purpose="verify" AND t.expires_at>?');
    $stmt->execute([$email, strlen($token) === 64 ? hash('sha256', $token) : '', now()]);
    $row = $stmt->fetch();
    if (!$row) { flash('Verification link is invalid or expired.', 'error'); redirect('?a=login'); }
    db()->prepare('UPDATE users SET email_verified_at=? WHERE id=?')->execute([now(), $row['user_id']]);
    db()->prepare('DELETE FROM email_tokens WHERE user_id=? AND purpose="verify"')->execute([$row['user_id']]);
    session_regenerate_id(true); $_SESSION['user_id'] = (int) $row['user_id'];
    unset($_SESSION['pending_verification_email'], $_SESSION['verification_mail_message']);
    flash('Email confirmed. Your account is ready.'); redirect('?');
}
function verify_email_change(): void
{
    $email = strtolower(trim($_GET['email'] ?? '')); $token = (string) ($_GET['token'] ?? '');
    $stmt = db()->prepare('SELECT t.* FROM email_tokens t JOIN users u ON u.id=t.user_id WHERE u.pending_email=? AND t.token=? AND t.purpose="email_change" AND t.expires_at>?');
    $stmt->execute([$email, strlen($token) === 64 ? hash('sha256', $token) : '', now()]);
    $row = $stmt->fetch();
    if (!$row) { flash('Email change link is invalid or expired.', 'error'); redirect('?a=login'); }
    try {
        db()->prepare('UPDATE users SET email=?, pending_email=NULL, email_verified_at=? WHERE id=?')->execute([$email, now(), $row['user_id']]);
        db()->prepare('DELETE FROM email_tokens WHERE user_id=? AND purpose="email_change"')->execute([$row['user_id']]);
        flash('Email address updated.');
    } catch (Throwable $e) {
        flash('That email address is already in use.', 'error');
    }
    redirect((int) ($_SESSION['user_id'] ?? 0) === (int) $row['user_id'] ? '?a=profile' : '?a=login');
}
function verify_pending(): void
{
    $email = (string) ($_SESSION['pending_verification_email'] ?? '');
    if ($email === '') redirect('?a=login');
    $message = (string) ($_SESSION['verification_mail_message'] ?? ''); unset($_SESSION['verification_mail_message']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $stmt = db()->prepare('SELECT * FROM users WHERE email=?'); $stmt->execute([$email]); $u = $stmt->fetch();
        if (!$u) { unset($_SESSION['pending_verification_email']); redirect('?a=register'); }
        $recent = db()->prepare('SELECT created_at FROM email_tokens WHERE user_id=? AND purpose="verify" ORDER BY created_at DESC LIMIT 1'); $recent->execute([$u['id']]);
        $last = $recent->fetchColumn();
        $message = ($last && $last > gmdate('Y-m-d H:i:s', time() - 60)) ? 'Please wait one minute before requesting another email.' : (send_verification((int) $u['id'], $u['email'], $u['username']) ? 'A new verification email has been sent.' : 'The mail server could not send the message. Please try again shortly.');
    }
    layout('Confirm email', '<section class="panel panel-pad"><h1>Confirm Your Email</h1><p class="muted">We sent a verification link to <strong>' . h($email) . '</strong>. It expires in 24 hours.</p>' . mail_delivery_note() . ($message ? '<div class="flash">' . h($message) . '</div>' : '') . '<form method="post" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><button class="secondary">Resend verification email</button></form><p class="muted"><a href="?a=login">Back to sign in</a></p></section>');
}
function twofactor_login(): void
{
    if (empty($_SESSION['pending_2fa_user_id'])) redirect('?a=login');
    $stmt = db()->prepare('SELECT * FROM users WHERE id=?'); $stmt->execute([$_SESSION['pending_2fa_user_id']]); $u = $stmt->fetch();
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        if ($u && verify_totp($u['twofa_secret'], $_POST['code'] ?? '')) { $mobileLogin = !empty($_SESSION['pending_mobile_app_login']) || is_mobile_app_request(); session_regenerate_id(true); $_SESSION['user_id'] = (int) $u['id']; unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_mobile_app_login']); issue_trusted_2fa_token($u); if ($mobileLogin) issue_mobile_unlock_token($u); log_activity($u, 'account.login', '2FA'); flash('Signed in.'); redirect('?'); }
        $error = 'Invalid authentication code.';
    }
    $biometric = $u && (int) ($u['mobile_biometric_enabled'] ?? 0) === 1
        ? '<div class="mobile-biometric-login" data-mobile-biometric hidden><div class="divider-label"><span>or</span></div><button class="secondary biometric-button" type="button" data-biometric-login data-csrf="' . h(csrf()) . '">Use fingerprint instead</button><p class="muted field-help" data-biometric-status></p></div>'
        : '';
    layout('2FA', '<section class="panel panel-pad auth-panel"><h1>Two-Factor Authentication</h1>' . ($error ? '<div class="flash error">' . h($error) . '</div>' : '') . '<form method="post" class="form-grid" data-auth-form><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="is_mobile_app" value="0" data-mobile-app-field><div class="form-row"><label>6-digit code</label><input name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus></div><button>Verify</button></form>' . $biometric . '</section>');
}
function mobile_biometric_login(): void
{
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['pending_2fa_user_id'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'No pending 2FA login.']);
        return;
    }
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'message' => 'Your session expired. Please sign in again.']);
        return;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id=? AND status="active"');
    $stmt->execute([$_SESSION['pending_2fa_user_id']]);
    $u = $stmt->fetch();
    if (!$u || !$u['email_verified_at'] || (int) $u['twofa_enabled'] !== 1 || (int) ($u['mobile_biometric_enabled'] ?? 0) !== 1) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Mobile fingerprint login is not enabled for this account.']);
        return;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $u['id'];
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_mobile_app_login']);
    issue_mobile_unlock_token($u);
    log_activity($u, 'account.login', 'Mobile biometric');
    echo json_encode(['ok' => true, 'redirect' => '?']);
}
function logout(): void
{
    $u = current_user();
    if ($u) log_activity($u, 'account.logout');
    $cookie = (string) ($_COOKIE['todoom_mobile_unlock'] ?? '');
    if (str_contains($cookie, ':')) {
        [$selector] = explode(':', $cookie, 2);
        if (preg_match('/^[a-f0-9]{18}$/', $selector)) db()->prepare('DELETE FROM mobile_unlock_tokens WHERE selector=?')->execute([hash('sha256', $selector)]);
    }
    clear_mobile_unlock_cookie();
    session_destroy(); session_start(); flash('Signed out.'); redirect('?a=login');
}

function profile(): void
{
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $action = $_POST['account_action'] ?? 'settings';
        if ($action === 'settings') {
            $country = country_code($_POST['country_code'] ?? ($u['country_code'] ?? 'GB'));
            $theme = ($_POST['theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
            $languages = app_languages();
            $language = isset($languages[$_POST['language'] ?? '']) ? (string) $_POST['language'] : 'en';
            $overdueNotifyMethod = array_key_exists((string) ($_POST['overdue_notify_method'] ?? ''), overdue_notify_options()) ? (string) $_POST['overdue_notify_method'] : 'notifications';
            $avatarUrl = trim($_POST['avatar_url'] ?? '');
            if (!valid_avatar_url($avatarUrl)) {
                flash('Use a valid http or https profile picture link.', 'error');
                redirect('?a=profile');
            }
            $avatarPath = save_avatar_upload($u);
            if (!empty($_POST['remove_avatar'])) {
                $avatarPath = null;
                $avatarUrl = '';
            } elseif ($avatarPath === null) {
                $avatarPath = $u['avatar_path'] ?? null;
            } else {
                $avatarUrl = '';
            }
            db()->prepare('UPDATE users SET country_code=?, overdue_notify_method=?, avatar_path=?, avatar_url=? WHERE id=?')->execute([$country, $overdueNotifyMethod, $avatarPath, $avatarUrl !== '' ? $avatarUrl : null, $u['id']]);
            setcookie('theme', $theme, ['expires' => time() + 31536000, 'path' => '/', 'secure' => false, 'httponly' => false, 'samesite' => 'Lax']);
            setcookie('language', $language, ['expires' => time() + 31536000, 'path' => '/', 'secure' => false, 'httponly' => false, 'samesite' => 'Lax']);
            $_COOKIE['theme'] = $theme;
            $_COOKIE['language'] = $language;
            log_activity($u, 'account.preferences_updated');
            flash('Profile settings saved.');
        } elseif ($action === 'email') {
            if (!password_verify($_POST['current_password'] ?? '', $u['password_hash'])) {
                flash('Enter your current password before changing email.', 'error');
                redirect('?a=profile');
            }
            $email = strtolower(trim($_POST['new_email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('Use a valid new email address.', 'error');
                redirect('?a=profile');
            }
            if ($email === strtolower((string) $u['email'])) {
                flash('That is already your account email.', 'error');
                redirect('?a=profile');
            }
            $exists = db()->prepare('SELECT COUNT(*) FROM users WHERE email=? AND id<>?');
            $exists->execute([$email, $u['id']]);
            if ((int) $exists->fetchColumn() > 0) {
                flash('That email address is already in use.', 'error');
                redirect('?a=profile');
            }
            db()->prepare('UPDATE users SET pending_email=? WHERE id=?')->execute([$email, $u['id']]);
            log_activity($u, 'account.email_change_requested');
            flash(send_email_change_verification((int) $u['id'], $email, $u['username']) ? 'Confirmation sent to your new email address.' : 'Email change saved, but the mail server could not send confirmation. Try again shortly.', 'ok');
        } elseif ($action === 'username') {
            if (!password_verify($_POST['current_password'] ?? '', $u['password_hash'])) {
                flash('Enter your current password before changing username.', 'error');
                redirect('?a=profile');
            }
            $username = trim($_POST['username'] ?? '');
            if ($username === '' || strlen($username) > 40) {
                flash('Use a username between 1 and 40 characters.', 'error');
                redirect('?a=profile');
            }
            try {
                db()->prepare('UPDATE users SET username=? WHERE id=?')->execute([$username, $u['id']]);
                log_activity(['id' => (int) $u['id'], 'username' => $username], 'account.username_updated');
                flash('Username updated.');
            } catch (Throwable $e) {
                flash('That username is already taken.', 'error');
            }
        } elseif ($action === 'password') {
            if (!password_verify($_POST['current_password'] ?? '', $u['password_hash'])) {
                flash('Enter your current password before changing password.', 'error');
                redirect('?a=profile');
            }
            $password = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            if ($password !== $confirm) {
                flash('New password confirmation does not match.', 'error');
                redirect('?a=profile');
            }
            if (strlen($password) < 10) {
                flash('Use a password of at least 10 characters.', 'error');
                redirect('?a=profile');
            }
            [$strong, $missing] = password_strength($password);
            db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
            revoke_trusted_2fa_tokens((int) $u['id']);
            log_activity($u, 'account.password_updated');
            flash($strong ? 'Password updated.' : 'Password updated, but it is weaker than recommended. Add ' . implode(', ', $missing) . '.');
        } elseif ($action === 'mobile_biometric') {
            if (!password_verify($_POST['current_password'] ?? '', $u['password_hash'])) {
                flash('Enter your current password before changing mobile fingerprint login.', 'error');
                redirect('?a=profile');
            }
            $enabled = !empty($_POST['mobile_biometric_enabled']) ? 1 : 0;
            db()->prepare('UPDATE users SET mobile_biometric_enabled=? WHERE id=?')->execute([$enabled, $u['id']]);
            if (!$enabled) {
                db()->prepare('DELETE FROM mobile_unlock_tokens WHERE user_id=?')->execute([$u['id']]);
                clear_mobile_unlock_cookie();
            }
            log_activity($u, $enabled ? 'account.mobile_biometric_enabled' : 'account.mobile_biometric_disabled');
            flash($enabled ? 'Mobile fingerprint login enabled for the Android app.' : 'Mobile fingerprint login disabled.');
        }
        redirect('?a=profile');
    }
    $u = require_login();
    $defaultTheme = setting('site_default_theme', 'light') === 'dark' ? 'dark' : 'light';
    $theme = in_array($_COOKIE['theme'] ?? '', ['light', 'dark'], true) ? (string) $_COOKIE['theme'] : $defaultTheme;
    $languages = app_languages();
    $defaultLanguage = isset($languages[setting('site_default_language', 'en')]) ? setting('site_default_language', 'en') : 'en';
    $language = isset($languages[$_COOKIE['language'] ?? '']) ? (string) $_COOKIE['language'] : $defaultLanguage;
    $country = country_code($u['country_code'] ?? 'GB');
    $pendingEmail = !empty($u['pending_email']) ? '<p class="pending-note">Pending confirmation: ' . h($u['pending_email']) . '</p>' : '';
    $settings = '<h2>Profile Picture & Preferences</h2><div class="profile-summary">' . avatar_html($u, 'profile-avatar') . '<span class="profile-flag">' . h(country_flag($country)) . '</span><div><strong class="user-content">' . h($u['username']) . '</strong><p class="muted user-content">' . h($u['email']) . '</p>' . $pendingEmail . '<p class="muted" data-country-name="' . h($country) . '">' . h(countries()[$country] ?? $country) . '</p></div></div><form method="post" enctype="multipart/form-data" class="form-grid settings-form"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="account_action" value="settings"><div class="inline-grid profile-preferences"><div class="form-row"><label>Country / region</label><select class="country-list" name="country_code" size="10">' . country_options($country) . '</select><p class="muted field-help">Scroll to see every country and region.</p></div><div class="form-row compact-preference"><label>Theme</label><select class="compact-select" name="theme">' . select_options(['light' => 'Light mode', 'dark' => 'Dark mode'], $theme) . '</select></div></div><div class="inline-grid"><div class="form-row compact-preference"><label>Language</label><select class="compact-select" name="language">' . select_options($languages, $language) . '</select></div><div class="form-row compact-preference"><label>Overdue task reminders</label><select class="compact-select" name="overdue_notify_method">' . select_options(overdue_notify_options(), overdue_notify_method($u)) . '</select><p class="muted field-help">Notifications show in the browser and Android app. E-mail is sent once per overdue task.</p></div></div><div class="inline-grid"><div class="form-row"><label>Upload profile picture</label><input type="file" name="avatar_file" accept="image/png,image/jpeg,image/gif,image/webp"></div><div class="form-row"><label>Profile picture link</label><input type="url" name="avatar_url" value="' . h($u['avatar_url'] ?? '') . '" placeholder="https://example.com/me.jpg"></div></div><label class="checkbox-line"><input type="checkbox" name="remove_avatar" value="1"> Remove current profile picture</label><button>Save settings</button></form>';
    $email = '<h2>Email Address</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="account_action" value="email"><div class="form-row"><label>New email address</label><input type="email" name="new_email" required></div><div class="form-row"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required></div><button>Send confirmation</button></form>';
    $username = '<h2>Username</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="account_action" value="username"><div class="form-row"><label>Username</label><input name="username" value="' . h($u['username']) . '" maxlength="40" required></div><div class="form-row"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required></div><button>Change username</button></form>';
    $password = '<h2>Password</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="account_action" value="password"><div class="form-row"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required></div><div class="inline-grid"><div class="form-row"><label>New password</label><input type="password" name="new_password" minlength="10" autocomplete="new-password" required data-password-check>' . password_help() . '</div><div class="form-row"><label>Confirm new password</label><input type="password" name="confirm_password" minlength="10" autocomplete="new-password" required></div></div><button>Change password</button></form>';
    $twofa = (int) $u['twofa_enabled'] === 1 ? '<span class="pill">Enabled</span><form method="post" action="?a=disable-2fa" class="btn-row"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><button class="danger">Disable 2FA</button></form>' : '<span class="pill">Disabled</span><a class="btn" href="?a=enable-2fa">Enable 2FA</a>';
    $biometric = '<h2>Mobile Fingerprint Login</h2><p class="muted">Use fingerprint or device unlock when opening the installed Todoom Android app. After one password login, this device can unlock with fingerprint for up to 30 days unless you sign out.</p><form method="post" class="form-grid biometric-settings"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="account_action" value="mobile_biometric"><label class="checkbox-line"><input type="checkbox" name="mobile_biometric_enabled" value="1" ' . ((int) ($u['mobile_biometric_enabled'] ?? 0) === 1 ? 'checked' : '') . '> Allow mobile fingerprint login</label><div class="form-row"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password" required></div><button>Save fingerprint setting</button></form>';
    layout('Profile', '<section class="panel panel-pad"><h1>User Settings</h1>' . $settings . '<hr class="divider">' . $email . '<hr class="divider">' . $username . '<hr class="divider">' . $password . '<hr class="divider"><h2>Two-Factor Authentication</h2><div class="btn-row">' . $twofa . '</div><hr class="divider">' . $biometric . '</section>');
}
function enable_2fa(): void
{
    $u = require_login(); $secret = $_SESSION['new_2fa_secret'] ??= base32_secret(); $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        if (verify_totp($secret, $_POST['code'] ?? '')) { db()->prepare('UPDATE users SET twofa_secret=?, twofa_enabled=1 WHERE id=?')->execute([$secret, $u['id']]); unset($_SESSION['new_2fa_secret']); log_activity($u, 'account.2fa_enabled'); flash('Two-factor authentication enabled.'); redirect('?a=profile'); }
        $error = 'Invalid code. Scan the QR code and try again.';
    }
    $otpauth = 'otpauth://totp/' . rawurlencode(APP_NAME . ':' . $u['email']) . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode(APP_NAME) . '&algorithm=SHA1&digits=6&period=30';
    layout('Enable 2FA', '<section class="panel panel-pad"><h1>Enable 2FA</h1>' . ($error ? '<div class="flash error">' . h($error) . '</div>' : '') . '<p class="muted">Scan this QR code, then enter a 6-digit code.</p><img class="qr" src="?a=qr&data=' . urlencode($otpauth) . '" alt="2FA QR code"><form method="post" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><div class="form-row"><label>Authenticator code</label><input name="code" inputmode="numeric" autocomplete="one-time-code" required></div><button>Enable 2FA</button></form><p class="muted">Manual secret: <code>' . h($secret) . '</code></p></section>');
}
function disable_2fa(): void { check_csrf(); $u = require_login(); db()->prepare('UPDATE users SET twofa_secret=NULL, twofa_enabled=0 WHERE id=?')->execute([$u['id']]); revoke_trusted_2fa_tokens((int) $u['id']); log_activity($u, 'account.2fa_disabled'); flash('Two-factor authentication disabled.'); redirect('?a=profile'); }
function qr(): void { $data = $_GET['data'] ?? ''; if ($data === '') { http_response_code(400); exit; } header('Content-Type: image/svg+xml'); echo (new Writer(new ImageRenderer(new RendererStyle(260), new SvgImageBackEnd())))->writeString($data); }

function base32_secret(int $length = 32): string { $a='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $s=''; for($i=0;$i<$length;$i++) $s.=$a[random_int(0, strlen($a)-1)]; return $s; }
function base32_decode_secret(string $secret): string { $secret=strtoupper(preg_replace('/[^A-Z2-7]/','',$secret)); $a='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits=''; foreach(str_split($secret) as $c) $bits.=str_pad(decbin(strpos($a,$c)),5,'0',STR_PAD_LEFT); $out=''; foreach(str_split($bits,8) as $b) if(strlen($b)===8) $out.=chr(bindec($b)); return $out; }
function totp(string $secret, ?int $slice = null): string { $slice ??= (int) floor(time()/30); $hash=hash_hmac('sha1', pack('N*',0,$slice), base32_decode_secret($secret), true); $off=ord(substr($hash,-1))&0x0F; $v=unpack('N',substr($hash,$off,4))[1]&0x7fffffff; return str_pad((string)($v%1000000),6,'0',STR_PAD_LEFT); }
function verify_totp(?string $secret, string $code): bool { if(!$secret) return false; $code=preg_replace('/\D/','',$code); $slice=(int)floor(time()/30); foreach([-1,0,1] as $w) if(hash_equals(totp($secret,$slice+$w),$code)) return true; return false; }
function select_options(array $options, string $selected): string { $html=''; foreach($options as $value=>$label) $html.='<option value="'.h((string)$value).'" '.((string)$value===$selected?'selected':'').'>'.h((string)$label).'</option>'; return $html; }

function admin_dashboard(): void
{
    require_admin();
    $counts = ['users'=>db()->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'tasks'=>db()->query('SELECT COUNT(*) FROM tasks')->fetchColumn(), 'lists'=>db()->query('SELECT COUNT(*) FROM lists')->fetchColumn()];
    layout('Admin', '<section class="panel panel-pad"><h1>Admin Panel</h1><div class="btn-row"><span class="pill">' . (int) $counts['users'] . ' users</span><span class="pill">' . (int) $counts['lists'] . ' lists</span><span class="pill">' . (int) $counts['tasks'] . ' tasks</span></div><br><div class="btn-row"><a class="btn" href="?a=admin-users">Users</a><a class="btn secondary" href="?a=admin-activity">User Activity</a><a class="btn secondary" href="?a=admin-settings">Settings</a></div></section>');
}
function admin_users(): void
{
    $admin = require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM users WHERE id=?'); $stmt->execute([$id]); $target = $stmt->fetch();
        if (!$target) { flash('User not found.', 'error'); redirect('?a=admin-users'); }
        if (($_POST['admin_action'] ?? 'save') === 'delete') {
            if ($id === (int) $admin['id']) { flash('You cannot delete your own administrator account.', 'error'); redirect('?a=admin-users'); }
            db()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
            log_activity($admin, 'admin.user.deleted', (string) $target['username']);
            flash('User deleted.'); redirect('?a=admin-users');
        }
        $username = trim($_POST['username'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        if ($username === '' || strlen($username) > 40 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Use a valid username and email address.', 'error'); redirect('?a=admin-users');
        }
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $status = ($_POST['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active';
        if ($id === (int) $admin['id']) { $role = 'admin'; $status = 'active'; }
        $verifiedAt = !empty($_POST['verified']) ? ($target['email_verified_at'] ?: now()) : null;
        try {
            db()->prepare('UPDATE users SET username=?,email=?,role=?,status=?,email_verified_at=? WHERE id=?')->execute([$username, $email, $role, $status, $verifiedAt, $id]);
            log_activity($admin, 'admin.user.updated', $username);
            flash('User updated.');
        } catch (Throwable $e) { flash('That username or email is already registered.', 'error'); }
        redirect('?a=admin-users');
    }
    $rows = '';
    foreach (db()->query('SELECT * FROM users ORDER BY created_at DESC') as $u) {
        $isSelf = (int) $u['id'] === (int) $admin['id'];
        $rows .= '<article class="admin-user-card"><form method="post" class="admin-user-form"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="id" value="' . (int) $u['id'] . '"><div class="form-row"><label>Username</label><input name="username" value="' . h($u['username']) . '" maxlength="40" required></div><div class="form-row"><label>Email</label><input type="email" name="email" value="' . h($u['email']) . '" required></div><div class="form-row"><label>Role</label><select name="role" ' . ($isSelf ? 'disabled' : '') . '>' . select_options(['user'=>'User','admin'=>'Admin'], $u['role']) . '</select></div><div class="form-row"><label>Status</label><select name="status" ' . ($isSelf ? 'disabled' : '') . '>' . select_options(['active'=>'Active','disabled'=>'Disabled'], $u['status']) . '</select></div><label class="checkbox-line"><input type="checkbox" name="verified" value="1" ' . ($u['email_verified_at'] ? 'checked' : '') . '> Email verified</label><span class="pill">2FA: ' . ((int) $u['twofa_enabled'] ? 'Yes' : 'No') . '</span><div class="btn-row admin-user-actions"><button name="admin_action" value="save">Save changes</button><button class="danger" name="admin_action" value="delete" data-confirm="Delete this user and all their lists and tasks?" ' . ($isSelf ? 'disabled title="You cannot delete your own account"' : '') . '>Delete user</button></div></form></article>';
    }
    layout('Users', '<section class="panel panel-pad"><div class="page-title"><div><h1>Registered Users</h1><p class="muted">Edit account details, access, and verification status.</p></div><a class="btn secondary" href="?a=admin">Admin Panel</a></div><div class="admin-user-list">' . $rows . '</div></section>');
}
function format_activity_date(string $date): string
{
    try { return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/London'))->format('d/m/Y H:i'); }
    catch (Throwable $e) { return $date; }
}
function activity_label(string $action): string
{
    return [
        'account.created' => 'Account created', 'account.registered' => 'Account registered',
        'account.login' => 'Signed in', 'account.logout' => 'Signed out',
        'account.preferences_updated' => 'Preferences updated', 'account.email_change_requested' => 'Email change requested',
        'account.username_updated' => 'Username updated', 'account.password_updated' => 'Password updated',
        'account.password_reset' => 'Password reset', 'account.2fa_enabled' => '2FA enabled', 'account.2fa_disabled' => '2FA disabled',
        'account.mobile_biometric_enabled' => 'Mobile fingerprint login enabled', 'account.mobile_biometric_disabled' => 'Mobile fingerprint login disabled',
        'account.mobile_unlocked' => 'Mobile app unlocked',
        'list.created' => 'List created', 'list.updated' => 'List updated', 'list.deleted' => 'List deleted',
        'list.archived' => 'List archived', 'list.restored' => 'List reactivated', 'lists.reordered' => 'Lists reordered',
        'task.created' => 'Task created', 'task.updated' => 'Task updated', 'task.completed' => 'Task completed',
        'task.reopened' => 'Task reopened', 'task.deleted' => 'Task deleted', 'tasks.deleted' => 'Tasks deleted',
        'task.overdue_email_sent' => 'Overdue e-mail sent',
        'task.due_email_sent' => 'Due reminder e-mail sent',
        'admin.user.updated' => 'User updated by admin', 'admin.user.deleted' => 'User deleted by admin',
        'admin.settings.updated' => 'Website settings updated',
    ][$action] ?? $action;
}
function admin_activity(): void
{
    require_admin();
    $q = trim($_GET['q'] ?? '');
    if ($q !== '') {
        $stmt = db()->prepare('SELECT * FROM activity_logs WHERE username LIKE ? OR action LIKE ? OR details LIKE ? ORDER BY created_at DESC LIMIT 200');
        $stmt->execute(["%$q%", "%$q%", "%$q%"]);
    } else $stmt = db()->query('SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 200');
    $rows = '';
    foreach ($stmt as $row) {
        $rows .= '<tr><td>' . h(format_activity_date($row['created_at'])) . '</td><td class="user-content">' . h($row['username']) . '</td><td>' . h(activity_label($row['action'])) . '</td><td class="user-content">' . h($row['details'] ?: '-') . '</td><td>' . h($row['ip_address'] ?: '-') . '</td></tr>';
    }
    if ($rows === '') $rows = '<tr><td colspan="5" class="muted">No activity recorded yet.</td></tr>';
    $html = '<section class="panel panel-pad"><div class="page-title"><div><h1>User Activity</h1><p class="muted">Latest account and todo actions across Todoom.</p></div><a class="btn secondary" href="?a=admin">Admin Panel</a></div><form class="activity-filter" method="get"><input type="hidden" name="a" value="admin-activity"><input name="q" value="' . h($q) . '" placeholder="Search user or activity"><button>Search</button><a class="btn secondary" href="?a=admin-activity">Reset</a></form><div class="table-wrap"><table class="table"><tr><th>Date</th><th>User</th><th>Activity</th><th>Details</th><th>IP address</th></tr>' . $rows . '</table></div></section>';
    layout('User Activity', $html);
}
function admin_settings(): void
{
    $admin = require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        set_setting('allow_registration', !empty($_POST['allow_registration']) ? '1' : '0');
        set_setting('require_email_verification', !empty($_POST['require_email_verification']) ? '1' : '0');
        foreach (['site_url','mail_from_email','mail_from_name','mail_envelope_sender','mail_reply_to'] as $n) set_setting($n, trim($_POST[$n] ?? ''));
        set_setting('site_default_theme', ($_POST['site_default_theme'] ?? 'light') === 'dark' ? 'dark' : 'light');
        $language = (string) ($_POST['site_default_language'] ?? 'en');
        set_setting('site_default_language', isset(app_languages()[$language]) ? $language : 'en');
        log_activity($admin, 'admin.settings.updated');
        flash('Settings saved.'); redirect('?a=admin-settings');
    }
    $html = '<section class="panel panel-pad"><div class="page-title"><h1>Settings</h1><a class="btn secondary" href="?a=admin">Admin Panel</a></div><form method="post" class="form-grid"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><div class="btn-row"><label><input type="checkbox" name="allow_registration" value="1" ' . (setting('allow_registration')==='1'?'checked':'') . '> Allow registration</label><label><input type="checkbox" name="require_email_verification" value="1" ' . (setting('require_email_verification')==='1'?'checked':'') . '> Require email confirmation</label></div><div class="inline-grid default-settings"><div class="form-row"><label>Default theme</label><select class="compact-select" name="site_default_theme">' . select_options(['light'=>'Light mode','dark'=>'Dark mode'], setting('site_default_theme','light')) . '</select></div><div class="form-row"><label>Default language</label><select name="site_default_language">' . select_options(app_languages(), setting('site_default_language','en')) . '</select></div></div><div class="form-row"><label>Site URL</label><input name="site_url" value="' . h(setting('site_url')) . '"></div><div class="inline-grid"><div class="form-row"><label>From email</label><input name="mail_from_email" value="' . h(setting('mail_from_email')) . '"></div><div class="form-row"><label>Envelope sender</label><input name="mail_envelope_sender" value="' . h(setting('mail_envelope_sender')) . '"></div></div><div class="inline-grid"><div class="form-row"><label>From name</label><input name="mail_from_name" value="' . h(setting('mail_from_name')) . '"></div><div class="form-row"><label>Reply-to</label><input name="mail_reply_to" value="' . h(setting('mail_reply_to')) . '"></div></div><button>Save settings</button></form></section>';
    layout('Settings', $html);
}

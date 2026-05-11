<?php
declare(strict_types=1);

/**
 * DrJessie.life contact form configuration.
 * Copy api/_config.local.php.example to api/_config.local.php and fill real values.
 */

$drjConfig = [
    'db_host' => 'localhost',
    'db_name' => 'change_this_db_name',
    'db_user' => 'change_this_db_user',
    'db_pass' => 'change_this_db_password',
    'admin_user' => 'change_this_admin_user',
    'admin_pass' => 'change_this_admin_password',
    'notification_email' => 'info@drjessie.life',
    'mail_from_email' => 'noreply@drjessie.life',
    'mail_from_name' => 'DrJessie.life Contact',
    'allowed_origin' => 'https://www.drjessie.life',
];

$localConfigPath = __DIR__ . '/_config.local.php';
if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (is_array($localConfig)) {
        $drjConfig = array_replace($drjConfig, $localConfig);
    }
}

function drj_config(string $key, ?string $fallback = null): ?string
{
    global $drjConfig;
    $value = $drjConfig[$key] ?? null;
    if ($value === null) {
        return $fallback;
    }

    $value = is_string($value) ? trim($value) : (string) $value;
    if ($value === '') {
        return $fallback;
    }

    return $value;
}

function drj_looks_unconfigured(string $value): bool
{
    $value = trim($value);
    return $value === '' || str_starts_with($value, 'change_this_');
}

function drj_admin_auth_credentials(): array
{
    $user = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
    $pass = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));

    if (($user === '' || $pass === '') && $auth !== '' && stripos($auth, 'basic ') === 0) {
        $decoded = base64_decode(substr($auth, 6), true);
        if (is_string($decoded) && strpos($decoded, ':') !== false) {
            [$user, $pass] = explode(':', $decoded, 2);
        }
    }

    return [$user, $pass];
}

function drj_require_admin_auth(string $realm): string
{
    $expectedUser = (string) drj_config('admin_user', '');
    $expectedPass = (string) drj_config('admin_pass', '');

    if (drj_looks_unconfigured($expectedUser) || drj_looks_unconfigured($expectedPass)) {
        http_response_code(500);
        exit('Admin credentials are not configured. Update api/_config.local.php.');
    }

    [$providedUser, $providedPass] = drj_admin_auth_credentials();
    if ($providedUser !== $expectedUser || $providedPass !== $expectedPass) {
        header('WWW-Authenticate: Basic realm="' . str_replace(chr(34), '', $realm) . '"');
        http_response_code(401);
        exit('Unauthorized');
    }

    return $providedUser;
}

function drj_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbHost = (string) drj_config('db_host', 'localhost');
    $dbName = (string) drj_config('db_name', '');
    $dbUser = (string) drj_config('db_user', '');
    $dbPass = (string) drj_config('db_pass', '');

    if (drj_looks_unconfigured($dbName) || drj_looks_unconfigured($dbUser) || drj_looks_unconfigured($dbPass)) {
        throw new RuntimeException('Database credentials are not configured. Update api/_config.local.php.');
    }

    $dsn = 'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

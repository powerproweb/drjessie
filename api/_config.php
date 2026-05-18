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
    'db_pass' => 'change_this_db_pass',
    'admin_user' => 'change_this_admin_user',
    'admin_pass' => 'change_this_admin_pass',
    'notification_email' => 'change_this_notification_email@example.com',
    'notification_flag_email' => 'jkideal@hotmail.com',
    'mail_from_email' => 'change_this_mail_from_email@example.com',
    'mail_from_name' => 'DrJessie.life Contact',
    'allowed_origin' => 'change_this_allowed_origin',
    'admin_inbox_url' => 'https://www.drjessie.life/admin/form-submissions.php',
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
function drj_authorization_header(): string
{
    $serverCandidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['Authorization'] ?? null,
        $_SERVER['REDIRECT_Authorization'] ?? null,
        $_SERVER['HTTP_X_HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['X-HTTP_AUTHORIZATION'] ?? null,
    ];

    foreach ($serverCandidates as $value) {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    $headerMaps = [];
    if (function_exists('getallheaders')) {
        $allHeaders = getallheaders();
        if (is_array($allHeaders)) {
            $headerMaps[] = $allHeaders;
        }
    }
    if (function_exists('apache_request_headers')) {
        $apacheHeaders = apache_request_headers();
        if (is_array($apacheHeaders)) {
            $headerMaps[] = $apacheHeaders;
        }
    }

    foreach ($headerMaps as $headers) {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0 && is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
    }

    return '';
}

function drj_parse_basic_auth(string $authHeader): array
{
    if ($authHeader === '' || stripos($authHeader, 'basic ') !== 0) {
        return ['', ''];
    }

    $encoded = trim(substr($authHeader, 6));
    if ($encoded === '') {
        return ['', ''];
    }

    $decoded = base64_decode($encoded, true);
    if (!is_string($decoded) || strpos($decoded, ':') === false) {
        return ['', ''];
    }

    [$user, $pass] = explode(':', $decoded, 2);
    return [trim($user), $pass];
}

function drj_admin_auth_credentials(): array
{
    $user = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
    $pass = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');

    if ($user === '' || $pass === '') {
        [$parsedUser, $parsedPass] = drj_parse_basic_auth(drj_authorization_header());
        if ($parsedUser !== '' || $parsedPass !== '') {
            $user = $parsedUser;
            $pass = $parsedPass;
        }
    }

    return [$user, $pass];
}

function drj_secret_matches(string $expected, string $provided): bool
{
    if (hash_equals($expected, $provided)) {
        return true;
    }

    $trimmedProvided = trim($provided);
    return $trimmedProvided !== $provided && hash_equals($expected, $trimmedProvided);
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
    $userMatches = hash_equals(strtolower(trim($expectedUser)), strtolower(trim((string) $providedUser)));
    $passMatches = drj_secret_matches($expectedPass, (string) $providedPass);

    if (!$userMatches || !$passMatches) {
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

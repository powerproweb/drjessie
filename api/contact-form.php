<?php
declare(strict_types=1);

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_rate_limit.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$allowedOrigin = (string) drj_config('allowed_origin', '');
$requestOrigin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($allowedOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Vary: Origin');
    if ($requestOrigin !== '') {
        $normalizedAllowedOrigin = rtrim(strtolower($allowedOrigin), '/');
        $normalizedRequestOrigin = rtrim(strtolower($requestOrigin), '/');
        if ($normalizedRequestOrigin !== $normalizedAllowedOrigin) {
            drj_contact_json_response(403, [
                'success' => false,
                'message' => 'Origin not allowed.',
            ]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$clientIp = drj_rate_limit_client_ip();
$contactRateLimit = drj_rate_limit_check('contact_form', $clientIp, 6, 300);
if (!$contactRateLimit['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . (string) $contactRateLimit['retry_after']);
    echo json_encode([
        'success' => false,
        'message' => 'Too many requests. Please try again shortly.',
    ]);
    exit;
}

const DRJ_CONTACT_UPLOAD_DIR = __DIR__ . '/../uploads/contact_messages';
if (!is_dir(DRJ_CONTACT_UPLOAD_DIR)) {
    mkdir(DRJ_CONTACT_UPLOAD_DIR, 0755, true);
}

function drj_contact_json_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function drj_contact_payload(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return $_POST;
}

function drj_contact_ensure_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dj_contact_submissions (
          id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          ticket_ref       VARCHAR(32)     NOT NULL COMMENT 'Unique contact reference code',
          full_name        VARCHAR(200)    NOT NULL,
          email            VARCHAR(255)    NOT NULL,
          subject          VARCHAR(255)    NOT NULL,
          message          MEDIUMTEXT      NOT NULL,
          consent_contact  TINYINT(1)      NOT NULL DEFAULT 1,
          ip_address       VARCHAR(45)     DEFAULT NULL,
          user_agent       VARCHAR(600)    DEFAULT NULL,
          status           ENUM('new','reviewed','resolved','spam') NOT NULL DEFAULT 'new',
          submitted_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_ticket_ref (ticket_ref),
          KEY idx_email (email),
          KEY idx_status (status),
          KEY idx_submitted_at (submitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ensured = true;
}

function drj_contact_text(array $payload, string $key, int $maxLength = 0): ?string
{
    $value = trim((string) ($payload[$key] ?? ''));
    if ($value === '') {
        return null;
    }

    $value = str_replace("\r\n", "\n", $value);
    $value = str_replace("\r", "\n", $value);

    if ($maxLength > 0 && strlen($value) > $maxLength) {
        $value = substr($value, 0, $maxLength);
    }

    return $value;
}

function drj_contact_int(array $payload, string $key): int
{
    return (int) ($payload[$key] ?? 0);
}

function drj_contact_ticket_ref(): string
{
    return 'DJ-CF-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function drj_contact_safe_header_value(string $value): string
{
    return trim(str_replace(["\r", "\n"], ' ', $value));
}

function drj_contact_safe_email(?string $email): ?string
{
    if ($email === null) {
        return null;
    }
    $email = drj_contact_safe_header_value($email);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

function drj_contact_mailbox(string $name, string $email): string
{
    $name = drj_contact_safe_header_value($name);
    $email = drj_contact_safe_header_value($email);
    if ($name === '') {
        return $email;
    }

    $escapedName = addcslashes($name, "\\\"");
    return '"' . $escapedName . '" <' . $email . '>';
}

/**
 * @return array{sent: bool, reason: string}
 */
function drj_contact_send_admin_email(array $record): array
{
    $to = drj_contact_safe_email(drj_config('notification_email', ''));
    if ($to === null) {
        return ['sent' => false, 'reason' => 'not_configured'];
    }

    $fromEmail = drj_contact_safe_email(drj_config('mail_from_email', ''));
    if ($fromEmail === null) {
        $fromEmail = $to;
    }
    $fromName = drj_contact_safe_header_value((string) drj_config('mail_from_name', 'DrJessie.life Contact'));
    if ($fromName === '') {
        $fromName = 'DrJessie.life Contact';
    }

    $replyToEmail = drj_contact_safe_email((string) ($record['email'] ?? ''));
    if ($replyToEmail === null) {
        $replyToEmail = $fromEmail;
    }
    $replyToName = drj_contact_safe_header_value((string) ($record['full_name'] ?? 'Contact Form Sender'));

    $subjectTail = drj_contact_safe_header_value((string) ($record['subject'] ?? 'New Message'));
    $subject = '[DrJessie Contact] ' . (string) ($record['ticket_ref'] ?? 'N/A') . ' - ' . $subjectTail;
    if (strlen($subject) > 180) {
        $subject = substr($subject, 0, 180);
    }

    $bodyLines = [
        'New contact form message was received.',
        '',
        'Ticket: ' . (string) ($record['ticket_ref'] ?? 'N/A'),
        'Submitted: ' . (string) ($record['submitted_at_utc'] ?? gmdate('c')),
        'Name: ' . (string) ($record['full_name'] ?? 'N/A'),
        'Email: ' . (string) ($record['email'] ?? 'N/A'),
        'Subject: ' . (string) ($record['subject'] ?? 'N/A'),
        'IP: ' . (string) ($record['ip_address'] ?? 'N/A'),
        '',
        'Message:',
        (string) ($record['message'] ?? ''),
    ];

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . drj_contact_mailbox($fromName, $fromEmail),
        'Reply-To: ' . drj_contact_mailbox($replyToName, $replyToEmail),
        'X-Auto-Response-Suppress: All',
        'X-DrJessie-Ticket: ' . drj_contact_safe_header_value((string) ($record['ticket_ref'] ?? 'N/A')),
    ];

    $sent = @mail($to, $subject, implode("\n", $bodyLines), implode("\r\n", $headers));
    return ['sent' => (bool) $sent, 'reason' => $sent ? 'sent' : 'mail_failed'];
}

$payload = drj_contact_payload();
$errors = [];

$fullName = drj_contact_text($payload, 'full_name', 120);
$email = drj_contact_text($payload, 'email', 160);
$subject = drj_contact_text($payload, 'subject', 180);
$message = drj_contact_text($payload, 'message', 6000);
$honeypot = trim((string) ($payload['company_website'] ?? ''));
$humanSliderValue = drj_contact_int($payload, 'human_slider_value');
$humanTargetValue = drj_contact_int($payload, 'human_target_value');
$humanElapsedMs = drj_contact_int($payload, 'human_elapsed_ms');
$consentContact = !empty($payload['consent_contact']);

if ($fullName === null) {
    $errors[] = 'Full name is required.';
}

if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email address is required.';
}

if ($subject === null) {
    $errors[] = 'Subject is required.';
}

if ($message === null) {
    $errors[] = 'Message is required.';
}

if (!$consentContact) {
    $errors[] = 'Consent is required before submitting.';
}

if ($honeypot !== '') {
    $errors[] = 'Submission could not be accepted.';
}

if ($humanTargetValue < 60 || $humanTargetValue > 92) {
    $errors[] = 'Submission could not be accepted.';
}

if ($humanSliderValue < $humanTargetValue) {
    $errors[] = 'Slide until the meter reaches 100% before submitting.';
}

if ($humanElapsedMs < 1200) {
    $errors[] = 'Please wait a second and submit again.';
}

if ($errors) {
    drj_contact_json_response(422, [
        'success' => false,
        'message' => 'Validation failed.',
        'errors' => $errors,
    ]);
}

$ticketRef = drj_contact_ticket_ref();
$record = [
    'ticket_ref' => $ticketRef,
    'submitted_at_utc' => gmdate('c'),
    'full_name' => $fullName,
    'email' => $email,
    'subject' => $subject,
    'message' => $message,
    'consent_contact' => $consentContact ? 1 : 0,
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
];

$dbSaved = false;
$dbError = null;
try {
    $pdo = drj_pdo();
    drj_contact_ensure_table($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO dj_contact_submissions (
            ticket_ref, full_name, email, subject, message,
            consent_contact, ip_address, user_agent
        ) VALUES (
            :ticket_ref, :full_name, :email, :subject, :message,
            :consent_contact, :ip_address, :user_agent
        )'
    );
    $stmt->execute([
        ':ticket_ref' => $ticketRef,
        ':full_name' => $fullName,
        ':email' => $email,
        ':subject' => $subject,
        ':message' => $message,
        ':consent_contact' => $consentContact ? 1 : 0,
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
    $dbSaved = true;
} catch (Throwable $e) {
    $dbError = $e->getMessage();
    error_log('DrJessie contact DB save failed: ' . $dbError);
}

$recordPath = DRJ_CONTACT_UPLOAD_DIR . '/' . $ticketRef . '.json';
$fileSaved = file_put_contents($recordPath, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;

if (!$dbSaved) {
    drj_contact_json_response(500, [
        'success' => false,
        'message' => 'Could not save your message right now. Please try again.',
        'errors' => ['The server could not write to the contact inbox table.'],
        'storage' => ['db' => false, 'file' => $fileSaved],
    ]);
}

$notification = drj_contact_send_admin_email($record);
if (!$notification['sent'] && $notification['reason'] !== 'not_configured') {
    error_log('DrJessie contact notification email failed for ' . $ticketRef);
}

drj_contact_json_response(200, [
    'success' => true,
    'message' => 'Message received successfully.',
    'ticket_ref' => $ticketRef,
    'storage' => ['db' => true, 'file' => $fileSaved],
    'notification' => $notification,
]);

<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/_config.php';
$adminActor = drj_require_admin_auth('DrJessie Form Submissions');

const DRJ_FORM_EVENTS_TABLE = 'dj_contact_submission_events';
const DRJ_FORM_ARCHIVE_TABLE = 'dj_contact_submission_archive';

function drj_inbox_json_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function drj_inbox_parse_json_body(): array
{
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : [];
}

function drj_inbox_allowed_statuses(): array
{
    return ['new', 'reviewed', 'resolved', 'spam'];
}

function drj_inbox_normalize_status(mixed $status): ?string
{
    $value = strtolower(trim((string) $status));
    if ($value === '') {
        return null;
    }
    return in_array($value, drj_inbox_allowed_statuses(), true) ? $value : null;
}

function drj_inbox_is_missing_table_error(Throwable $e, string $tableName): bool
{
    $message = strtolower($e->getMessage());
    if (strpos($message, strtolower($tableName)) !== false && strpos($message, "doesn't exist") !== false) {
        return true;
    }

    if ($e instanceof PDOException && isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1146) {
        if (isset($e->errorInfo[2]) && stripos((string) $e->errorInfo[2], $tableName) !== false) {
            return true;
        }
    }

    return false;
}

function drj_inbox_json_encode(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Unable to encode archive payload.');
    }
    return $json;
}

function drj_inbox_expected_delete_confirmation(string $ticketRef): string
{
    return 'DELETE ' . strtoupper(trim($ticketRef));
}

function drj_inbox_list_rows(PDO $pdo, ?string $status, string $query, int $limit, int $offset): array
{
    $params = [];
    $where = [];

    if ($status !== null) {
        $where[] = 'status = :status';
        $params[':status'] = $status;
    }

    if ($query !== '') {
        $where[] = '(ticket_ref LIKE :q OR full_name LIKE :q OR email LIKE :q OR subject LIKE :q OR message LIKE :q)';
        $params[':q'] = '%' . $query . '%';
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $listSql = "SELECT id, ticket_ref, full_name, email, subject, status, submitted_at, updated_at
                FROM dj_contact_submissions
                {$whereSql}
                ORDER BY submitted_at DESC
                LIMIT :limit OFFSET :offset";

    $countSql = "SELECT COUNT(*) FROM dj_contact_submissions {$whereSql}";

    $stmt = $pdo->prepare($listSql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    return [$rows, $total];
}

function drj_inbox_fetch_detail(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM dj_contact_submissions WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $events = [];
    $eventsAvailable = true;
    try {
        $evtStmt = $pdo->prepare(
            'SELECT id, ticket_ref, event_type, from_status, to_status, note, actor, created_at
             FROM ' . DRJ_FORM_EVENTS_TABLE . '
             WHERE ticket_ref = :ticket_ref
             ORDER BY created_at DESC, id DESC
             LIMIT 200'
        );
        $evtStmt->execute([':ticket_ref' => (string) $row['ticket_ref']]);
        $events = $evtStmt->fetchAll();
    } catch (Throwable $e) {
        if (drj_inbox_is_missing_table_error($e, DRJ_FORM_EVENTS_TABLE)) {
            $eventsAvailable = false;
        } else {
            throw $e;
        }
    }

    return ['record' => $row, 'events' => $events, 'events_available' => $eventsAvailable];
}

function drj_inbox_ensure_events_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ' . DRJ_FORM_EVENTS_TABLE . ' (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_ref VARCHAR(32) NOT NULL,
            event_type ENUM(\'status_change\',\'note\',\'reply\') NOT NULL,
            from_status VARCHAR(24) DEFAULT NULL,
            to_status VARCHAR(24) DEFAULT NULL,
            note TEXT DEFAULT NULL,
            actor VARCHAR(120) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ticket_ref_created (ticket_ref, created_at),
            KEY idx_event_type (event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function drj_inbox_ensure_archive_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ' . DRJ_FORM_ARCHIVE_TABLE . ' (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id BIGINT UNSIGNED NOT NULL,
            ticket_ref VARCHAR(32) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            related_events_json LONGTEXT NULL,
            deleted_by VARCHAR(120) NOT NULL,
            deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_deleted_at (deleted_at),
            KEY idx_ticket_ref (ticket_ref),
            KEY idx_submission_id (submission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function drj_inbox_log_event(PDO $pdo, string $ticketRef, string $eventType, ?string $fromStatus, ?string $toStatus, string $note, string $actor): bool
{
    try {
        drj_inbox_ensure_events_table($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO ' . DRJ_FORM_EVENTS_TABLE . ' (ticket_ref, event_type, from_status, to_status, note, actor)
             VALUES (:ticket_ref, :event_type, :from_status, :to_status, :note, :actor)'
        );
        $stmt->execute([
            ':ticket_ref' => $ticketRef,
            ':event_type' => $eventType,
            ':from_status' => $fromStatus,
            ':to_status' => $toStatus,
            ':note' => $note === '' ? null : $note,
            ':actor' => $actor,
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('[drj form inbox] event log failed: ' . $e->getMessage());
        return false;
    }
}

function drj_inbox_update_status(PDO $pdo, int $id, string $newStatus, string $note, string $actor): array
{
    $pdo->beginTransaction();
    try {
        $select = $pdo->prepare('SELECT id, ticket_ref, status FROM dj_contact_submissions WHERE id = :id LIMIT 1 FOR UPDATE');
        $select->execute([':id' => $id]);
        $row = $select->fetch();
        if (!$row) {
            $pdo->rollBack();
            throw new RuntimeException('Submission not found.');
        }

        $oldStatus = (string) $row['status'];
        if ($oldStatus !== $newStatus) {
            $update = $pdo->prepare('UPDATE dj_contact_submissions SET status = :status WHERE id = :id');
            $update->execute([':status' => $newStatus, ':id' => $id]);
        }

        $eventLogged = false;
        $ticketRef = (string) $row['ticket_ref'];
        if ($oldStatus !== $newStatus || $note !== '') {
            $eventType = $oldStatus !== $newStatus ? 'status_change' : 'note';
            $eventLogged = drj_inbox_log_event($pdo, $ticketRef, $eventType, $oldStatus, $newStatus, $note, $actor);
        }

        $pdo->commit();
        return [
            'ticket_ref' => $ticketRef,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'event_logged' => $eventLogged,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function drj_inbox_safe_email(?string $email): ?string
{
    if ($email === null) {
        return null;
    }
    $email = trim(str_replace(["\r", "\n"], ' ', $email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

function drj_inbox_mailbox(string $name, string $email): string
{
    $name = trim(str_replace(["\r", "\n"], ' ', $name));
    $email = trim(str_replace(["\r", "\n"], ' ', $email));
    if ($name === '') {
        return $email;
    }
    $escapedName = addcslashes($name, "\\\"");
    return '"' . $escapedName . '" <' . $email . '>';
}

function drj_inbox_send_reply_email(array $record, string $subject, string $body): array
{
    $to = drj_inbox_safe_email((string) ($record['email'] ?? ''));
    if ($to === null) {
        return ['sent' => false, 'reason' => 'invalid_recipient'];
    }

    $fromEmail = drj_inbox_safe_email(drj_config('mail_from_email', ''));
    if ($fromEmail === null) {
        $fromEmail = drj_inbox_safe_email(drj_config('notification_email', ''));
    }
    if ($fromEmail === null) {
        return ['sent' => false, 'reason' => 'sender_not_configured'];
    }

    $fromName = trim((string) drj_config('mail_from_name', 'DrJessie.life'));
    if ($fromName === '') {
        $fromName = 'DrJessie.life';
    }

    $replyTo = drj_inbox_safe_email(drj_config('notification_email', '')) ?? $fromEmail;
    $replyToName = 'DrJessie Team';

    $safeSubject = trim(str_replace(["\r", "\n"], ' ', $subject));
    if ($safeSubject === '') {
        $safeSubject = 'Response from DrJessie.life';
    }
    if (strlen($safeSubject) > 180) {
        $safeSubject = substr($safeSubject, 0, 180);
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . drj_inbox_mailbox($fromName, $fromEmail),
        'Reply-To: ' . drj_inbox_mailbox($replyToName, $replyTo),
        'X-Auto-Response-Suppress: All',
    ];

    $sent = @mail($to, $safeSubject, $body, implode("\r\n", $headers));
    return ['sent' => (bool) $sent, 'reason' => $sent ? 'sent' : 'mail_failed', 'to' => $to];
}

function drj_inbox_send_reply(PDO $pdo, int $id, string $subject, string $message, string $actor): array
{
    $stmt = $pdo->prepare('SELECT id, ticket_ref, full_name, email, subject, status FROM dj_contact_submissions WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Submission not found.');
    }

    $fullName = trim((string) ($row['full_name'] ?? 'there'));
    $ticketRef = (string) ($row['ticket_ref'] ?? '');
    $preface = "Hi " . ($fullName !== '' ? $fullName : 'there') . ",\n\n";
    $closing = "\n\nRegards,\nDrJessie Team";
    $body = $preface . trim($message) . $closing;

    $result = drj_inbox_send_reply_email($row, $subject, $body);
    if (!$result['sent']) {
        throw new RuntimeException('Reply email could not be sent.');
    }

    $preview = trim($message);
    if (strlen($preview) > 1200) {
        $preview = substr($preview, 0, 1200) . "\n...[truncated]";
    }
    $note = "Reply subject: " . trim($subject) . "\n\n" . $preview;
    $eventLogged = drj_inbox_log_event($pdo, $ticketRef, 'reply', (string) ($row['status'] ?? ''), (string) ($row['status'] ?? ''), $note, $actor);

    return [
        'ticket_ref' => $ticketRef,
        'to' => $result['to'] ?? '',
        'subject' => trim($subject),
        'event_logged' => $eventLogged,
    ];
}

function drj_inbox_delete_submission(PDO $pdo, int $id, string $confirmation, string $actor): array
{
    $pdo->beginTransaction();
    try {
        $select = $pdo->prepare('SELECT * FROM dj_contact_submissions WHERE id = :id LIMIT 1 FOR UPDATE');
        $select->execute([':id' => $id]);
        $row = $select->fetch();
        if (!$row) {
            throw new RuntimeException('Submission not found.');
        }

        $ticketRef = (string) $row['ticket_ref'];
        $expected = drj_inbox_expected_delete_confirmation($ticketRef);
        if ($confirmation !== $expected) {
            throw new InvalidArgumentException('Delete confirmation phrase does not match.');
        }

        $eventRows = [];
        $eventsDeleted = false;
        try {
            drj_inbox_ensure_events_table($pdo);
            $evtRows = $pdo->prepare(
                'SELECT id, ticket_ref, event_type, from_status, to_status, note, actor, created_at
                 FROM ' . DRJ_FORM_EVENTS_TABLE . '
                 WHERE ticket_ref = :ticket_ref
                 ORDER BY created_at ASC, id ASC
                 LIMIT 300'
            );
            $evtRows->execute([':ticket_ref' => $ticketRef]);
            $eventRows = $evtRows->fetchAll();
        } catch (Throwable $e) {
            if (!drj_inbox_is_missing_table_error($e, DRJ_FORM_EVENTS_TABLE)) {
                throw $e;
            }
        }

        drj_inbox_ensure_archive_table($pdo);
        $archiveStmt = $pdo->prepare(
            'INSERT INTO ' . DRJ_FORM_ARCHIVE_TABLE . ' (
                submission_id, ticket_ref, payload_json, related_events_json, deleted_by
            ) VALUES (
                :submission_id, :ticket_ref, :payload_json, :related_events_json, :deleted_by
            )'
        );
        $archiveStmt->execute([
            ':submission_id' => $id,
            ':ticket_ref' => $ticketRef,
            ':payload_json' => drj_inbox_json_encode($row),
            ':related_events_json' => empty($eventRows) ? null : drj_inbox_json_encode($eventRows),
            ':deleted_by' => $actor,
        ]);
        $archiveId = (int) $pdo->lastInsertId();

        try {
            $evtDelete = $pdo->prepare('DELETE FROM ' . DRJ_FORM_EVENTS_TABLE . ' WHERE ticket_ref = :ticket_ref');
            $evtDelete->execute([':ticket_ref' => $ticketRef]);
            $eventsDeleted = true;
        } catch (Throwable $e) {
            if (!drj_inbox_is_missing_table_error($e, DRJ_FORM_EVENTS_TABLE)) {
                throw $e;
            }
        }

        $delete = $pdo->prepare('DELETE FROM dj_contact_submissions WHERE id = :id');
        $delete->execute([':id' => $id]);
        $pdo->commit();

        return [
            'archive_id' => $archiveId,
            'ticket_ref' => $ticketRef,
            'events_deleted' => $eventsDeleted,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = drj_inbox_parse_json_body();
    $action = trim((string) ($input['action'] ?? ''));

    try {
        $pdo = drj_pdo();
    } catch (Throwable $e) {
        error_log('[drj form inbox] db bootstrap failed: ' . $e->getMessage());
        drj_inbox_json_response(500, ['ok' => false, 'error' => 'Database connection failed.']);
    }

    try {
        if ($action === 'list') {
            $status = drj_inbox_normalize_status($input['status'] ?? '');
            $query = trim((string) ($input['query'] ?? ''));
            $limit = (int) ($input['limit'] ?? 60);
            $offset = (int) ($input['offset'] ?? 0);

            if ($limit < 1) {
                $limit = 1;
            } elseif ($limit > 200) {
                $limit = 200;
            }
            if ($offset < 0) {
                $offset = 0;
            }

            [$rows, $total] = drj_inbox_list_rows($pdo, $status, $query, $limit, $offset);
            drj_inbox_json_response(200, [
                'ok' => true,
                'rows' => $rows,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'status' => $status,
            ]);
        }

        if ($action === 'detail') {
            $id = (int) ($input['id'] ?? 0);
            if ($id < 1) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Invalid submission id.']);
            }

            $detail = drj_inbox_fetch_detail($pdo, $id);
            if ($detail === null) {
                drj_inbox_json_response(404, ['ok' => false, 'error' => 'Submission not found.']);
            }

            drj_inbox_json_response(200, [
                'ok' => true,
                'detail' => $detail['record'],
                'events' => $detail['events'],
                'events_available' => $detail['events_available'],
            ]);
        }

        if ($action === 'update_status') {
            $id = (int) ($input['id'] ?? 0);
            $status = drj_inbox_normalize_status($input['status'] ?? '');
            $note = trim((string) ($input['note'] ?? ''));
            if ($id < 1) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Invalid submission id.']);
            }
            if ($status === null) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Invalid status value.']);
            }
            if (strlen($note) > 1500) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Note is too long.']);
            }

            $update = drj_inbox_update_status($pdo, $id, $status, $note, $adminActor);
            $fresh = drj_inbox_fetch_detail($pdo, $id);
            drj_inbox_json_response(200, [
                'ok' => true,
                'update' => $update,
                'detail' => $fresh['record'] ?? null,
                'events' => $fresh['events'] ?? [],
                'events_available' => $fresh['events_available'] ?? true,
            ]);
        }

        if ($action === 'send_reply') {
            $id = (int) ($input['id'] ?? 0);
            $subject = trim((string) ($input['subject'] ?? ''));
            $message = trim((string) ($input['message'] ?? ''));

            if ($id < 1) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Invalid submission id.']);
            }
            if ($subject === '' || strlen($subject) > 180) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Reply subject is required and must be 180 characters or less.']);
            }
            if ($message === '' || strlen($message) > 6000) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Reply message is required and must be 6000 characters or less.']);
            }

            $reply = drj_inbox_send_reply($pdo, $id, $subject, $message, $adminActor);
            $fresh = drj_inbox_fetch_detail($pdo, $id);
            drj_inbox_json_response(200, [
                'ok' => true,
                'reply' => $reply,
                'detail' => $fresh['record'] ?? null,
                'events' => $fresh['events'] ?? [],
                'events_available' => $fresh['events_available'] ?? true,
            ]);
        }

        if ($action === 'delete_submission') {
            $id = (int) ($input['id'] ?? 0);
            $confirmation = strtoupper(trim((string) ($input['confirmation'] ?? '')));
            if ($id < 1) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Invalid submission id.']);
            }
            if ($confirmation === '' || strlen($confirmation) > 120) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Delete confirmation phrase is required.']);
            }

            $deleted = drj_inbox_delete_submission($pdo, $id, $confirmation, $adminActor);
            drj_inbox_json_response(200, ['ok' => true, 'delete' => $deleted]);
        }

        drj_inbox_json_response(400, ['ok' => false, 'error' => 'Unknown action.']);
    } catch (InvalidArgumentException $e) {
        drj_inbox_json_response(400, ['ok' => false, 'error' => $e->getMessage()]);
    } catch (RuntimeException $e) {
        drj_inbox_json_response(404, ['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        if (drj_inbox_is_missing_table_error($e, 'dj_contact_submissions')) {
            drj_inbox_json_response(500, ['ok' => false, 'error' => 'Contact inbox table is missing.']);
        }
        error_log('[drj form inbox] action failed: ' . $e->getMessage());
        drj_inbox_json_response(500, ['ok' => false, 'error' => 'Operation failed.']);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Contact Submissions</title>
    <style>
        :root { color-scheme: dark; --bg:#0f1117; --panel:#171a22; --line:#2a3142; --text:#e8ecf4; --muted:#9aa3b4; --accent:#6ea8fe; --danger:#ff6b6b; --ok:#2ecc71; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--text); font:14px/1.4 Inter,Segoe UI,Arial,sans-serif; }
        .wrap { max-width:1320px; margin:0 auto; padding:16px; }
        .top { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; gap:8px; flex-wrap:wrap; }
        h1 { margin:0; font-size:20px; }
        .muted { color:var(--muted); }
        .row { display:grid; gap:12px; grid-template-columns: 1fr 1fr; min-height:70vh; }
        .panel { background:var(--panel); border:1px solid var(--line); border-radius:10px; overflow:hidden; }
        .panel-head { padding:10px 12px; border-bottom:1px solid var(--line); display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .panel-body { padding:10px 12px; }
        input,select,textarea,button { border-radius:8px; border:1px solid var(--line); background:#101420; color:var(--text); padding:8px 10px; }
        button { cursor:pointer; }
        button.primary { border-color:#315ca6; background:#173262; }
        button.danger { border-color:#8a2f2f; background:#4a1f1f; color:#ffd6d6; }
        button:disabled { opacity:.6; cursor:not-allowed; }
        .filters { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:0 0 10px; }
        .filters input { min-width:220px; flex:1; }
        table { width:100%; border-collapse:collapse; }
        th,td { text-align:left; padding:8px; border-bottom:1px solid var(--line); vertical-align:top; }
        th { color:var(--muted); font-weight:600; }
        tr.sel { background:#161d2f; }
        .pill { border:1px solid var(--line); border-radius:999px; padding:2px 8px; display:inline-block; font-size:12px; text-transform:uppercase; letter-spacing:.02em; }
        .pill.new{color:#7ec8ff;border-color:#2f6f92;} .pill.reviewed{color:#ffe08a;border-color:#8a7330;} .pill.resolved{color:#9bffb7;border-color:#2b7d43;} .pill.spam{color:#ff9f9f;border-color:#7e3333;}
        .kvs { display:grid; grid-template-columns:150px 1fr; gap:8px 12px; margin:0 0 12px; }
        .k { color:var(--muted); } .v { word-break:break-word; white-space:pre-wrap; }
        .detail-actions { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
        .composer { border:1px solid var(--line); border-radius:8px; padding:10px; margin-bottom:10px; background:#0f1320; }
        .composer h3 { margin:0 0 8px; font-size:14px; }
        .composer textarea { width:100%; min-height:120px; resize:vertical; margin:8px 0; }
        .events { border:1px solid var(--line); border-radius:8px; padding:8px; max-height:240px; overflow:auto; background:#0f1320; margin-bottom:10px; }
        .event { padding:7px 6px; border-bottom:1px solid #21283a; }
        .event:last-child { border-bottom:none; }
        .delete-box { border:1px solid #6c2b2b; border-radius:8px; padding:10px; margin-top:10px; background:#241415; }
        .delete-box h3 { margin:0 0 6px; font-size:14px; color:#ffb3b3; }
        .ok { color:var(--ok); } .err { color:var(--danger); }
        @media (max-width:1050px){ .row { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1>Contact Form Inbox</h1>
            <div class="muted">Reply, triage, and manage contact submissions.</div>
        </div>
        <a href="/admin/index.php" style="color:#b8d0ff;">Back to Admin Home</a>
    </div>

    <div id="flash" class="muted" style="min-height:18px; margin-bottom:8px;"></div>

    <div class="row">
        <section class="panel">
            <div class="panel-head"><strong>Inbox</strong></div>
            <div class="panel-body">
                <div class="filters">
                    <input id="qInput" type="text" placeholder="Search ticket, name, email, subject...">
                    <select id="statusFilter">
                        <option value="">All statuses</option>
                        <option value="new">new</option>
                        <option value="reviewed">reviewed</option>
                        <option value="resolved">resolved</option>
                        <option value="spam">spam</option>
                    </select>
                    <button class="primary" id="refreshBtn">Refresh</button>
                </div>
                <div style="overflow:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Ticket</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Submitted</th>
                            </tr>
                        </thead>
                        <tbody id="rows"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><strong>Submission Detail</strong></div>
            <div class="panel-body" id="detailPane"><div class="muted">Select a row to view details.</div></div>
        </section>
    </div>
</div>

<script>
(() => {
    const STATE = {
        rows: [],
        selectedId: null,
        detail: null,
        events: [],
        statuses: ['new', 'reviewed', 'resolved', 'spam']
    };

    const $ = (id) => document.getElementById(id);
    const flash = (msg, isErr = false) => {
        const el = $('flash');
        el.textContent = msg || '';
        el.className = isErr ? 'err' : 'ok';
        if (!msg) el.className = 'muted';
    };

    const safe = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({
        '&':'&amp;',
        '<':'&lt;',
        '>':'&gt;',
        '"':'&quot;',
        '\'':'&#39;'
    }[c]));

    const fmt = (d) => {
        if (!d) return 'N/A';
        const t = new Date(d);
        return Number.isNaN(t.getTime()) ? d : t.toLocaleString();
    };

    const req = async (body) => {
        const response = await fetch(location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) {
            throw new Error(data.error || 'Request failed.');
        }
        return data;
    };

    function renderList() {
        const tbody = $('rows');
        if (!STATE.rows.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="muted">No submissions found.</td></tr>';
            return;
        }

        tbody.innerHTML = STATE.rows.map((row) => `
            <tr data-id="${row.id}" class="${STATE.selectedId === Number(row.id) ? 'sel' : ''}">
                <td>${safe(row.ticket_ref)}</td>
                <td>${safe(row.full_name)}</td>
                <td>${safe(row.email)}</td>
                <td><span class="pill ${safe(String(row.status || '').toLowerCase())}">${safe(row.status)}</span></td>
                <td>${safe(fmt(row.submitted_at))}</td>
            </tr>
        `).join('');

        tbody.querySelectorAll('tr[data-id]').forEach((tr) => {
            tr.addEventListener('click', () => {
                STATE.selectedId = Number(tr.dataset.id);
                renderList();
                loadDetail();
            });
        });
    }

    function detailPairs(record) {
        const pairs = [
            ['Ticket', record.ticket_ref],
            ['Name', record.full_name],
            ['Email', record.email],
            ['Subject', record.subject],
            ['Message', record.message],
            ['Status', record.status],
            ['IP Address', record.ip_address],
            ['User Agent', record.user_agent],
            ['Submitted', fmt(record.submitted_at)],
            ['Updated', fmt(record.updated_at)]
        ];
        return `<div class="kvs">${pairs.map(([k, v]) => `<div class="k">${safe(k)}</div><div class="v">${safe(v || 'N/A')}</div>`).join('')}</div>`;
    }

    function renderEvents(events, available) {
        if (!available) return '<div class="muted">Timeline events are not available yet.</div>';
        if (!events || !events.length) return '<div class="muted">No timeline events.</div>';
        return `<div class="events">${events.map((e) => `
            <div class="event">
                <div><strong>${safe(e.event_type || 'event')}</strong> ${(e.from_status || e.to_status) ? `<span class="muted">${safe(e.from_status || 'N/A')} → ${safe(e.to_status || 'N/A')}</span>` : ''}</div>
                ${e.note ? `<div>${safe(e.note)}</div>` : ''}
                <div class="muted">${safe(e.actor || 'system')} • ${safe(fmt(e.created_at))}</div>
            </div>
        `).join('')}</div>`;
    }

    function renderDetail(eventsAvailable = true) {
        const pane = $('detailPane');
        if (!STATE.detail) {
            pane.innerHTML = '<div class="muted">Select a row to view details.</div>';
            return;
        }

        const record = STATE.detail;
        const ticketRef = String(record.ticket_ref || '');
        const replySubject = `Re: [${ticketRef}] ${String(record.subject || 'Your message to DrJessie')}`;

        pane.innerHTML = `
            <div class="detail-actions">
                <select id="statusSelect">${STATE.statuses.map((status) => `<option value="${status}" ${String(record.status).toLowerCase() === status ? 'selected' : ''}>${status}</option>`).join('')}</select>
                <input id="statusNote" type="text" placeholder="Optional status note for timeline" style="min-width:240px;flex:1;">
                <button class="primary" id="saveStatusBtn">Save Status</button>
            </div>
            ${detailPairs(record)}
            <h3 style="margin:10px 0 6px;font-size:14px;">Timeline</h3>
            ${renderEvents(STATE.events, eventsAvailable)}
            <div class="composer">
                <h3>Reply to sender</h3>
                <input id="replySubject" type="text" value="${safe(replySubject)}" style="width:100%;">
                <textarea id="replyMessage" placeholder="Write your reply message..."></textarea>
                <button class="primary" id="sendReplyBtn">Send Reply</button>
            </div>
            <div class="delete-box">
                <h3>Danger Zone</h3>
                <div class="muted" style="margin-bottom:8px;">Type <code>DELETE ${safe(ticketRef)}</code> to archive and remove this submission.</div>
                <input id="deletePhrase" type="text" placeholder="DELETE ${safe(ticketRef)}" style="width:100%;margin-bottom:8px;">
                <button class="danger" id="deleteBtn">Delete Submission</button>
            </div>
        `;

        $('saveStatusBtn').addEventListener('click', async () => {
            const status = $('statusSelect').value;
            const note = $('statusNote').value.trim();
            try {
                flash('Saving status...');
                await req({ action:'update_status', id:STATE.selectedId, status, note });
                flash('Status updated.');
                await loadList();
                await loadDetail();
            } catch (error) {
                flash(error.message, true);
            }
        });

        $('sendReplyBtn').addEventListener('click', async () => {
            const button = $('sendReplyBtn');
            const subject = $('replySubject').value.trim();
            const message = $('replyMessage').value.trim();
            if (!subject) {
                flash('Reply subject is required.', true);
                return;
            }
            if (!message) {
                flash('Reply message is required.', true);
                return;
            }

            try {
                button.disabled = true;
                button.textContent = 'Sending...';
                flash('Sending reply...');
                await req({ action:'send_reply', id:STATE.selectedId, subject, message });
                flash('Reply sent successfully.');
                $('replyMessage').value = '';
                await loadDetail();
            } catch (error) {
                flash(error.message, true);
            } finally {
                button.disabled = false;
                button.textContent = 'Send Reply';
            }
        });

        $('deleteBtn').addEventListener('click', async () => {
            const button = $('deleteBtn');
            const phrase = $('deletePhrase').value.trim().toUpperCase();
            const expected = `DELETE ${ticketRef}`;
            if (phrase !== expected) {
                flash(`Delete blocked: phrase must match exactly "${expected}"`, true);
                return;
            }
            if (!confirm(`Delete ${ticketRef}? This cannot be undone.`)) return;

            try {
                button.disabled = true;
                button.textContent = 'Deleting...';
                flash('Deleting submission...');
                const out = await req({ action:'delete_submission', id:STATE.selectedId, confirmation:phrase });
                const archiveId = out?.delete?.archive_id ? ` Archive #${out.delete.archive_id}.` : '';
                flash(`Archived and removed ${ticketRef}.${archiveId}`);
                STATE.rows = STATE.rows.filter((row) => Number(row.id) !== Number(STATE.selectedId));
                STATE.selectedId = null;
                STATE.detail = null;
                STATE.events = [];
                renderList();
                renderDetail();
            } catch (error) {
                flash(error.message, true);
            } finally {
                button.disabled = false;
                button.textContent = 'Delete Submission';
            }
        });
    }

    async function loadList() {
        try {
            flash('Loading submissions...');
            const data = await req({
                action: 'list',
                status: $('statusFilter').value || '',
                query: $('qInput').value.trim(),
                limit: 200,
                offset: 0
            });
            STATE.rows = data.rows || [];
            renderList();
            flash(`Loaded ${STATE.rows.length} submission(s).`);
        } catch (error) {
            flash(error.message, true);
        }
    }

    async function loadDetail() {
        if (!STATE.selectedId) {
            renderDetail();
            return;
        }

        try {
            flash('Loading detail...');
            const data = await req({ action:'detail', id:STATE.selectedId });
            STATE.detail = data.detail || null;
            STATE.events = data.events || [];
            renderDetail(data.events_available !== false);
            flash('Loaded submission detail.');
        } catch (error) {
            flash(error.message, true);
        }
    }

    $('refreshBtn').addEventListener('click', () => loadList());
    $('qInput').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            loadList();
        }
    });
    $('statusFilter').addEventListener('change', () => loadList());

    loadList();
})();
</script>
</body>
</html>
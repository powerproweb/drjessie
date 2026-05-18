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
    return 'ARCHIVE ' . strtoupper(trim($ticketRef));
}

function drj_inbox_legacy_delete_confirmation(string $ticketRef): string
{
    return 'DELETE ' . strtoupper(trim($ticketRef));
}

function drj_inbox_matches_delete_confirmation(string $confirmation, string $ticketRef): bool
{
    $normalized = strtoupper(trim($confirmation));
    return $normalized === drj_inbox_expected_delete_confirmation($ticketRef)
        || $normalized === drj_inbox_legacy_delete_confirmation($ticketRef);
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

function drj_inbox_archive_payload(array $archiveRow): array
{
    $payload = json_decode((string) ($archiveRow['payload_json'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

function drj_inbox_list_archived_rows(PDO $pdo, string $query, int $limit, int $offset): array
{
    $params = [];
    $where = [];

    if ($query !== '') {
        $where[] = '(ticket_ref LIKE :q OR payload_json LIKE :q OR deleted_by LIKE :q)';
        $params[':q'] = '%' . $query . '%';
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $listSql = 'SELECT id, submission_id, ticket_ref, payload_json, deleted_by, deleted_at
                FROM ' . DRJ_FORM_ARCHIVE_TABLE . "
                {$whereSql}
                ORDER BY deleted_at DESC
                LIMIT :limit OFFSET :offset";
    $countSql = 'SELECT COUNT(*) FROM ' . DRJ_FORM_ARCHIVE_TABLE . " {$whereSql}";

    $stmt = $pdo->prepare($listSql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rawRows = $stmt->fetchAll();

    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $rows = [];
    foreach ($rawRows as $rawRow) {
        $payload = drj_inbox_archive_payload($rawRow);
        $rows[] = [
            'archive_id' => (int) ($rawRow['id'] ?? 0),
            'submission_id' => (int) ($rawRow['submission_id'] ?? 0),
            'ticket_ref' => (string) ($rawRow['ticket_ref'] ?? ''),
            'full_name' => (string) ($payload['full_name'] ?? 'N/A'),
            'email' => (string) ($payload['email'] ?? 'N/A'),
            'subject' => (string) ($payload['subject'] ?? 'N/A'),
            'status' => drj_inbox_normalize_status($payload['status'] ?? null) ?? 'archived',
            'submitted_at' => (string) ($payload['submitted_at'] ?? ''),
            'updated_at' => (string) ($payload['updated_at'] ?? ''),
            'deleted_at' => (string) ($rawRow['deleted_at'] ?? ''),
            'deleted_by' => (string) ($rawRow['deleted_by'] ?? ''),
        ];
    }

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

function drj_inbox_fetch_archived_detail(PDO $pdo, int $archiveId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, submission_id, ticket_ref, payload_json, related_events_json, deleted_by, deleted_at
         FROM ' . DRJ_FORM_ARCHIVE_TABLE . '
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $archiveId]);
    $archiveRow = $stmt->fetch();
    if (!$archiveRow) {
        return null;
    }

    $record = drj_inbox_archive_payload($archiveRow);
    $record['ticket_ref'] = (string) ($record['ticket_ref'] ?? $archiveRow['ticket_ref'] ?? '');
    $record['status'] = drj_inbox_normalize_status($record['status'] ?? null) ?? 'archived';
    $record['archive_id'] = (int) ($archiveRow['id'] ?? 0);
    $record['archived_submission_id'] = (int) ($archiveRow['submission_id'] ?? 0);
    $record['archived_by'] = (string) ($archiveRow['deleted_by'] ?? '');
    $record['archived_at'] = (string) ($archiveRow['deleted_at'] ?? '');

    $events = [];
    $eventsAvailable = false;
    $eventPayload = trim((string) ($archiveRow['related_events_json'] ?? ''));
    if ($eventPayload !== '') {
        $decodedEvents = json_decode($eventPayload, true);
        if (is_array($decodedEvents)) {
            $events = $decodedEvents;
            $eventsAvailable = true;
        }
    }

    return ['record' => $record, 'events' => $events, 'events_available' => $eventsAvailable];
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
        if (!drj_inbox_is_missing_table_error($e, DRJ_FORM_EVENTS_TABLE)) {
            error_log('[drj form inbox] event log failed: ' . $e->getMessage());
        }
        return false;
    }
}

function drj_inbox_try_ensure_events_table(PDO $pdo): void
{
    try {
        drj_inbox_ensure_events_table($pdo);
    } catch (Throwable $e) {
        error_log('[drj form inbox] events table ensure failed: ' . $e->getMessage());
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
        if (!drj_inbox_matches_delete_confirmation($confirmation, $ticketRef)) {
            throw new InvalidArgumentException('Archive confirmation phrase does not match.');
        }

        $eventRows = [];
        $eventsDeleted = false;
        try {
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

function drj_inbox_restore_submission(PDO $pdo, int $archiveId, string $actor): array
{
    $pdo->beginTransaction();
    try {
        $selectArchive = $pdo->prepare(
            'SELECT id, submission_id, ticket_ref, payload_json, related_events_json, deleted_by, deleted_at
             FROM ' . DRJ_FORM_ARCHIVE_TABLE . '
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $selectArchive->execute([':id' => $archiveId]);
        $archiveRow = $selectArchive->fetch();
        if (!$archiveRow) {
            throw new RuntimeException('Archived submission not found.');
        }

        $payload = drj_inbox_archive_payload($archiveRow);
        $ticketRef = trim((string) ($payload['ticket_ref'] ?? $archiveRow['ticket_ref'] ?? ''));
        if ($ticketRef === '') {
            throw new RuntimeException('Archived submission is missing ticket reference.');
        }

        $check = $pdo->prepare('SELECT id FROM dj_contact_submissions WHERE ticket_ref = :ticket_ref LIMIT 1');
        $check->execute([':ticket_ref' => $ticketRef]);
        $existingId = $check->fetchColumn();
        if ($existingId !== false) {
            throw new InvalidArgumentException('This ticket is already present in inbox.');
        }

        $status = drj_inbox_normalize_status($payload['status'] ?? null) ?? 'new';
        $submittedAt = trim((string) ($payload['submitted_at'] ?? ''));
        $updatedAt = trim((string) ($payload['updated_at'] ?? ''));
        if ($submittedAt === '') {
            $submittedAt = date('Y-m-d H:i:s');
        }
        if ($updatedAt === '') {
            $updatedAt = $submittedAt;
        }

        $insert = $pdo->prepare(
            'INSERT INTO dj_contact_submissions (
                ticket_ref, full_name, email, subject, message,
                consent_contact, ip_address, user_agent, status, submitted_at, updated_at
            ) VALUES (
                :ticket_ref, :full_name, :email, :subject, :message,
                :consent_contact, :ip_address, :user_agent, :status, :submitted_at, :updated_at
            )'
        );
        $insert->execute([
            ':ticket_ref' => $ticketRef,
            ':full_name' => (string) ($payload['full_name'] ?? ''),
            ':email' => (string) ($payload['email'] ?? ''),
            ':subject' => (string) ($payload['subject'] ?? ''),
            ':message' => (string) ($payload['message'] ?? ''),
            ':consent_contact' => (int) ($payload['consent_contact'] ?? 1),
            ':ip_address' => isset($payload['ip_address']) ? (string) $payload['ip_address'] : null,
            ':user_agent' => isset($payload['user_agent']) ? (string) $payload['user_agent'] : null,
            ':status' => $status,
            ':submitted_at' => $submittedAt,
            ':updated_at' => $updatedAt,
        ]);
        $restoredId = (int) $pdo->lastInsertId();

        $restoredEvents = 0;
        $eventPayload = trim((string) ($archiveRow['related_events_json'] ?? ''));
        if ($eventPayload !== '') {
            drj_inbox_try_ensure_events_table($pdo);
            $eventRows = json_decode($eventPayload, true);
            if (is_array($eventRows)) {
                $insertEvent = $pdo->prepare(
                    'INSERT INTO ' . DRJ_FORM_EVENTS_TABLE . ' (
                        ticket_ref, event_type, from_status, to_status, note, actor, created_at
                    ) VALUES (
                        :ticket_ref, :event_type, :from_status, :to_status, :note, :actor, :created_at
                    )'
                );
                foreach ($eventRows as $eventRow) {
                    if (!is_array($eventRow)) {
                        continue;
                    }
                    $eventType = strtolower(trim((string) ($eventRow['event_type'] ?? 'note')));
                    if (!in_array($eventType, ['status_change', 'note', 'reply'], true)) {
                        $eventType = 'note';
                    }
                    $createdAt = trim((string) ($eventRow['created_at'] ?? ''));
                    if ($createdAt === '') {
                        $createdAt = date('Y-m-d H:i:s');
                    }
                    $insertEvent->execute([
                        ':ticket_ref' => $ticketRef,
                        ':event_type' => $eventType,
                        ':from_status' => isset($eventRow['from_status']) ? (string) $eventRow['from_status'] : null,
                        ':to_status' => isset($eventRow['to_status']) ? (string) $eventRow['to_status'] : null,
                        ':note' => isset($eventRow['note']) ? (string) $eventRow['note'] : null,
                        ':actor' => trim((string) ($eventRow['actor'] ?? 'system')) ?: 'system',
                        ':created_at' => $createdAt,
                    ]);
                    $restoredEvents++;
                }
            }
        }

        drj_inbox_log_event(
            $pdo,
            $ticketRef,
            'note',
            $status,
            $status,
            'Restored from archive #' . $archiveId,
            $actor
        );

        $deleteArchive = $pdo->prepare('DELETE FROM ' . DRJ_FORM_ARCHIVE_TABLE . ' WHERE id = :id');
        $deleteArchive->execute([':id' => $archiveId]);

        $pdo->commit();
        return [
            'archive_id' => $archiveId,
            'restored_id' => $restoredId,
            'ticket_ref' => $ticketRef,
            'restored_events' => $restoredEvents,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
function drj_inbox_parse_ids(mixed $rawIds, int $maxItems = 100): array
{
    if (!is_array($rawIds)) {
        return [];
    }

    $ids = [];
    foreach ($rawIds as $rawId) {
        $id = (int) $rawId;
        if ($id > 0) {
            $ids[$id] = $id;
        }
        if (count($ids) >= $maxItems) {
            break;
        }
    }

    return array_values($ids);
}

function drj_inbox_ticket_ref_by_id(PDO $pdo, int $id): ?string
{
    $stmt = $pdo->prepare('SELECT ticket_ref FROM dj_contact_submissions WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $value = $stmt->fetchColumn();
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    return trim($value);
}

function drj_inbox_bulk_update_status(PDO $pdo, array $ids, string $status, string $note, string $actor): array
{
    $updated = [];
    $failed = [];

    foreach ($ids as $id) {
        try {
            $updated[] = drj_inbox_update_status($pdo, (int) $id, $status, $note, $actor);
        } catch (Throwable $e) {
            $failed[] = [
                'id' => (int) $id,
                'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Update failed.',
            ];
        }
    }

    return [
        'updated' => $updated,
        'failed' => $failed,
        'updated_count' => count($updated),
        'failed_count' => count($failed),
    ];
}

function drj_inbox_bulk_delete(PDO $pdo, array $ids, string $actor): array
{
    $deleted = [];
    $failed = [];

    foreach ($ids as $id) {
        $id = (int) $id;
        try {
            $ticketRef = drj_inbox_ticket_ref_by_id($pdo, $id);
            if ($ticketRef === null) {
                throw new RuntimeException('Submission not found.');
            }

            $deletedRow = drj_inbox_delete_submission($pdo, $id, drj_inbox_expected_delete_confirmation($ticketRef), $actor);
            $deleted[] = [
                'id' => $id,
                'ticket_ref' => (string) ($deletedRow['ticket_ref'] ?? $ticketRef),
                'archive_id' => (int) ($deletedRow['archive_id'] ?? 0),
            ];
        } catch (Throwable $e) {
            $failed[] = [
                'id' => $id,
                'error' => $e instanceof RuntimeException || $e instanceof InvalidArgumentException ? $e->getMessage() : 'Archive failed.',
            ];
        }
    }

    return [
        'deleted' => $deleted,
        'failed' => $failed,
        'deleted_count' => count($deleted),
        'failed_count' => count($failed),
    ];
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
        if ($action === 'list_archived') {
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

            drj_inbox_ensure_archive_table($pdo);
            [$rows, $total] = drj_inbox_list_archived_rows($pdo, $query, $limit, $offset);
            drj_inbox_json_response(200, [
                'ok' => true,
                'rows' => $rows,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'mode' => 'archived',
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
        if ($action === 'detail_archived') {
            $archiveId = (int) ($input['archive_id'] ?? ($input['id'] ?? 0));
            if ($archiveId < 1) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Invalid archive id.']);
            }

            drj_inbox_ensure_archive_table($pdo);
            $detail = drj_inbox_fetch_archived_detail($pdo, $archiveId);
            if ($detail === null) {
                drj_inbox_json_response(404, ['ok' => false, 'error' => 'Archived submission not found.']);
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
            drj_inbox_try_ensure_events_table($pdo);

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
            drj_inbox_try_ensure_events_table($pdo);

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

        if ($action === 'delete_submission' || $action === 'archive_submission') {
            $id = (int) ($input['id'] ?? 0);
            $confirmation = strtoupper(trim((string) ($input['confirmation'] ?? '')));
            if ($id < 1) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Invalid submission id.']);
            }
            if ($confirmation === '' || strlen($confirmation) > 120) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Archive confirmation phrase is required.']);
            }
            drj_inbox_ensure_archive_table($pdo);

            $deleted = drj_inbox_delete_submission($pdo, $id, $confirmation, $adminActor);
            drj_inbox_json_response(200, ['ok' => true, 'archive' => $deleted, 'delete' => $deleted]);
        }
        if ($action === 'restore_submission') {
            $archiveId = (int) ($input['archive_id'] ?? ($input['id'] ?? 0));
            if ($archiveId < 1) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Invalid archive id.']);
            }

            drj_inbox_ensure_archive_table($pdo);
            drj_inbox_try_ensure_events_table($pdo);
            $restored = drj_inbox_restore_submission($pdo, $archiveId, $adminActor);
            drj_inbox_json_response(200, ['ok' => true, 'restore' => $restored]);
        }

        if ($action === 'bulk_update_status') {
            $ids = drj_inbox_parse_ids($input['ids'] ?? null, 200);
            $status = drj_inbox_normalize_status($input['status'] ?? '');
            $note = trim((string) ($input['note'] ?? ''));

            if (empty($ids)) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Select at least one submission.']);
            }
            if ($status === null) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Invalid status value.']);
            }
            if (strlen($note) > 1500) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Note is too long.']);
            }

            drj_inbox_try_ensure_events_table($pdo);
            $bulk = drj_inbox_bulk_update_status($pdo, $ids, $status, $note, $adminActor);
            drj_inbox_json_response(200, ['ok' => true, 'bulk' => $bulk]);
        }

        if ($action === 'bulk_delete' || $action === 'bulk_archive') {
            $ids = drj_inbox_parse_ids($input['ids'] ?? null, 200);
            $confirmation = strtoupper(trim((string) ($input['confirmation'] ?? '')));

            if (empty($ids)) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Select at least one submission.']);
            }
            if (!in_array($confirmation, ['ARCHIVE SELECTED', 'DELETE SELECTED'], true)) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Archive confirmation phrase is invalid.']);
            }

            drj_inbox_ensure_archive_table($pdo);
            $bulk = drj_inbox_bulk_delete($pdo, $ids, $adminActor);
            drj_inbox_json_response(200, ['ok' => true, 'bulk' => $bulk]);
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
        .tabs { display:flex; gap:6px; margin-left:auto; }
        .tab { border-color:#2d3650; background:#11182a; color:#b9c6e6; }
        .tab.active { border-color:#315ca6; background:#173262; color:#e8f0ff; }
        .panel-body { padding:10px 12px; }
        input,select,textarea,button { border-radius:8px; border:1px solid var(--line); background:#101420; color:var(--text); padding:8px 10px; }
        button { cursor:pointer; }
        button.primary { border-color:#315ca6; background:#173262; }
        button.danger { border-color:#8a2f2f; background:#4a1f1f; color:#ffd6d6; }
        button:disabled { opacity:.6; cursor:not-allowed; }
        .filters { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:0 0 10px; }
        .filters input { min-width:220px; flex:1; }
        .bulk-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:0 0 10px; }
        .bulk-actions .spacer { flex:1; min-width:20px; }
        .pager { display:flex; gap:8px; align-items:center; justify-content:flex-end; margin-top:10px; }
        .pager .muted { margin-right:auto; }
        table { width:100%; border-collapse:collapse; }
        th,td { text-align:left; padding:8px; border-bottom:1px solid var(--line); vertical-align:top; }
        th { color:var(--muted); font-weight:600; }
        tr.sel { background:#161d2f; }
        .row-check { width:16px; height:16px; accent-color:#315ca6; cursor:pointer; }
        .pill { border:1px solid var(--line); border-radius:999px; padding:2px 8px; display:inline-block; font-size:12px; text-transform:uppercase; letter-spacing:.02em; }
        .pill.new{color:#7ec8ff;border-color:#2f6f92;} .pill.reviewed{color:#ffe08a;border-color:#8a7330;} .pill.resolved{color:#9bffb7;border-color:#2b7d43;} .pill.spam{color:#ff9f9f;border-color:#7e3333;} .pill.archived{color:#c6c9d1;border-color:#5a6373;}
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
            <div class="panel-head">
                <strong id="listTitle">Inbox</strong>
                <div class="tabs">
                    <button type="button" id="tabInbox" class="tab active">Inbox</button>
                    <button type="button" id="tabArchived" class="tab">Archived</button>
                </div>
            </div>
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
                    <select id="pageSizeSelect">
                        <option value="25">25 / page</option>
                        <option value="50">50 / page</option>
                        <option value="100">100 / page</option>
                    </select>
                    <button class="primary" id="refreshBtn">Refresh</button>
                </div>
                <div class="bulk-actions">
                    <span id="bulkSelectedCount" class="muted">0 selected</span>
                    <div class="spacer"></div>
                    <select id="bulkStatusSelect">
                        <option value="">Bulk status...</option>
                        <option value="new">new</option>
                        <option value="reviewed">reviewed</option>
                        <option value="resolved">resolved</option>
                        <option value="spam">spam</option>
                    </select>
                    <input id="bulkStatusNote" type="text" placeholder="Optional note" style="min-width:180px;">
                    <button class="primary" id="bulkApplyBtn" disabled>Apply to Selected</button>
                    <button class="danger" id="bulkDeleteBtn" disabled>Archive Selected</button>
                </div>
                <div style="overflow:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th id="selectColHead"><input id="selectAllRows" class="row-check" type="checkbox" aria-label="Select all rows"></th>
                                <th>Ticket</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th id="listMetaHeading">Submitted</th>
                            </tr>
                        </thead>
                        <tbody id="rows"></tbody>
                    </table>
                </div>
                <div class="pager">
                    <span id="pageInfo" class="muted">Page 1 of 1</span>
                    <button id="prevPageBtn">Previous</button>
                    <button id="nextPageBtn">Next</button>
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
        mode: 'inbox',
        rows: [],
        total: 0,
        page: 1,
        pageSize: 25,
        selectedIds: new Set(),
        selectedKey: null,
        detail: null,
        events: [],
        statuses: ['new', 'reviewed', 'resolved', 'spam']
    };

    const REPLY_TEMPLATES = {
        acknowledgement: (record) => {
            const name = String(record.full_name || 'there').trim() || 'there';
            return `Hi ${name},\n\nThank you for reaching out. We received your message and will review it shortly.\n\nBest,\nDrJessie Team`;
        },
        follow_up: (record) => {
            const name = String(record.full_name || 'there').trim() || 'there';
            const ticket = String(record.ticket_ref || '').trim();
            return `Hi ${name},\n\nThanks for your message${ticket ? ` (${ticket})` : ''}. To help you faster, please share any additional details, relevant dates, or context we should review.\n\nBest,\nDrJessie Team`;
        },
        resolved: (record) => {
            const name = String(record.full_name || 'there').trim() || 'there';
            return `Hi ${name},\n\nThank you again for contacting us. This thread is now marked as resolved, but you can reply any time if you need anything else.\n\nBest,\nDrJessie Team`;
        }
    };

    const $ = (id) => document.getElementById(id);
    const flash = (msg, isErr = false) => {
        const el = $('flash');
        el.textContent = msg || '';
        el.className = isErr ? 'err' : 'ok';
        if (!msg) {
            el.className = 'muted';
        }
    };

    const safe = (v) => {
        const escMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
        return String(v ?? '').replace(/[&<>"']/g, (c) => escMap[c]);
    };

    const fmt = (d) => {
        if (!d) {
            return 'N/A';
        }
        const t = new Date(d);
        return Number.isNaN(t.getTime()) ? d : t.toLocaleString();
    };

    const isInbox = () => STATE.mode === 'inbox';
    const pageCount = (total = STATE.total) => Math.max(1, Math.ceil((Number(total) || 0) / STATE.pageSize));
    const keyForRow = (row) => isInbox() ? Number(row.id) : Number(row.archive_id);
    const metaDateForRow = (row) => isInbox() ? row.submitted_at : row.deleted_at;
    const currentPageKeys = () => STATE.rows.map((row) => keyForRow(row)).filter((n) => Number.isInteger(n) && n > 0);

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

    function setMode(mode) {
        STATE.mode = mode === 'archived' ? 'archived' : 'inbox';
        STATE.page = 1;
        STATE.selectedIds.clear();
        STATE.selectedKey = null;
        STATE.detail = null;
        STATE.events = [];

        const inbox = isInbox();
        $('listTitle').textContent = inbox ? 'Inbox' : 'Archived';
        $('tabInbox').classList.toggle('active', inbox);
        $('tabArchived').classList.toggle('active', !inbox);
        $('statusFilter').style.display = inbox ? '' : 'none';
        document.querySelector('.bulk-actions').style.display = inbox ? 'flex' : 'none';
        $('selectAllRows').style.display = inbox ? '' : 'none';
        $('listMetaHeading').textContent = inbox ? 'Submitted' : 'Archived';
        $('qInput').placeholder = inbox
            ? 'Search ticket, name, email, subject...'
            : 'Search archived ticket, name, email, subject...';
        renderList();
        renderDetail();
    }

    function renderBulkState() {
        if (!isInbox()) {
            $('bulkSelectedCount').textContent = '0 selected';
            $('bulkApplyBtn').disabled = true;
            $('bulkDeleteBtn').disabled = true;
            $('selectAllRows').checked = false;
            $('selectAllRows').indeterminate = false;
            return;
        }
        const selectedCount = STATE.selectedIds.size;
        $('bulkSelectedCount').textContent = `${selectedCount} selected`;
        $('bulkApplyBtn').disabled = selectedCount === 0;
        $('bulkDeleteBtn').disabled = selectedCount === 0;

        const keys = currentPageKeys();
        const selectedOnPage = keys.filter((id) => STATE.selectedIds.has(id)).length;
        $('selectAllRows').checked = keys.length > 0 && selectedOnPage === keys.length;
        $('selectAllRows').indeterminate = selectedOnPage > 0 && selectedOnPage < keys.length;
    }

    function renderPagination() {
        const pages = pageCount();
        if (STATE.page > pages) {
            STATE.page = pages;
        }
        $('pageInfo').textContent = `Page ${STATE.page} of ${pages} (${STATE.total} total)`;
        $('prevPageBtn').disabled = STATE.page <= 1;
        $('nextPageBtn').disabled = STATE.page >= pages;
    }

    function renderList() {
        const tbody = $('rows');
        if (!STATE.rows.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="muted">No submissions found.</td></tr>';
            renderBulkState();
            renderPagination();
            return;
        }

        const inbox = isInbox();
        tbody.innerHTML = STATE.rows.map((row) => {
            const key = keyForRow(row);
            const statusClass = String(row.status || '').toLowerCase();
            return `
                <tr data-id="${key}" class="${STATE.selectedKey === key ? 'sel' : ''}">
                    <td>${inbox ? `<input type="checkbox" class="row-check js-row-check" data-id="${key}" ${STATE.selectedIds.has(key) ? 'checked' : ''} aria-label="Select ${safe(row.ticket_ref)}">` : ''}</td>
                    <td>${safe(row.ticket_ref)}</td>
                    <td>${safe(row.full_name)}</td>
                    <td>${safe(row.email)}</td>
                    <td><span class="pill ${safe(statusClass)}">${safe(row.status)}</span></td>
                    <td>${safe(fmt(metaDateForRow(row)))}</td>
                </tr>
            `;
        }).join('');

        tbody.querySelectorAll('tr[data-id]').forEach((tr) => {
            tr.addEventListener('click', (event) => {
                if (event.target && event.target.closest('.js-row-check')) {
                    return;
                }
                STATE.selectedKey = Number(tr.dataset.id);
                renderList();
                loadDetail();
            });
        });

        if (inbox) {
            tbody.querySelectorAll('.js-row-check').forEach((input) => {
                input.addEventListener('click', (event) => event.stopPropagation());
                input.addEventListener('change', () => {
                    const id = Number(input.dataset.id);
                    if (input.checked) {
                        STATE.selectedIds.add(id);
                    } else {
                        STATE.selectedIds.delete(id);
                    }
                    renderBulkState();
                });
            });
        }

        renderBulkState();
        renderPagination();
    }

    function detailPairs(record, includeArchive = false) {
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
        if (includeArchive) {
            pairs.push(['Archived By', record.archived_by || 'N/A']);
            pairs.push(['Archived At', fmt(record.archived_at)]);
            pairs.push(['Archive ID', record.archive_id]);
        }
        return `<div class="kvs">${pairs.map(([k, v]) => `<div class="k">${safe(k)}</div><div class="v">${safe(v || 'N/A')}</div>`).join('')}</div>`;
    }

    function renderEvents(events, available) {
        if (!available) {
            return '<div class="muted">Timeline events are not available yet.</div>';
        }
        if (!events || !events.length) {
            return '<div class="muted">No timeline events.</div>';
        }
        return `<div class="events">${events.map((e) => `
            <div class="event">
                <div><strong>${safe(e.event_type || 'event')}</strong> ${(e.from_status || e.to_status) ? `<span class="muted">${safe(e.from_status || 'N/A')} → ${safe(e.to_status || 'N/A')}</span>` : ''}</div>
                ${e.note ? `<div>${safe(e.note)}</div>` : ''}
                <div class="muted">${safe(e.actor || 'system')} • ${safe(fmt(e.created_at))}</div>
            </div>
        `).join('')}</div>`;
    }

    function renderInboxDetail(record, eventsAvailable) {
        const pane = $('detailPane');
        const ticketRef = String(record.ticket_ref || '').trim();
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
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                    <select id="replyTemplate" style="min-width:220px;">
                        <option value="">Quick template...</option>
                        <option value="acknowledgement">Acknowledgement</option>
                        <option value="follow_up">Need more context</option>
                        <option value="resolved">Resolved follow-up</option>
                    </select>
                    <button id="insertTemplateBtn" type="button">Insert Template</button>
                </div>
                <input id="replySubject" type="text" value="${safe(replySubject)}" style="width:100%;">
                <textarea id="replyMessage" placeholder="Write your reply message..."></textarea>
                <button class="primary" id="sendReplyBtn">Send Reply</button>
            </div>
            <div class="delete-box">
                <h3>Archive</h3>
                <div class="muted" style="margin-bottom:8px;">Type <code>ARCHIVE ${safe(ticketRef)}</code> to move this message to Archived.</div>
                <input id="deletePhrase" type="text" placeholder="ARCHIVE ${safe(ticketRef)}" style="width:100%;margin-bottom:8px;">
                <button class="danger" id="deleteBtn">Archive Submission</button>
            </div>
        `;

        $('insertTemplateBtn').addEventListener('click', () => {
            const key = $('replyTemplate').value;
            if (!key || !REPLY_TEMPLATES[key]) {
                flash('Choose a template first.', true);
                return;
            }
            $('replyMessage').value = REPLY_TEMPLATES[key](record);
            $('replyMessage').focus();
            flash('Template inserted.');
        });

        $('saveStatusBtn').addEventListener('click', async () => {
            const button = $('saveStatusBtn');
            const status = $('statusSelect').value;
            const note = $('statusNote').value.trim();
            try {
                button.disabled = true;
                button.textContent = 'Saving...';
                flash('Saving status...');
                await req({ action: 'update_status', id: STATE.selectedKey, status, note });
                await loadList({ preserveSelection: true, silent: true });
                await loadDetail();
                flash('Status updated.');
            } catch (error) {
                flash(error.message, true);
            } finally {
                button.disabled = false;
                button.textContent = 'Save Status';
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
                await req({ action: 'send_reply', id: STATE.selectedKey, subject, message });
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
            const expected = `ARCHIVE ${ticketRef}`;
            if (phrase !== expected) {
                flash(`Archive blocked: phrase must match exactly "${expected}"`, true);
                return;
            }
            if (!confirm(`Archive ${ticketRef}? You can restore it from Archived.`)) {
                return;
            }
            try {
                button.disabled = true;
                button.textContent = 'Archiving...';
                flash('Archiving submission...');
                const out = await req({ action: 'archive_submission', id: STATE.selectedKey, confirmation: phrase });
                const archiveId = out && out.archive && out.archive.archive_id ? ` Archive #${out.archive.archive_id}.` : '';
                STATE.selectedIds.delete(Number(STATE.selectedKey));
                STATE.selectedKey = null;
                STATE.detail = null;
                STATE.events = [];
                renderList();
                renderDetail();
                await loadList({ preserveSelection: true, silent: true });
                flash(`Archived ${ticketRef}.${archiveId}`);
            } catch (error) {
                flash(error.message, true);
            } finally {
                button.disabled = false;
                button.textContent = 'Archive Submission';
            }
        });
    }

    function renderArchivedDetail(record, eventsAvailable) {
        const pane = $('detailPane');
        const ticketRef = String(record.ticket_ref || '').trim();
        pane.innerHTML = `
            ${detailPairs(record, true)}
            <h3 style="margin:10px 0 6px;font-size:14px;">Timeline</h3>
            ${renderEvents(STATE.events, eventsAvailable)}
            <div class="delete-box" style="border-color:#2f6b3f;background:#152118;">
                <h3 style="color:#c8f7d3;">Restore</h3>
                <div class="muted" style="margin-bottom:8px;">Restore this archived message back to Inbox.</div>
                <button class="primary" id="restoreBtn">Restore to Inbox</button>
            </div>
        `;

        $('restoreBtn').addEventListener('click', async () => {
            const button = $('restoreBtn');
            if (!confirm(`Restore ${ticketRef} back to Inbox?`)) {
                return;
            }
            try {
                button.disabled = true;
                button.textContent = 'Restoring...';
                flash('Restoring archived submission...');
                await req({ action: 'restore_submission', archive_id: STATE.selectedKey });
                setMode('inbox');
                await loadList({ preserveSelection: false, silent: true });
                flash(`Restored ${ticketRef} to inbox.`);
            } catch (error) {
                flash(error.message, true);
            } finally {
                button.disabled = false;
                button.textContent = 'Restore to Inbox';
            }
        });
    }

    function renderDetail(eventsAvailable = true) {
        const pane = $('detailPane');
        if (!STATE.detail) {
            pane.innerHTML = '<div class="muted">Select a row to view details.</div>';
            return;
        }
        if (isInbox()) {
            renderInboxDetail(STATE.detail, eventsAvailable);
            return;
        }
        renderArchivedDetail(STATE.detail, eventsAvailable);
    }

    async function loadList(options = {}) {
        const preserveSelection = options.preserveSelection === true;
        const silent = options.silent === true;
        try {
            if (!silent) {
                flash(isInbox() ? 'Loading inbox submissions...' : 'Loading archived submissions...');
            }

            const payload = {
                action: isInbox() ? 'list' : 'list_archived',
                query: $('qInput').value.trim(),
                limit: STATE.pageSize,
                offset: (STATE.page - 1) * STATE.pageSize
            };
            if (isInbox()) {
                payload.status = $('statusFilter').value || '';
            }

            let data = await req(payload);
            if ((!Array.isArray(data.rows) || data.rows.length === 0) && STATE.page > 1 && Number(data.total || 0) > 0) {
                STATE.page = pageCount(Number(data.total || 0));
                payload.offset = (STATE.page - 1) * STATE.pageSize;
                data = await req(payload);
            }

            STATE.rows = Array.isArray(data.rows) ? data.rows : [];
            STATE.total = Number(data.total || 0);

            if (!preserveSelection || !isInbox()) {
                STATE.selectedIds.clear();
            } else {
                const pageSet = new Set(currentPageKeys());
                STATE.selectedIds = new Set([...STATE.selectedIds].filter((id) => pageSet.has(id)));
            }

            if (STATE.selectedKey !== null && !STATE.rows.some((row) => keyForRow(row) === Number(STATE.selectedKey))) {
                STATE.selectedKey = null;
                STATE.detail = null;
                STATE.events = [];
            }

            renderList();
            renderDetail();
            if (!silent) {
                flash(`Loaded ${STATE.rows.length} ${isInbox() ? 'inbox' : 'archived'} submission(s).`);
            }
        } catch (error) {
            flash(error.message, true);
        }
    }

    async function runBulkStatusUpdate() {
        if (!isInbox()) {
            return;
        }
        const ids = [...STATE.selectedIds];
        const status = $('bulkStatusSelect').value;
        const note = $('bulkStatusNote').value.trim();
        if (!ids.length) {
            flash('Select at least one submission.', true);
            return;
        }
        if (!status) {
            flash('Choose a bulk status first.', true);
            return;
        }

        const button = $('bulkApplyBtn');
        const originalText = button.textContent;
        try {
            button.disabled = true;
            button.textContent = 'Applying...';
            flash('Applying bulk status update...');
            const out = await req({ action: 'bulk_update_status', ids, status, note });
            const bulk = out && out.bulk ? out.bulk : {};
            const updated = Number(bulk.updated_count || 0);
            const failed = Number(bulk.failed_count || 0);
            STATE.selectedIds.clear();
            $('bulkStatusNote').value = '';
            await loadList({ preserveSelection: false, silent: true });
            if (STATE.selectedKey !== null && ids.includes(Number(STATE.selectedKey))) {
                await loadDetail();
            }
            flash(failed > 0 ? `Updated ${updated} submissions, ${failed} failed.` : `Updated ${updated} submissions.`, failed > 0);
        } catch (error) {
            flash(error.message, true);
        } finally {
            button.textContent = originalText;
            renderBulkState();
        }
    }

    async function runBulkArchive() {
        if (!isInbox()) {
            return;
        }
        const ids = [...STATE.selectedIds];
        if (!ids.length) {
            flash('Select at least one submission.', true);
            return;
        }
        const typed = (prompt('Type ARCHIVE SELECTED to confirm bulk archive.', '') || '').trim().toUpperCase();
        if (typed !== 'ARCHIVE SELECTED') {
            flash('Bulk archive canceled: confirmation phrase mismatch.', true);
            return;
        }
        if (!confirm(`Archive ${ids.length} selected submission(s)? You can restore them from Archived.`)) {
            return;
        }

        const button = $('bulkDeleteBtn');
        const originalText = button.textContent;
        try {
            button.disabled = true;
            button.textContent = 'Archiving...';
            flash('Archiving selected submissions...');
            const out = await req({ action: 'bulk_archive', ids, confirmation: 'ARCHIVE SELECTED' });
            const bulk = out && out.bulk ? out.bulk : {};
            const archivedCount = Number(bulk.deleted_count || 0);
            const failedCount = Number(bulk.failed_count || 0);
            const archivedRows = Array.isArray(bulk.deleted) ? bulk.deleted : [];
            const archivedIds = archivedRows.map((item) => Number(item.id)).filter((n) => Number.isInteger(n) && n > 0);
            archivedIds.forEach((id) => STATE.selectedIds.delete(id));
            if (STATE.selectedKey !== null && archivedIds.includes(Number(STATE.selectedKey))) {
                STATE.selectedKey = null;
                STATE.detail = null;
                STATE.events = [];
            }
            await loadList({ preserveSelection: true, silent: true });
            renderDetail();
            flash(failedCount > 0 ? `Archived ${archivedCount} submissions, ${failedCount} failed.` : `Archived ${archivedCount} submissions.`, failedCount > 0);
        } catch (error) {
            flash(error.message, true);
        } finally {
            button.textContent = originalText;
            renderBulkState();
        }
    }

    async function loadDetail() {
        if (!STATE.selectedKey) {
            renderDetail();
            return;
        }
        try {
            flash('Loading detail...');
            const data = isInbox()
                ? await req({ action: 'detail', id: STATE.selectedKey })
                : await req({ action: 'detail_archived', archive_id: STATE.selectedKey });
            STATE.detail = data.detail || null;
            STATE.events = data.events || [];
            renderDetail(data.events_available !== false);
            flash(isInbox() ? 'Loaded inbox detail.' : 'Loaded archived detail.');
        } catch (error) {
            flash(error.message, true);
        }
    }

    $('refreshBtn').addEventListener('click', () => loadList());
    $('qInput').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            STATE.page = 1;
            loadList();
        }
    });
    $('statusFilter').addEventListener('change', () => {
        if (!isInbox()) {
            return;
        }
        STATE.page = 1;
        loadList();
    });
    $('tabInbox').addEventListener('click', async () => {
        if (isInbox()) {
            return;
        }
        setMode('inbox');
        await loadList({ preserveSelection: false, silent: true });
    });
    $('tabArchived').addEventListener('click', async () => {
        if (!isInbox()) {
            return;
        }
        setMode('archived');
        await loadList({ preserveSelection: false, silent: true });
    });
    $('pageSizeSelect').value = String(STATE.pageSize);
    $('pageSizeSelect').addEventListener('change', () => {
        const nextSize = Number.parseInt($('pageSizeSelect').value, 10);
        STATE.pageSize = Number.isInteger(nextSize) && nextSize > 0 ? nextSize : 25;
        STATE.page = 1;
        loadList();
    });
    $('prevPageBtn').addEventListener('click', () => {
        if (STATE.page > 1) {
            STATE.page -= 1;
            loadList();
        }
    });
    $('nextPageBtn').addEventListener('click', () => {
        if (STATE.page < pageCount()) {
            STATE.page += 1;
            loadList();
        }
    });
    $('selectAllRows').addEventListener('change', () => {
        if (!isInbox()) {
            return;
        }
        const ids = currentPageKeys();
        if ($('selectAllRows').checked) {
            ids.forEach((id) => STATE.selectedIds.add(id));
        } else {
            ids.forEach((id) => STATE.selectedIds.delete(id));
        }
        renderList();
    });
    $('bulkApplyBtn').addEventListener('click', () => runBulkStatusUpdate());
    $('bulkDeleteBtn').addEventListener('click', () => runBulkArchive());

    setMode('inbox');
    renderBulkState();
    renderPagination();
    loadList();
})();
</script>
</body>
</html>
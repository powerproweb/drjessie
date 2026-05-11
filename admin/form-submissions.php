<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/_config.php';
$adminActor = drj_require_admin_auth('DrJessie Form Submissions');

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

function drj_inbox_is_missing_table_error(Throwable $e): bool
{
    $message = strtolower($e->getMessage());
    if (strpos($message, 'dj_contact_submissions') !== false && strpos($message, "doesn't exist") !== false) {
        return true;
    }

    if ($e instanceof PDOException && isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1146) {
        return isset($e->errorInfo[2]) && stripos((string) $e->errorInfo[2], 'dj_contact_submissions') !== false;
    }

    return false;
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
    return $row ?: null;
}

function drj_inbox_update_status(PDO $pdo, int $id, string $newStatus): array
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

        $pdo->commit();
        return [
            'ticket_ref' => (string) $row['ticket_ref'],
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
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

            drj_inbox_json_response(200, ['ok' => true, 'detail' => $detail]);
        }

        if ($action === 'update_status') {
            $id = (int) ($input['id'] ?? 0);
            $status = drj_inbox_normalize_status($input['status'] ?? '');
            if ($id < 1) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Invalid submission id.']);
            }
            if ($status === null) {
                drj_inbox_json_response(400, ['ok' => false, 'error' => 'Invalid status value.']);
            }

            $update = drj_inbox_update_status($pdo, $id, $status);
            $fresh = drj_inbox_fetch_detail($pdo, $id);
            drj_inbox_json_response(200, ['ok' => true, 'update' => $update, 'detail' => $fresh, 'actor' => $adminActor]);
        }

        drj_inbox_json_response(400, ['ok' => false, 'error' => 'Unknown action.']);
    } catch (RuntimeException $e) {
        drj_inbox_json_response(404, ['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        if (drj_inbox_is_missing_table_error($e)) {
            drj_inbox_json_response(500, ['ok' => false, 'error' => 'Contact inbox table is missing. Run api/CONTACT_FORM_DB_SCHEMA.sql first.']);
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
        .detail-actions { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
        .ok { color:var(--ok); } .err { color:var(--danger); }
        @media (max-width:1050px){ .row { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1>Contact Form Inbox</h1>
            <div class="muted">Review, search, and update status for incoming contact messages.</div>
        </div>
        <a href="/admin/index.php" style="color:#b8d0ff;">Back to Admin Home</a>
    </div>

    <div id="flash" class="muted" style="min-height:18px; margin-bottom:8px;"></div>

    <div class="row">
        <section class="panel">
            <div class="panel-head"><strong>Inbox</strong></div>
            <div class="panel-body">
                <div class="filters">
                    <input id="qInput" type="text" placeholder="Search ticket, name, email, subject..." />
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
        statuses: ["new", "reviewed", "resolved", "spam"]
    };

    const $ = (id) => document.getElementById(id);
    const flash = (msg, isErr = false) => {
        const el = $("flash");
        el.textContent = msg || "";
        el.className = isErr ? "err" : "ok";
        if (!msg) el.className = "muted";
    };
    const safe = (v) => String(v ?? "").replace(/[&<>"']/g, (c) => ({
        "&":"&amp;",
        "<":"&lt;",
        ">":"&gt;",
        "\"":"&quot;",
        "'":"&#39;"
    }[c]));
    const fmt = (d) => {
        if (!d) return "N/A";
        const t = new Date(d);
        return Number.isNaN(t.getTime()) ? d : t.toLocaleString();
    };

    const req = async (body) => {
        const response = await fetch(location.pathname, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(body)
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) {
            throw new Error(data.error || "Request failed.");
        }
        return data;
    };

    function renderList() {
        const tbody = $("rows");
        if (!STATE.rows.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="muted">No submissions found.</td></tr>';
            return;
        }

        tbody.innerHTML = STATE.rows.map((row) => `
            <tr data-id="${row.id}" class="${STATE.selectedId === Number(row.id) ? "sel" : ""}">
                <td>${safe(row.ticket_ref)}</td>
                <td>${safe(row.full_name)}</td>
                <td>${safe(row.email)}</td>
                <td><span class="pill ${safe(String(row.status || "").toLowerCase())}">${safe(row.status)}</span></td>
                <td>${safe(fmt(row.submitted_at))}</td>
            </tr>
        `).join("");

        tbody.querySelectorAll("tr[data-id]").forEach((tr) => {
            tr.addEventListener("click", () => {
                STATE.selectedId = Number(tr.dataset.id);
                renderList();
                loadDetail();
            });
        });
    }

    function detailPairs(record) {
        const pairs = [
            ["Ticket", record.ticket_ref],
            ["Name", record.full_name],
            ["Email", record.email],
            ["Subject", record.subject],
            ["Message", record.message],
            ["Status", record.status],
            ["IP Address", record.ip_address],
            ["User Agent", record.user_agent],
            ["Submitted", fmt(record.submitted_at)],
            ["Updated", fmt(record.updated_at)]
        ];
        return `<div class="kvs">${pairs.map(([k, v]) => `<div class="k">${safe(k)}</div><div class="v">${safe(v || "N/A")}</div>`).join("")}</div>`;
    }

    function renderDetail() {
        const pane = $("detailPane");
        if (!STATE.detail) {
            pane.innerHTML = '<div class="muted">Select a row to view details.</div>';
            return;
        }

        const record = STATE.detail;
        pane.innerHTML = `
            <div class="detail-actions">
                <select id="statusSelect">
                    ${STATE.statuses.map((status) => `<option value="${status}" ${String(record.status).toLowerCase() === status ? "selected" : ""}>${status}</option>`).join("")}
                </select>
                <button class="primary" id="saveStatusBtn">Save Status</button>
            </div>
            ${detailPairs(record)}
        `;

        $("saveStatusBtn").addEventListener("click", async () => {
            const status = $("statusSelect").value;
            try {
                flash("Saving status...");
                await req({ action: "update_status", id: STATE.selectedId, status });
                flash("Status updated.");
                await loadList();
                await loadDetail();
            } catch (error) {
                flash(error.message, true);
            }
        });
    }

    async function loadList() {
        try {
            flash("Loading submissions...");
            const data = await req({
                action: "list",
                status: $("statusFilter").value || "",
                query: $("qInput").value.trim(),
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
            flash("Loading detail...");
            const data = await req({ action: "detail", id: STATE.selectedId });
            STATE.detail = data.detail || null;
            renderDetail();
            flash("Loaded submission detail.");
        } catch (error) {
            flash(error.message, true);
        }
    }

    $("refreshBtn").addEventListener("click", loadList);
    $("qInput").addEventListener("keydown", (event) => {
        if (event.key === "Enter") {
            loadList();
        }
    });
    $("statusFilter").addEventListener("change", loadList);

    loadList();
})();
</script>
</body>
</html>

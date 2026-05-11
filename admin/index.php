<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/_config.php';
drj_require_admin_auth('DrJessie Admin Home');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Admin Home, DrJessie</title>
<style>
:root {
  --bg: #050a18;
  --surface: #0d1526;
  --surface2: #121e35;
  --border: #1e2d4a;
  --magenta: #f06dc5;
  --text: #c8d4e8;
  --muted: #6a7a96;
  --cyan: #5dd5ff;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: system-ui, -apple-system, sans-serif;
  font-size: 14px;
  min-height: 100vh;
  padding: 22px 24px;
}
h1 { color: var(--magenta); font-size: 24px; font-weight: 700; letter-spacing: -.01em; margin-bottom: 8px; }
h2 { color: var(--magenta); font-size: 17px; margin-bottom: 8px; }
p { line-height: 1.55; }
a { color: var(--cyan); text-decoration: none; }
a:hover { text-decoration: underline; }
.hero { margin-bottom: 16px; }
.muted { color: var(--muted); }
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 12px;
}
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 16px;
}
.card p { margin-bottom: 10px; }
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 6px;
  padding: 8px 13px;
  font-weight: 700;
  font-size: 13px;
  text-decoration: none;
  border: 1px solid var(--border);
  color: var(--text);
  background: var(--surface2);
}
.btn:hover { border-color: var(--magenta); color: var(--magenta); text-decoration: none; }
.btn.primary {
  background: var(--magenta);
  border-color: var(--magenta);
  color: #050a18;
}
.btn.primary:hover {
  color: #050a18;
  opacity: .9;
}
.row { display: flex; gap: 8px; flex-wrap: wrap; }
@media (max-width: 640px) {
  body { padding: 16px 14px; }
}
</style>
</head>
<body>

<section class="hero">
  <h1>DrJessie Admin Home</h1>
  <p class="muted">Central launcher for DrJessie operational tools.</p>
</section>

<section class="grid">
  <article class="card">
    <h2>Form Submissions</h2>
    <p class="muted">View and triage contact messages, mark status, and keep inbox flow organized.</p>
    <div class="row">
      <a class="btn primary" href="/admin/form-submissions.php">Open Form Submissions</a>
    </div>
  </article>
</section>

</body>
</html>

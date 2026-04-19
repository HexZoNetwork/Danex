<?php
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pterodactyl Dummy</title>
  <style>
    :root {
      color-scheme: light;
      --bg: #f6f3eb;
      --panel: #fffdf8;
      --ink: #1f2937;
      --muted: #6b7280;
      --line: #d7c9ad;
      --accent: #b45309;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Georgia, "Times New Roman", serif;
      background:
        radial-gradient(circle at top left, #f8e7c9 0, transparent 30%),
        linear-gradient(135deg, var(--bg), #efe7d6);
      color: var(--ink);
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 24px;
    }
    .card {
      width: min(760px, 100%);
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 24px;
      box-shadow: 0 24px 80px rgba(60, 34, 7, .12);
      overflow: hidden;
    }
    .head {
      padding: 28px 28px 10px;
      border-bottom: 1px solid rgba(180, 83, 9, .15);
    }
    .body {
      padding: 28px;
    }
    h1 {
      margin: 0 0 8px;
      font-size: clamp(30px, 5vw, 48px);
      line-height: 1;
    }
    p {
      margin: 0 0 14px;
      color: var(--muted);
      line-height: 1.6;
    }
    .pill {
      display: inline-block;
      background: #fff2df;
      color: var(--accent);
      border: 1px solid #f1d2a8;
      border-radius: 999px;
      padding: 8px 14px;
      font-size: 14px;
      margin-right: 8px;
      margin-bottom: 8px;
    }
    code {
      background: #f5efe5;
      padding: 2px 8px;
      border-radius: 8px;
    }
  </style>
</head>
<body>
  <section class="card">
    <div class="head">
      <span class="pill">Codespaces</span>
      <span class="pill">HTTP Only</span>
      <span class="pill">Dummy Pterodactyl</span>
      <h1>Pterodactyl mock panel aktif</h1>
    </div>
    <div class="body">
      <p>Panel ini dibuat otomatis oleh <code>code.sh</code> khusus untuk ngetes <code>setup.sh</code> di GitHub Codespaces tanpa VPS real dan tanpa SSL.</p>
      <p>APP_URL: <code>http://10.0.11.196:8080</code></p>
      <p>Panel path: <code>/workspaces/Danex/.codespaces/mock/var/www/pterodactyl</code></p>
      <p>Kalau halaman ini muncul, bootstrap panel dummy sudah berhasil.</p>
    </div>
  </section>
</body>
</html>

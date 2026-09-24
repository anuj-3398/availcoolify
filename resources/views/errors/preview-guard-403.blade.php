{{-- Self-contained: this page is also served on the protected app's own domain, where Coolify's assets and session are unavailable. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>403 · Access denied</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #f6f6f6; color: #1a1a1a; padding: 16px; box-sizing: border-box;
        }
        @media (prefers-color-scheme: dark) { body { background: #101010; color: #e8e8e8; } }
        main { max-width: 440px; text-align: center; }
        .code { font-size: 14px; font-weight: 700; letter-spacing: .08em; color: #d93025; }
        h1 { font-size: 22px; margin: 8px 0 12px; }
        p { margin: 0; opacity: .75; line-height: 1.5; }
    </style>
</head>
<body>
<main>
    <div class="code">403</div>
    <h1>{{ $message }}</h1>
    <p>Your account isn't a member of the team that owns this application. Ask a team admin to invite you.</p>
</main>
</body>
</html>

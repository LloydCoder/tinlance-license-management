<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'TLMS Security')</title>
    <style>
        :root{color-scheme:dark;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#0b1220;color:#e5edf8}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:28px 16px;background:radial-gradient(circle at 15% 10%,#172b47 0,transparent 38%),#0b1220}
        main{width:min(100%,520px);padding:30px;border:1px solid #293b53;border-radius:18px;background:#101c2d;box-shadow:0 24px 80px #0005}
        h1{font-size:1.45rem;margin:0 0 8px}p{color:#a9bad0;line-height:1.55}label{display:block;margin:16px 0 7px;color:#d9e5f3;font-size:.92rem}
        input{width:100%;padding:12px 13px;border:1px solid #40536b;border-radius:9px;background:#0b1524;color:#f4f8ff;font:inherit}
        button,.button{display:inline-flex;align-items:center;justify-content:center;margin-top:16px;padding:11px 15px;border:1px solid #55708f;border-radius:9px;background:#23466e;color:#fff;font:inherit;font-weight:650;text-decoration:none;cursor:pointer}
        button:disabled{opacity:.55;cursor:wait}.secondary{background:transparent}.links{display:flex;flex-wrap:wrap;gap:14px;margin-top:18px}.links a{color:#9fc8f5}
        .errors,.status{margin:12px 0;padding:10px 12px;border-radius:8px;background:#33212a;color:#ffd8e1}.status{background:#173528;color:#c9f7dd}
        .muted{font-size:.88rem;color:#9cafc7}.stack{display:grid;gap:12px}.divider{height:1px;background:#293b53;margin:22px 0}
        code{overflow-wrap:anywhere;color:#d9eaff}svg{max-width:100%;height:auto}
    </style>
</head>
<body>
<main>
    <div class="muted">TINLANCE LICENSE MANAGEMENT SYSTEM</div>
    @yield('content')
</main>
</body>
</html>

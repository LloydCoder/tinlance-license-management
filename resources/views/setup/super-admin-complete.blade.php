<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#0A0C0B">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('brand/tlms-brand.css') }}">
    <title>Setup Complete — TLMS</title>
</head>
<body class="tlms-auth-page">
    <main class="tlms-auth-shell">
        <img class="tlms-brand-lockup tlms-brand-lockup--compact" src="{{ asset('brand/tlms-lockup-horizontal-dark.svg') }}" alt="TLMS — Tinlance License Management System" width="232" height="78">
        <h1>Initial administrator created</h1>
        <p>The one-time bootstrap has been consumed. Remove <code>TLMS_BOOTSTRAP_TOKEN</code> from the environment file and clear Laravel's cached configuration. On cPanel, delete <code>bootstrap/cache/config.php</code> through File Manager if it exists. Do not retain or reuse this one-time secret.</p>
        <p>Administrator sign-in will be available after email verification, authentication, mandatory MFA enrollment and recovery controls are deployed.</p>
    </main>
</body>
</html>

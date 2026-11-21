<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Setup Complete — TLMS</title>
</head>
<body>
    <main>
        <h1>Initial administrator created</h1>
        <p>The one-time bootstrap has been consumed. Remove <code>TLMS_BOOTSTRAP_TOKEN</code> from the environment file and clear Laravel's cached configuration. On cPanel, delete <code>bootstrap/cache/config.php</code> through File Manager if it exists. Do not retain or reuse this one-time secret.</p>
        <p>Administrator sign-in will be available after email verification, authentication, mandatory MFA enrollment and recovery controls are deployed.</p>
    </main>
</body>
</html>

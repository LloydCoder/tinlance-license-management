<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Membership invitation — TLMS</title>
    <style>
        :root{color-scheme:dark;font-family:Inter,ui-sans-serif,system-ui,sans-serif;background:#0b1220;color:#e5edf8}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px 16px;background:#0b1220}
        main{width:min(100%,520px);padding:30px;border:1px solid #293b53;border-radius:18px;background:#101c2d}
        h1{font-size:1.45rem;margin:0 0 12px}h2{font-size:1.1rem;margin:22px 0 8px}p{color:#a9bad0;line-height:1.55}
        label{display:block;margin:16px 0 7px;color:#d9e5f3;font-size:.92rem}
        input{width:100%;padding:12px 13px;border:1px solid #40536b;border-radius:9px;background:#0b1524;color:#f4f8ff;font:inherit}
        button{display:inline-flex;align-items:center;justify-content:center;margin-top:18px;padding:11px 15px;border:1px solid #55708f;border-radius:9px;background:#23466e;color:#fff;font:inherit;font-weight:650;cursor:pointer}
        .muted{font-size:.88rem;color:#9cafc7}.error{color:#ffd8e1;background:#33212a;padding:10px 12px;border-radius:8px}
    </style>
</head>
<body>
<main>
    <div class="muted">TINLANCE LICENSE MANAGEMENT SYSTEM</div>
    <h1>Review your TLMS invitation</h1>

    @if ($mode === 'prepare')
        <p id="invitation-status" role="status">Preparing your secure invitation link…</p>
        <script>
            (async () => {
                const fragment = new URLSearchParams(window.location.hash.slice(1));
                const token = fragment.get('token');
                window.history.replaceState(null, '', window.location.pathname);
                const status = document.getElementById('invitation-status');

                if (!token || !/^[a-f0-9]{64}$/.test(token)) {
                    status.className = 'error';
                    status.textContent = 'This invitation link is incomplete or invalid. Ask the inviter to send a new link.';
                    return;
                }

                try {
                    const response = await fetch('/membership-invitations/prepare', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify({ token })
                    });
                    const result = await response.json();

                    if (!response.ok) {
                        throw new Error('This invitation is invalid, expired or has already been used.');
                    }

                    if (result.next === 'login') {
                        window.location.assign(result.redirect);
                        return;
                    }

                    window.location.reload();
                } catch (error) {
                    status.className = 'error';
                    status.textContent = error instanceof Error ? error.message : 'Unable to prepare this invitation.';
                }
            })();
        </script>
    @elseif ($mode === 'account_setup')
        <p>You have been invited to join <strong>{{ $preview['tenant_name'] }}</strong> as {{ $preview['role_name'] }}.</p>
        <p>Confirm your name and set a strong password. This one-time invitation also verifies the invited email address.</p>
        <form method="post" action="{{ route('membership-invitations.accept.new') }}">
            @csrf
            <label for="name">Your name</label>
            <input id="name" name="name" type="text" minlength="2" maxlength="160" value="{{ old('name') }}" autocomplete="name" required>
            <label for="password">Create a password</label>
            <input id="password" name="password" type="password" minlength="15" maxlength="1024" autocomplete="new-password" required>
            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" minlength="15" maxlength="1024" autocomplete="new-password" required>
            @if ($errors->any())
                <p class="error" role="alert">{{ $errors->first() }}</p>
            @endif
            <button type="submit">Verify email and accept invitation</button>
        </form>
    @elseif ($mode === 'authenticated')
        <p>You have been invited to join <strong>{{ $preview['tenant_name'] }}</strong> as {{ $preview['role_name'] }}.</p>
        <p>Confirm acceptance while signed in to the invited account. TLMS will not grant access to a different account.</p>
        <form method="post" action="{{ route('membership-invitations.accept.authenticated') }}">
            @csrf
            <button type="submit">Accept invitation</button>
        </form>
    @endif
</main>
</body>
</html>

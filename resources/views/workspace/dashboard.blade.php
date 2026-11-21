<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Your TLMS workspace</title>
    <style>
        :root{color-scheme:dark;font-family:Inter,ui-sans-serif,system-ui,sans-serif;background:#0b1220;color:#e5edf8}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;padding:28px 16px;background:#0b1220}
        main{width:min(100%,960px);margin:0 auto}h1{font-size:1.7rem;margin:0 0 8px}h2{font-size:1.15rem;margin:26px 0 12px}
        p{color:#a9bad0;line-height:1.55}.muted{font-size:.88rem;color:#9cafc7}
        .panel{border:1px solid #293b53;border-radius:14px;background:#101c2d;padding:18px;margin:12px 0}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
        .role{display:inline-block;margin:6px 6px 0 0;padding:4px 8px;border:1px solid #40536b;border-radius:999px;color:#d9e5f3;font-size:.8rem}
        form{margin-top:28px}button{padding:10px 14px;border:1px solid #55708f;border-radius:9px;background:#23466e;color:#fff;font:inherit;cursor:pointer}
    </style>
</head>
<body>
<main>
    <div class="muted">TINLANCE LICENSE MANAGEMENT SYSTEM</div>
    <h1>Your TLMS workspace</h1>
    <p>Signed in as <strong>{{ $user->name }}</strong>. This workspace shows only your own active memberships.</p>

    <h2>Vendor accounts</h2>
    @forelse ($vendorMemberships as $membership)
        <section class="panel">
            <strong>{{ $membership->vendorAccount->display_name }}</strong>
            <p class="muted">{{ $membership->vendorAccount->legal_name }}</p>
            @foreach ($membership->roles as $role)
                <span class="role">{{ $role->name }}</span>
            @endforeach
        </section>
    @empty
        <p class="muted">You do not currently have an active vendor-account membership.</p>
    @endforelse

    <h2>Customer organizations</h2>
    @forelse ($customerMemberships as $membership)
        <section class="panel">
            <strong>{{ $membership->customerOrganization->display_name }}</strong>
            <p class="muted">Managed by {{ $membership->customerOrganization->vendorAccount->display_name }}</p>
            @foreach ($membership->roles as $role)
                <span class="role">{{ $role->name }}</span>
            @endforeach
        </section>
    @empty
        <p class="muted">You do not currently have an active customer-organization membership.</p>
    @endforelse

    <p class="muted">Portal actions are being implemented separately. Membership visibility is read-only in this workspace.</p>
    <form method="post" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Sign out</button>
    </form>
</main>
</body>
</html>

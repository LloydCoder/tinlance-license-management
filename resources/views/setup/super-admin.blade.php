<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#0A0C0B">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('brand/tlms-brand.css') }}">
    <title>Initial Super Admin Setup — TLMS</title>
</head>
<body class="tlms-auth-page">
    <main class="tlms-auth-shell">
        <img class="tlms-brand-lockup tlms-brand-lockup--compact" src="{{ asset('brand/tlms-lockup-horizontal-dark.svg') }}" alt="TLMS — Tinlance License Management System" width="232" height="78">
        <h1>Initial Super Admin Setup</h1>
        <p>Create the first administrator for this TLMS installation. This setup page is available only once.</p>
        <form method="post" action="{{ route('setup.super-admin.store') }}" autocomplete="off" class="tlms-stack">
            @csrf
            <label for="bootstrap_token">One-time installation secret</label>
            <input id="bootstrap_token" name="bootstrap_token" type="password" required minlength="43" maxlength="128" autocomplete="off">
            @error('bootstrap_token') <p class="errors" role="alert">{{ $message }}</p> @enderror
            <label for="name">Administrator name</label>
            <input id="name" name="name" type="text" required minlength="2" maxlength="160" autocomplete="name" value="{{ old('name') }}">
            @error('name') <p class="errors" role="alert">{{ $message }}</p> @enderror
            <label for="email">Administrator email</label>
            <input id="email" name="email" type="email" required maxlength="254" autocomplete="email" value="{{ old('email') }}">
            @error('email') <p class="errors" role="alert">{{ $message }}</p> @enderror
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required minlength="15" maxlength="1024" autocomplete="new-password">
            @error('password') <p class="errors" role="alert">{{ $message }}</p> @enderror
            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="15" maxlength="1024" autocomplete="new-password">
            <button type="submit">Create Super Admin</button>
        </form>
    </main>
</body>
</html>

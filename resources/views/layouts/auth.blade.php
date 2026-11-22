<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#0A0C0B">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('brand/tlms-brand.css') }}">
    <title>@yield('title', 'TLMS Security')</title>
</head>
<body class="tlms-auth-page">
<main class="tlms-auth-shell">
    <img class="tlms-brand-lockup tlms-brand-lockup--compact" src="{{ asset('brand/tlms-lockup-horizontal-dark.svg') }}" alt="TLMS — Tinlance License Management System" width="232" height="78">
    @yield('content')
</main>
<script type="module" src="{{ asset('build/passkeys.js') }}"></script>
<script type="module" src="{{ asset('build/membership-invitations.js') }}"></script>
</body>
</html>

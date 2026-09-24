<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- A storefront must be findable; a demo shown to one partner must not be. The operator decides. --}}
    <meta name="robots" content="{{ config('landing.indexable') ? 'index, follow' : 'noindex, nofollow' }}">
    <meta name="theme-color" content="#0b0b0e">
    <meta name="color-scheme" content="dark">

    @php
        $brand = config('landing.brand');
        $summary = config('landing.subhead');
    @endphp

    <title>{{ $title ?? $brand }}</title>
    <meta name="description" content="{{ $summary }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brand }}">
    <meta property="og:title" content="{{ $title ?? $brand }}">
    <meta property="og:description" content="{{ $summary }}">
    <meta property="og:url" content="{{ url('/') }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title ?? $brand }}">
    <meta name="twitter:description" content="{{ $summary }}">

    <link rel="canonical" href="{{ url('/') }}">
    <link rel="icon" href="{{ config('landing.favicon') ?: asset('xlaunch-logo.svg') }}" type="image/svg+xml">

    @vite(['resources/css/landing.css'])
</head>

<body>
    {{ $slot }}
</body>

</html>

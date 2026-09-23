@props(['name'])

@php
    $paths = [
        'git' => '<circle cx="6" cy="6" r="2.4"/><circle cx="6" cy="18" r="2.4"/><circle cx="17" cy="9" r="2.4"/><path d="M6 8.4v7.2M17 11.4c0 3-2.6 4-5 4.3"/>',
        'database' => '<ellipse cx="12" cy="6" rx="7" ry="2.8"/><path d="M5 6v12c0 1.55 3.13 2.8 7 2.8s7-1.25 7-2.8V6"/><path d="M5 12c0 1.55 3.13 2.8 7 2.8s7-1.25 7-2.8"/>',
        'stack' => '<path d="M12 3 3 7.5 12 12l9-4.5L12 3Z"/><path d="m3 12.5 9 4.5 9-4.5"/><path d="m3 17 9 4.5 9-4.5"/>',
        'lock' => '<rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.8a4 4 0 0 1 8 0v2.7"/>',
        'shield' => '<path d="M12 3 5 6v5.7c0 4.3 2.9 8.2 7 9.3 4.1-1.1 7-5 7-9.3V6l-7-3Z"/><path d="m9 12.2 2.2 2.2 4-4.2"/>',
        'terminal' => '<rect x="3" y="4.5" width="18" height="15" rx="2.5"/><path d="m7.5 10 2.6 2.4-2.6 2.4M13 15h4"/>',
    ];
@endphp

<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    {!! $paths[$name] ?? '' !!}
</svg>

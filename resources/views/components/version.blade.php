@php($version = config('constants.xlaunch.version'))

@if (str_contains($version, '-dev.'))
    <span {{ $attributes->merge(['class' => 'text-xs opacity-90']) }}>v{{ $version }}</span>
@else
    <a {{ $attributes->merge(['class' => 'text-xs cursor-pointer opacity-90 hover:opacity-100 dark:hover:text-white hover:text-black']) }}
        href="https://github.com/northlatch/xlaunch/releases/tag/v{{ config('constants.xlaunch.version') }}" target="_blank">
        v{{ $version }}
    </a>
@endif

{{-- The front page's block wordmark: the letters let go and rebuild (public/js/brand-blocks.js). --}}
@php
    $logoWords = config('landing.logo_words') ?: 'gridframes';
    // The box keeps the word's proportions: 5-block letters, one block apart, 9 rows tall.
    $logoColumns = collect(explode(',', $logoWords))->map(fn ($w) => strlen(trim($w)) * 6 - 1)->max();
@endphp
<span {{ $attributes->class(['brand-logo']) }} data-logo="{{ $logoWords }}"
    data-logo-split="{{ config('landing.logo_split') }}" style="aspect-ratio: {{ $logoColumns }} / 9">
    <img src="/brand/gridframes/logo-on-light.svg" alt="GridFrames" class="brand-logo-light">
    <img src="/brand/gridframes/logo.svg" alt="GridFrames" class="brand-logo-dark">
</span>
@once
    <style>
        .brand-logo { display: block; height: 20px; --logo-accent: #6b16ed; --logo-word: #0a0f0c; }
        .dark .brand-logo { --logo-accent: #8f63ff; --logo-word: #e4ece8; }
        .brand-logo img, .brand-logo canvas { display: block; width: 100%; height: 100%; }
        .brand-logo .brand-logo-dark, .dark .brand-logo .brand-logo-light { display: none; }
        .dark .brand-logo .brand-logo-dark { display: block; }
    </style>
    <script src="{{ asset('js/brand-blocks.js') }}" defer data-navigate-once></script>
@endonce

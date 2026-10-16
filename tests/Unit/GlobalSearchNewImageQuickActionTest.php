<?php

/**
 * Unit tests to verify that the "new image" quick action properly matches
 * the docker-image type using the quickcommand field.
 *
 * This test verifies the fix for the issue where typing "new image" would
 * not match because the frontend was only checking name and type fields,
 * not the quickcommand field.
 */
it('ensures GlobalSearch blade template checks quickcommand field in matching logic', function () {
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/global-search.blade.php');

    // Check that the matching logic includes quickcommand check
    expect($bladeFile)
        ->toContain('item.quickcommand')
        ->toContain('quickcommand.toLowerCase().includes(trimmed)');
});

it('ensures GlobalSearch clears search query when starting resource creation', function () {
    $globalSearchFile = file_get_contents(__DIR__.'/../../app/Livewire/GlobalSearch.php');

    // Check that navigateToResourceCreation clears the search query
    expect($globalSearchFile)
        ->toContain('$this->searchQuery = \'\'');
});

it('ensures GlobalSearch uses redirect route helper', function () {
    $globalSearchFile = file_get_contents(__DIR__.'/../../app/Livewire/GlobalSearch.php');

    // Check that completeResourceCreation uses the shared redirect route helper
    expect($globalSearchFile)
        ->toContain('redirectRoute($this, \'project.resource.create\'');
});

it('routes new server quick action to the new server page', function () {
    $globalSearchFile = file_get_contents(__DIR__.'/../../app/Livewire/GlobalSearch.php');
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/global-search.blade.php');

    expect($globalSearchFile)
        ->toContain("if (\$type === 'server')")
        ->toContain("redirectRoute(\$this, 'server.create')");

    expect($bladeFile)
        ->toContain("window.location.href = '/servers/new'")
        ->not->toContain('@open-create-modal-server.window');
});

it('ensures docker-image item has quickcommand with new image', function () {
    $globalSearchFile = file_get_contents(__DIR__.'/../../app/Livewire/GlobalSearch.php');

    // Check that Docker Image has the correct quickcommand
    expect($globalSearchFile)
        ->toContain("'name' => 'Docker Image'")
        ->toContain("'quickcommand' => '(type: new image)'")
        ->toContain("'type' => 'docker-image'");
});

it('uses neutral hover styling for GlobalSearch quick action rows', function () {
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/global-search.blade.php');
    $cssFile = file_get_contents(__DIR__.'/../../resources/css/app.css');

    // Match the full opening tag (the Alpine @click contains "=>" so we cannot stop at ">")
    preg_match_all('/<button[^<]*(?:wire:click="navigateToResource|\\$wire\\.navigateToResource)[^<]*>/s', $bladeFile, $quickActionButtons);

    expect($quickActionButtons[0])->not->toBeEmpty();

    // Quick action rows use the shared command-palette-item class instead of
    // row-level Tailwind utilities, so the neutral hover lives in the stylesheet.
    foreach ($quickActionButtons[0] as $quickActionButton) {
        expect($quickActionButton)
            ->toContain('search-result-item command-palette-item')
            ->not->toContain('hover:bg-warning-50')
            ->not->toContain('hover:border-warning-500');
    }

    // The shared row style is a neutral fill on hover (no warning/accent hover)
    expect($cssFile)
        ->toContain('.command-palette-item:hover')
        ->toContain('background: var(--northlatch-fill);');
});

it('uses product logos for GlobalSearch database quick actions', function () {
    $globalSearchFile = file_get_contents(__DIR__.'/../../app/Livewire/GlobalSearch.php');
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/global-search.blade.php');

    expect($bladeFile)
        ->toContain('template x-if="item.logo"')
        ->toContain(':src="item.logo.startsWith(\'http\') ? item.logo : \'/\' + item.logo"')
        ->toContain(':alt="item.name"');

    foreach ([
        'postgresql' => 'svgs/postgresql.svg',
        'mysql' => 'svgs/mysql.svg',
        'mariadb' => 'svgs/mariadb.svg',
        'redis' => 'svgs/redis.svg',
        'keydb' => 'svgs/keydb.svg',
        'dragonfly' => 'svgs/dragonfly.svg',
        'mongodb' => 'svgs/mongodb.svg',
        'clickhouse' => 'svgs/clickhouse-icon.svg',
    ] as $type => $logo) {
        expect($globalSearchFile)
            ->toContain("'type' => '{$type}'")
            ->toContain("'logo' => '{$logo}'");

        expect(file_exists(__DIR__.'/../../public/'.$logo))->toBeTrue();
    }
});

it('uses neutral hover styling for GlobalSearch existing resource rows', function () {
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/global-search.blade.php');
    $cssFile = file_get_contents(__DIR__.'/../../resources/css/app.css');

    preg_match_all('/<a[^>]+(?:href="{{ \\$result\\[\'link\'\\]|:href="result\\.link)[^>]+>/s', $bladeFile, $existingResourceLinks);

    expect($existingResourceLinks[0])->not->toBeEmpty();

    // Existing resource rows share the command-palette-item style; the neutral
    // hover/focus treatment lives in the stylesheet instead of inline utilities.
    foreach ($existingResourceLinks[0] as $existingResourceLink) {
        expect($existingResourceLink)
            ->toContain('search-result-item command-palette-item')
            ->not->toContain('hover:bg-neutral-50')
            ->not->toContain('focus:bg-warning')
            ->not->toContain('hover:border-northlatch')
            ->not->toContain('focus:border-warning');
    }

    // Focused rows get a soft accent fill with no outline/ring (keyboard selection)
    expect($cssFile)
        ->toContain('.command-palette-item:focus,')
        ->toContain('background: color-mix(in oklab, var(--color-accent) 12%, transparent);')
        ->toContain('outline: none !important;');
});

it('uses visible cropped SVG marks for wide database logos', function () {
    $keydbLogo = file_get_contents(__DIR__.'/../../public/svgs/keydb.svg');
    $dragonflyLogo = file_get_contents(__DIR__.'/../../public/svgs/dragonfly.svg');
    $clickhouseLogo = file_get_contents(__DIR__.'/../../public/svgs/clickhouse-icon.svg');

    expect($keydbLogo)
        ->toContain('viewBox="0 0 160 182"')
        ->toContain('svg{color:#d4d4d4}')
        ->not->toContain('prefers-color-scheme');

    expect($dragonflyLogo)
        ->toContain('viewBox="0 0 88 88"')
        ->toContain('svg{color:#d4d4d4}')
        ->not->toContain('prefers-color-scheme');

    expect($clickhouseLogo)
        ->toContain('viewBox="1.70837 1.875 22.25025 22.2493"')
        ->toContain('svg{color:#d4d4d4}')
        ->toContain('x="20.7087"')
        ->toContain('width="2.24992"')
        ->not->toContain('viewBox="0 0 100 43"')
        ->not->toContain('width="215"')
        ->not->toContain('height="90"');
});

it('uses cropped image assets instead of inline wide logos for GlobalSearch database icons', function () {
    $globalSearchFile = file_get_contents(__DIR__.'/../../app/Livewire/GlobalSearch.php');
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/global-search.blade.php');
    $cssFile = file_get_contents(__DIR__.'/../../resources/css/app.css');

    // Icons render as cropped <img> assets inside the shared icon container
    expect($bladeFile)
        ->toContain('command-palette-item-icon')
        ->toContain('<img :src="item.logo.startsWith(\'http\') ? item.logo : \'/\' + item.logo"')
        ->not->toContain('$item[\'logo_html\']')
        ->not->toContain('x-html="item.logo_html"');

    // Cropping is done via CSS object-fit on the icon image, not inline wide logos
    expect($cssFile)
        ->toContain('.command-palette-item-icon img')
        ->toContain('object-fit: contain');

    expect($globalSearchFile)->not->toContain("'logo_html' =>");
});

it('uses themed plus icons for GlobalSearch creatable actions without logos', function () {
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/global-search.blade.php');

    expect($bladeFile)
        ->toContain('template x-if="!item.logo"')
        ->toContain('command-palette-item-icon is-create')
        // The plus glyph itself (horizontal + vertical strokes)
        ->toContain('d="M6 12H18"')
        ->toContain('d="M12 18V6"')
        ->not->toContain('rounded-lg bg-warning-100 dark:bg-warning-900/40')
        ->not->toContain('text-warning-600 dark:text-warning-400');
});

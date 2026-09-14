<?php

use Illuminate\Support\Facades\Blade;

/**
 * Header version badge should open the matching GitHub release page.
 */
test('desktop header version links to the xlaunch github release for the installed version', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
    $version = file_get_contents(resource_path('views/components/version.blade.php'));

    expect($layout)
        ->toContain('<x-version')
        ->not->toContain("class=\"text-[10.5px] font-medium text-neutral-400 dark:text-fg-faint\">v{{ config('constants.xlaunch.version') }}</span>");

    expect($version)
        ->toContain("https://github.com/northlatch/xlaunch/releases/tag/v{{ config('constants.xlaunch.version') }}")
        ->toContain('target="_blank"');
});

test('development versions are not linked to nonexistent github releases', function () {
    config(['constants.xlaunch.version' => '4.3.1-dev.d64cbda3e']);

    $version = Blade::render('<x-version />');

    expect($version)
        ->toContain('v4.3.1-dev.d64cbda3e')
        ->not->toContain('href=')
        ->not->toContain('target="_blank"');
});

test('mobile sidebar shows the installed xlaunch version when opened', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)
        ->toContain('data-mobile-sidebar-brand')
        ->toContain('<x-version class="!text-[10.5px]');
});

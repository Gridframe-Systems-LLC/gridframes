<?php

/**
 * Create team primary action must use the theme-aware purple/yellow accent,
 * not the legacy solid northlatch purple fill.
 */
test('create team button uses theme-aware primary accent classes', function () {
    $view = file_get_contents(resource_path('views/livewire/team/create.blade.php'));

    expect($view)
        ->toContain('Create team')
        ->toContain('bg-northlatch/10!')
        ->toContain('text-northlatch!')
        ->toContain('dark:bg-warning/15!')
        ->toContain('dark:text-warning!')
        ->not->toContain('bg-northlatch! text-white!')
        ->not->toContain('hover:bg-northlatch-100!');
});

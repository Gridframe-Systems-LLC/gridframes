<?php

it('uses northlatch purple for scrollbars in dark mode', function () {
    $utilities = file_get_contents(dirname(__DIR__, 2).'/resources/css/utilities.css');

    expect($utilities)
        ->toContain('dark:scrollbar-thumb-northlatch-100')
        ->not->toContain('dark:scrollbar-thumb-warning');
});

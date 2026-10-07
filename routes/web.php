<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// TEMPORARY (Phase 03B-1A): UI component demo. Local/testing only. Remove with resources/views/dev/.
if (app()->environment(['local', 'testing'])) {
    Route::view('/_dev/ui-components', 'dev.ui-components')->name('dev.ui-components');
}

require __DIR__.'/auth.php';
require __DIR__.'/student.php';
require __DIR__.'/admin.php';

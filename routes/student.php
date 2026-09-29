<?php

use Illuminate\Support\Facades\Route;

Route::prefix('student')->name('student.')->middleware('student')->group(function () {
    Route::view('/', 'student.home')->name('home');
});

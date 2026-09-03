<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('plugin.getfy-example-commerce::dashboard');
})->name('getfy-example-commerce.index');

<?php

use Illuminate\Support\Facades\Route;
use Ngelekanyo\Payfast\Laravel\Http\Controllers\PayfastController;

Route::post('/initiate', [PayfastController::class, 'initiate']);
Route::post('/notify', [PayfastController::class, 'notify']);
Route::delete('/cancel/{token}', [PayfastController::class, 'cancel']);
Route::put('/pause/{token}', [PayfastController::class, 'pause']);
Route::put('/unpause/{token}', [PayfastController::class, 'unpause']);
Route::get('/fetch/{token}', [PayfastController::class, 'fetch']);

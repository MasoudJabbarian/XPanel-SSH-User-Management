<?php

use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:api')->group(function () {
    Route::get('/users', [ApiController::class, 'listuser'])->name('api.listuser');
    Route::get('/users/status/{sort}', [ApiController::class, 'sort_listuser'])->name('api.listuser.sort');
    Route::post('/users', [ApiController::class, 'add_user'])->name('api.add.user');
    Route::get('/users/{username}', [ApiController::class, 'show_detail'])->name('api.show.detail');
    Route::post('/users/edit', [ApiController::class, 'edit'])->name('api.user.edit');
    Route::post('/users/delete', [ApiController::class, 'delete_user'])->name('api.user.delete');
    Route::post('/users/active', [ApiController::class, 'active_user'])->name('api.user.active');
    Route::post('/users/deactive', [ApiController::class, 'deactive_user'])->name('api.user.deactive');
    Route::post('/users/traffic/reset', [ApiController::class, 'retrafic_user'])->name('api.user.retraffic');
    Route::post('/users/renewal', [ApiController::class, 'renewal_user'])->name('api.user.renewal');
    Route::get('/users/traffic/{username}', [ApiController::class, 'traffic_user'])->name('api.user.traffic');
    Route::get('/online', [ApiController::class, 'online_user'])->name('api.user.online');
    Route::post('/kill/{method}/{param}', [ApiController::class, 'kill'])->name('api.user.kill');
});

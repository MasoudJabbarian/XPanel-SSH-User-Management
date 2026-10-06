<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DahboardController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\OnlineController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\AdminsController;
use App\Http\Controllers\Auth\LoginController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

$panel=env('PANEL_DIRECT');
if($panel=='cp')
{
    Route::get('/', function () {
        return redirect('/login');
    });
}
Route::prefix("$panel")->group(function()
{

    Route::get('/', [LoginController::class,'showLoginForm'])->name('login');
    Route::get('/login', [LoginController::class,'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class,'login']);
    Route::get('/dashboard',[DahboardController::class,'index'])->name('dashboard');
    Route::get('/dashboard/usage',[DahboardController::class,'usage'])->name('usage');
    Route::get('/users',[UserController::class,'index'])->name('users');
    Route::get('/users/sort/{status}',[UserController::class,'index_sort'])->name('users.sort');
    Route::get('/users/search',[UserController::class,'search'])->name('users.search');
    Route::get('/users/qr/{data}',[UserController::class,'generateQRCode'])->name('qrimg');
    Route::post('/users',[UserController::class,'newuser'])->name('new.user');
    Route::post('/users/bulk',[UserController::class,'bulkuser'])->name('new.bulkuser');
    Route::post('/user/active/{username}',[UserController::class,'activeuser'])->name('user.active');
    Route::post('/user/deactive/{username}',[UserController::class,'deactiveuser'])->name('user.deactive');
    Route::post('/user/reset/{username}',[UserController::class,'reset_traffic'])->name('user.reset');
    Route::post('/user/delete/{username}',[UserController::class,'delete'])->name('user.delete');


    Route::post('/user/all/delete',[UserController::class,'user_all_delete'])->name('user.all.delete');
    Route::post('/user/action/bulk',[UserController::class,'delete_bulk'])->name('user.action.bulk');
    Route::post('/user/renewal',[UserController::class,'renewal'])->name('new.renewal');
    Route::post('/user/renewal/bulk',[UserController::class,'renew_bulk'])->name('new.renewal.bulk');
    Route::get('/user/edit/{username}',[UserController::class,'edit'])->name('user.edit');
    Route::post('/user/edit',[UserController::class,'update'])->name('user.update');
    Route::get('/online',[OnlineController::class,'index'])->name('online');
    Route::post('/online/id/{pid}',[OnlineController::class,'kill_pid'])->name('online.kill.pid');
    Route::post('/online/user/{username}',[OnlineController::class,'kill_user'])->name('online.kill.username');
    Route::get('/checkip',[OnlineController::class,'filtering'])->name('filtering');
    Route::get('/settings',[SettingsController::class,'defualt'])->name('setting');
    Route::get('/settings/{name}',[SettingsController::class,'index'])->name('settings');
    Route::get('/settings/mod/{name}',[SettingsController::class,'mod'])->name('mod');
    Route::get('/settings/lang/{name}',[SettingsController::class,'lang'])->name('lang');
    Route::post('/settings/general',[SettingsController::class,'update_general'])->name('settings.general');
    Route::post('/settings/change/port/ssh',[SettingsController::class,'change_port_ssh'])->name('settings.change.port.ssh');
    Route::post('/settings/backup/new',[SettingsController::class,'upload_backup'])->name('settings.backup.upload');
    Route::post('/settings/backup/delete/{name}',[SettingsController::class,'delete_backup'])->name('settings.backup.delete');
    Route::post('/settings/backup/restore/{name}',[SettingsController::class,'restore_backup'])->name('settings.backup.restore');
    Route::post('/settings/backup/make/',[SettingsController::class,'make_backup'])->name('settings.backup.make');
    Route::get('/settings/backup/dl/{name}',[SettingsController::class,'download_backup'])->name('settings.backup.dl');
    Route::post('/settings/api',[SettingsController::class,'insert_api'])->name('settings.api');
    Route::post('/settings/api/renew/{id}',[SettingsController::class,'renew_api'])->name('settings.token.renew');
    Route::post('/settings/api/delete/{id}',[SettingsController::class,'delete_api'])->name('settings.token.delete');
    Route::get('/managers',[AdminsController::class,'index'])->name('admins');
    Route::post('/managers',[AdminsController::class,'insert'])->name('admin.new');
    Route::post('/managers/active/{username}',[AdminsController::class,'activeadmin'])->name('admin.active');
    Route::post('/managers/deactive/{username}',[AdminsController::class,'deactiveadmin'])->name('admin.deactive');
    Route::post('/managers/delete/{username}',[AdminsController::class,'deleteadmin'])->name('admin.delete');
    Route::get('/managers/edit/{username}',[AdminsController::class,'edit'])->name('admin.edit');
    Route::post('/manager/update',[AdminsController::class,'update'])->name('admin.update');
    Route::post('/logout',[LoginController::class,'logout'])->name('user.logout');

});

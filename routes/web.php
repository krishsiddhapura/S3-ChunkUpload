<?php

use App\Http\Controllers\ChunksUploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});


Route::get('upload',[ChunksUploadController::class,'index']);
Route::get('upload-client',[ChunksUploadController::class,'indexClient']);
Route::post('presign-urls',[ChunksUploadController::class,'presignUrls'])->name('presign-urls');
Route::post('merge-url-frontend',[ChunksUploadController::class,'mergeUrlFrontend'])->name('merge-url-frontend');
// Route::get('upload',[ChunksUploadController::class,'index']);
// Route::get('upload',[ChunksUploadController::class,'index']);

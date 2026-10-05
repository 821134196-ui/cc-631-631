<?php

use App\Http\Controllers\AdjustmentController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ChannelStateController;
use App\Http\Controllers\PublicationController;
use App\Http\Controllers\RestoreTaskController;
use App\Http\Controllers\SimulationController;
use App\Http\Controllers\StoreController;
use Illuminate\Support\Facades\Route;

Route::get('/stores', [StoreController::class, 'index']);
Route::get('/stores/{store}/calendar', [CalendarController::class, 'show']);
Route::get('/stores/{store}/adjustments', [AdjustmentController::class, 'index']);
Route::post('/stores/{store}/adjustments', [AdjustmentController::class, 'store']);

Route::post('/adjustments/{adjustment}/approve', [AdjustmentController::class, 'approve']);
Route::post('/adjustments/{adjustment}/reject', [AdjustmentController::class, 'reject']);
Route::post('/adjustments/{adjustment}/revise', [AdjustmentController::class, 'revise']);

Route::get('/stores/{store}/publications', [PublicationController::class, 'index']);
Route::post('/publication-channels/{receipt}/retry', [PublicationController::class, 'retry']);

Route::get('/stores/{store}/channel-states', [ChannelStateController::class, 'index']);
Route::get('/stores/{store}/restore-tasks', [RestoreTaskController::class, 'index']);

// 本地渠道模拟控制
Route::post('/simulate/directive', [SimulationController::class, 'directive']);
Route::post('/simulate/deliver-receipts', [SimulationController::class, 'deliverReceipts']);
Route::post('/simulate/run-expiry', [SimulationController::class, 'runExpiry']);

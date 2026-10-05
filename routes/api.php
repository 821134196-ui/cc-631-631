<?php

use App\Http\Controllers\AdjustmentController;
use App\Http\Controllers\ChannelStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/stores', [AdjustmentController::class, 'stores']);
Route::get('/stores/{store}/calendar', [AdjustmentController::class, 'calendar']);
Route::get('/stores/{store}/adjustments', [AdjustmentController::class, 'index']);
Route::post('/stores/{store}/adjustments', [AdjustmentController::class, 'store']);

Route::post('/adjustments/{adjustment}/revise', [AdjustmentController::class, 'revise']);
Route::post('/adjustments/{adjustment}/approve', [AdjustmentController::class, 'approve']);
Route::post('/adjustments/{adjustment}/reject', [AdjustmentController::class, 'reject']);

Route::get('/channel-status', [ChannelStatusController::class, 'index']);
Route::get('/channel-states', [ChannelStatusController::class, 'channelStates']);
Route::post('/channel-publishes/{publish}/retry', [ChannelStatusController::class, 'retry']);

<?php
// routes/web.php atau routes/api.php

use App\Http\Controllers\Api\BroadcastController;
use App\Http\Controllers\Api\ImportController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\KendaraanController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\OmniChannelController;

// Web routes
// Route::prefix('broadcast')->group(function () {
//     // GET endpoints
//     // Route::get('/kendaraan', [BroadcastController::class, 'getKendaraanList']);
//     // Route::get('/logs', [BroadcastController::class, 'getLogs']);
//     // Route::get('/status/{id}', [BroadcastController::class, 'checkStatus']);
//     // Route::get('/stats', [BroadcastController::class, 'getStats']);

//     // POST endpoints
//     Route::post('/send-mass', [BroadcastController::class, 'sendMassBroadcast']);
//     Route::post('/send-single/{id}', [BroadcastController::class, 'sendSingleBroadcast']);
//     Route::post('/retry-failed', [BroadcastController::class, 'retryFailedBroadcast']);
//     Route::post('/cancel/{id}', [BroadcastController::class, 'cancelBroadcast']);
// });
Route::prefix('v1')->group(function () {
    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // Vehicle Management
    Route::apiResource('kendaraans', KendaraanController::class);
    Route::put('/kendaraans/{id}/status', [KendaraanController::class, 'updateStatus']);


    // Post kendaraan baru
    Route::post('/kendaraans', [KendaraanController::class, 'store']);

    // POST endpoints for Broadcast
    Route::post('/send-mass', [BroadcastController::class, 'sendMassBroadcast']);
    Route::post('/send-single/{id}', [BroadcastController::class, 'sendSingleBroadcast']);
    // Route::post('/retry-failed', [BroadcastController::class, 'retryFailedBroadcast']);
    // Route::post('/cancel/{id}', [BroadcastController::class, 'cancelBroadcast']);

    // callback endpoint for status update
    // Route::post('/callback', [BroadcastController::class, 'statusUpdateCallback']);
    // Route::post('/callback/test', [BroadcastController::class, 'statusUpdateCallback_test']);

    // Import routes
    Route::post('/import/excel', [ImportController::class, 'import']);
    Route::post('/import/csv', [ImportController::class, 'importCsv']);
    Route::get('/import/status/{id}', [ImportController::class, 'importStatus']);
    Route::get('/import/template', [ImportController::class, 'downloadTemplate']);

    // Reports
    // Route::get('/reports/export', [ReportController::class, 'exportExcel']);
    Route::get('/reports/data', [ReportController::class, 'getReportData']);
    Route::get('/reports', [ReportController::class, 'getReportByDate']);
    Route::get('/reports/excel', [ReportController::class, 'exportFilteredExcel']);


    // Omni Channel
    Route::get('/inbox', [OmniChannelController::class, 'getDataInbox']);
    Route::get('/chat/{dataid}', [OmniChannelController::class, 'getDataPercakapan']);
    Route::post('/reply', [OmniChannelController::class, 'sendReply']);
    // Route::put('/conversations/{kendaraanId}/read', [OmniChannelController::class, 'markAsRead']);

    // Webhook for incoming messages
    Route::post('/webhook/whatsapp', [OmniChannelController::class, 'webhookIncoming']);
    Route::post('/webhook/whatsapp/test', [OmniChannelController::class, 'webhookIncoming_test']);
});

<?php
// routes/web.php atau routes/api.php

use App\Http\Controllers\Api\BroadcastController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\KendaraanController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\OmniChannelController;
use Illuminate\Support\Facades\Route;

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
// Route::prefix('v1')->group(function () {
//     Route::middleware('auth:sanctum')->group(function () {

//         // Dashboard
//         Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

//         // Vehicle Management
//         Route::apiResource('kendaraans', KendaraanController::class);
//         Route::put('/kendaraans/{id}/status', [KendaraanController::class, 'updateStatus']);


//         // Post kendaraan baru
//         Route::post('/kendaraans', [KendaraanController::class, 'store']);

//         // POST endpoints for Broadcast
//         Route::post('/send-mass', [BroadcastController::class, 'sendMassBroadcast']);
//         // Route::post('/send-single/{id}', [BroadcastController::class, 'sendSingleBroadcast']);

//         // Import routes
//         Route::post('/import/excel', [ImportController::class, 'import']);
//         Route::post('/import/csv', [ImportController::class, 'importCsv']);
//         Route::get('/import/status/{id}', [ImportController::class, 'importStatus']);
//         Route::get('/import/template', [ImportController::class, 'downloadTemplate']);

//         // Reports
//         // Route::get('/reports/export', [ReportController::class, 'exportExcel']);
//         Route::get('/reports/data', [ReportController::class, 'getReportData']);
//         Route::get('/reports', [ReportController::class, 'getReportByDate']);
//         Route::get('/reports/excel', [ReportController::class, 'exportFilteredExcel']);


//         // Omni Channel
//         Route::get('/inbox', [OmniChannelController::class, 'getDataInbox']);
//         Route::get('/chat/{dataid}', [OmniChannelController::class, 'getDataPercakapan']);
//         Route::post('/reply', [OmniChannelController::class, 'sendReply']);
//         // Route::put('/conversations/{kendaraanId}/read', [OmniChannelController::class, 'markAsRead']);
//     });
//     // Webhook for incoming messages
//     Route::post('/webhook/whatsapp', [OmniChannelController::class, 'webhookIncoming']);
// });


Route::prefix('v1')->group(function () {
    // ============ PUBLIC AUTH ROUTES ============
    Route::prefix('auth')->group(function () {
        Route::post('/register/sanctum', [AuthController::class, 'sanctumRegister']);
        Route::post('/login/sanctum', [AuthController::class, 'sanctumLogin']);
    });

    // ============ SANCTUM AUTHENTICATED ROUTES ============
    Route::middleware('auth:sanctum')->group(function () {
        // Auth
        Route::get('/me', [AuthController::class, 'sanctumMe']);
        Route::post('/logout', [AuthController::class, 'sanctumLogout']);
        Route::post('/logout-all', [AuthController::class, 'sanctumLogoutAllDevices']);
        Route::get('/tokens', [AuthController::class, 'sanctumTokens']);
        Route::delete('/tokens/{tokenId}', [AuthController::class, 'sanctumRevokeToken']);

        // Dashboard
        Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

        // Vehicle Management
        Route::apiResource('kendaraans', KendaraanController::class);
        Route::put('/kendaraans/{id}/status', [KendaraanController::class, 'updateStatus']);
        Route::post('/kendaraans', [KendaraanController::class, 'store']);

        // Broadcast
        Route::post('/send-mass', [BroadcastController::class, 'sendMassBroadcast']);

        // Import routes
        Route::post('/import/excel', [ImportController::class, 'import']);
        Route::post('/import/csv', [ImportController::class, 'importCsv']);
        Route::get('/import/status/{id}', [ImportController::class, 'importStatus']);
        Route::get('/import/template', [ImportController::class, 'downloadTemplate']);

        // Reports
        Route::get('/reports/data', [ReportController::class, 'getReportData']);
        Route::get('/reports', [ReportController::class, 'getReportByDate']);
        Route::get('/reports/excel', [ReportController::class, 'exportFilteredExcel']);

        // Omni Channel
        Route::get('/inbox', [OmniChannelController::class, 'getDataInbox']);
        Route::get('/chat/{dataid}', [OmniChannelController::class, 'getDataPercakapan']);
        Route::post('/reply', [OmniChannelController::class, 'sendReply']);
    });

    // ============ WEBHOOK (PUBLIC) ============
    // Webhook for incoming messages (NO AUTH required)
    Route::post('/webhook/whatsapp', [OmniChannelController::class, 'webhookIncoming']);
});

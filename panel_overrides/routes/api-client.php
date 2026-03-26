<?php

use Pterodactyl\Enum\ResourceLimit;
use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Controllers\Api\Client;
use Pterodactyl\Http\Middleware\Activity\ServerSubject;
use Pterodactyl\Http\Middleware\Activity\AccountSubject;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;
use Pterodactyl\Http\Middleware\Api\Client\Server\ResourceBelongsToServer;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;

/*
|--------------------------------------------------------------------------
| Client Control API
|--------------------------------------------------------------------------
|
| Endpoint: /api/client
|
*/
Route::get('/', [Client\ClientController::class, 'index'])->name('api:client.index');
Route::get('/permissions', [Client\ClientController::class, 'permissions']);
Route::prefix('/chat')->withoutMiddleware('throttle:api')->group(function () {
    Route::get('/conversations', [Client\PublicChatController::class, 'conversations']);
    Route::get('/users', [Client\PublicChatController::class, 'searchUsers'])->middleware('throttle:60,1');
    Route::post('/conversations/private', [Client\PublicChatController::class, 'createPrivate'])->middleware('throttle:30,1');
    Route::post('/conversations/group', [Client\PublicChatController::class, 'createGroup'])->middleware('throttle:20,1');
    Route::patch('/conversations/{conversation}', [Client\PublicChatController::class, 'updateGroup'])->middleware('throttle:30,1');
    Route::post('/conversations/{conversation}/members', [Client\PublicChatController::class, 'addGroupMember'])->middleware('throttle:40,1');
    Route::delete('/conversations/{conversation}/members/{member}', [Client\PublicChatController::class, 'kickGroupMember'])->middleware('throttle:40,1');
    Route::post('/conversations/{conversation}/members/{member}/ban', [Client\PublicChatController::class, 'banGroupMember'])->middleware('throttle:40,1');
    Route::post('/conversations/{conversation}/members/{member}/mute', [Client\PublicChatController::class, 'muteMember'])->middleware('throttle:40,1');
    Route::delete('/conversations/{conversation}/members/{member}/mute', [Client\PublicChatController::class, 'unmuteMember'])->middleware('throttle:40,1');
    Route::post('/conversations/{conversation}/members/{member}/admin', [Client\PublicChatController::class, 'setGroupAdmin'])->middleware('throttle:30,1');
    Route::get('/messages', [Client\PublicChatController::class, 'index']);
    Route::post('/messages', [Client\PublicChatController::class, 'store'])->middleware('throttle:40,1');
    Route::post('/presence', [Client\PublicChatController::class, 'presence'])->middleware('throttle:180,1');
    Route::get('/notifications', [Client\PublicChatController::class, 'notifications']);
    Route::post('/notifications/read', [Client\PublicChatController::class, 'readNotifications']);
    Route::post('/notifications/mute', [Client\PublicChatController::class, 'muteNotifications']);
    Route::post('/notifications/unmute', [Client\PublicChatController::class, 'unmuteNotifications']);
    Route::get('/calls/state', [Client\PublicChatController::class, 'callState']);
    Route::post('/calls/start', [Client\PublicChatController::class, 'startCall'])->middleware('throttle:30,1');
    Route::post('/calls/join', [Client\PublicChatController::class, 'joinCall']);
    Route::post('/calls/leave', [Client\PublicChatController::class, 'leaveCall']);
    Route::post('/calls/end', [Client\PublicChatController::class, 'endCall'])->middleware('throttle:40,1');
    Route::post('/calls/signal', [Client\PublicChatController::class, 'callSignal']);
    Route::post('/calls/mic', [Client\PublicChatController::class, 'callMic']);
    Route::post('/polls', [Client\PublicChatController::class, 'storePoll'])->middleware('throttle:20,1');
    Route::post('/polls/{message}/vote', [Client\PublicChatController::class, 'votePoll'])->middleware('throttle:60,1');
    Route::post('/messages/{message}/reactions', [Client\PublicChatController::class, 'react'])->middleware('throttle:80,1');
    Route::patch('/messages/{message}', [Client\PublicChatController::class, 'update'])->middleware('throttle:40,1');
    Route::delete('/messages/{message}', [Client\PublicChatController::class, 'destroy'])->middleware('throttle:40,1');
    Route::post('/read', [Client\PublicChatController::class, 'markRead']);
    Route::post('/upload', [Client\PublicChatController::class, 'upload'])->middleware('throttle:20,1');
});

Route::prefix('/danexcoin')->group(function () {
    Route::get('/', [Client\DanexCoinController::class, 'index']);
    Route::post('/spin', [Client\DanexCoinController::class, 'spin'])->middleware('throttle:240,1');
});

Route::prefix('/create-panel')->group(function () {
    Route::get('/options', [Client\CreatePanelController::class, 'options']);
    Route::post('/create', [Client\CreatePanelController::class, 'create'])->middleware('throttle:10,1');
});

Route::prefix('/account')->middleware(AccountSubject::class)->group(function () {
    Route::prefix('/')->withoutMiddleware(RequireTwoFactorAuthentication::class)->group(function () {
        Route::get('/', [Client\AccountController::class, 'index'])->name('api:client.account');
        Route::get('/two-factor', [Client\TwoFactorController::class, 'index']);
        Route::post('/two-factor', [Client\TwoFactorController::class, 'store']);
        Route::post('/two-factor/disable', [Client\TwoFactorController::class, 'delete']);
    });

    Route::put('/email', [Client\AccountController::class, 'updateEmail'])->name('api:client.account.update-email');
    Route::put('/profile', [Client\AccountController::class, 'updateProfile'])->name('api:client.account.update-profile');
    Route::post('/profile/avatar', [Client\AccountController::class, 'uploadAvatar'])->name('api:client.account.upload-avatar');
    Route::put('/password', [Client\AccountController::class, 'updatePassword'])->name('api:client.account.update-password');

    Route::get('/activity', Client\ActivityLogController::class)->name('api:client.account.activity');

    Route::get('/api-keys', [Client\ApiKeyController::class, 'index']);
    Route::post('/api-keys', [Client\ApiKeyController::class, 'store']);
    Route::delete('/api-keys/{identifier}', [Client\ApiKeyController::class, 'delete']);

    Route::prefix('/ssh-keys')->group(function () {
        Route::get('/', [Client\SSHKeyController::class, 'index']);
        Route::post('/', [Client\SSHKeyController::class, 'store']);
        Route::post('/remove', [Client\SSHKeyController::class, 'delete']);
    });
});

/*
|--------------------------------------------------------------------------
| Client Control API
|--------------------------------------------------------------------------
|
| Endpoint: /api/client/servers/{server}
|
*/
Route::group([
    'prefix' => '/servers/{server}',
    'middleware' => [
        ServerSubject::class,
        AuthenticateServerAccess::class,
        ResourceBelongsToServer::class,
    ],
], function () {
    Route::get('/', [Client\Servers\ServerController::class, 'index'])->name('api:client:server.view');
    Route::middleware([ResourceLimit::Websocket->middleware()])
        ->get('/websocket', Client\Servers\WebsocketController::class)
        ->name('api:client:server.ws');
    Route::get('/resources', Client\Servers\ResourceUtilizationController::class)->name('api:client:server.resources');
    Route::get('/activity', Client\Servers\ActivityLogController::class)->name('api:client:server.activity');

    Route::post('/command', [Client\Servers\CommandController::class, 'index']);
    Route::post('/power', [Client\Servers\PowerController::class, 'index']);

    Route::group(['prefix' => '/databases'], function () {
        Route::get('/', [Client\Servers\DatabaseController::class, 'index']);
        Route::middleware([ResourceLimit::Database->middleware()])
            ->post('/', [Client\Servers\DatabaseController::class, 'store']);
        Route::post('/{database}/rotate-password', [Client\Servers\DatabaseController::class, 'rotatePassword']);
        Route::delete('/{database}', [Client\Servers\DatabaseController::class, 'delete']);
    });

    Route::group(['prefix' => '/files'], function () {
        Route::get('/list', [Client\Servers\FileController::class, 'directory']);
        Route::get('/contents', [Client\Servers\FileController::class, 'contents']);
        Route::get('/download', [Client\Servers\FileController::class, 'download']);
        Route::put('/rename', [Client\Servers\FileController::class, 'rename']);
        Route::post('/copy', [Client\Servers\FileController::class, 'copy']);
        Route::post('/write', [Client\Servers\FileController::class, 'write']);
        Route::post('/compress', [Client\Servers\FileController::class, 'compress']);
        Route::post('/decompress', [Client\Servers\FileController::class, 'decompress']);
        Route::post('/delete', [Client\Servers\FileController::class, 'delete']);
        Route::post('/create-folder', [Client\Servers\FileController::class, 'create']);
        Route::post('/chmod', [Client\Servers\FileController::class, 'chmod']);
        Route::middleware([ResourceLimit::FilePull->middleware()])
            ->post('/pull', [Client\Servers\FileController::class, 'pull']);
        Route::get('/upload', Client\Servers\FileUploadController::class);
    });

    Route::group(['prefix' => '/schedules'], function () {
        Route::get('/', [Client\Servers\ScheduleController::class, 'index']);
        Route::middleware([ResourceLimit::Schedule->middleware()])
            ->post('/', [Client\Servers\ScheduleController::class, 'store']);
        Route::get('/{schedule}', [Client\Servers\ScheduleController::class, 'view']);
        Route::post('/{schedule}', [Client\Servers\ScheduleController::class, 'update']);
        Route::post('/{schedule}/execute', [Client\Servers\ScheduleController::class, 'execute']);
        Route::delete('/{schedule}', [Client\Servers\ScheduleController::class, 'delete']);

        Route::post('/{schedule}/tasks', [Client\Servers\ScheduleTaskController::class, 'store']);
        Route::post('/{schedule}/tasks/{task}', [Client\Servers\ScheduleTaskController::class, 'update']);
        Route::delete('/{schedule}/tasks/{task}', [Client\Servers\ScheduleTaskController::class, 'delete']);
    });

    Route::group(['prefix' => '/network'], function () {
        Route::get('/allocations', [Client\Servers\NetworkAllocationController::class, 'index']);
        Route::middleware([ResourceLimit::Allocation->middleware()])
            ->post('/allocations', [Client\Servers\NetworkAllocationController::class, 'store']);
        Route::post('/allocations/{allocation}', [Client\Servers\NetworkAllocationController::class, 'update']);
        Route::post('/allocations/{allocation}/primary', [Client\Servers\NetworkAllocationController::class, 'setPrimary']);
        Route::delete('/allocations/{allocation}', [Client\Servers\NetworkAllocationController::class, 'delete']);
    });

    Route::group(['prefix' => '/users'], function () {
        Route::get('/', [Client\Servers\SubuserController::class, 'index']);
        Route::middleware([ResourceLimit::Subuser->middleware()])
            ->post('/', [Client\Servers\SubuserController::class, 'store']);
        Route::get('/{user}', [Client\Servers\SubuserController::class, 'view']);
        Route::post('/{user}', [Client\Servers\SubuserController::class, 'update']);
        Route::delete('/{user}', [Client\Servers\SubuserController::class, 'delete']);
    });

    Route::group(['prefix' => '/backups'], function () {
        Route::get('/', [Client\Servers\BackupController::class, 'index']);
        Route::post('/', [Client\Servers\BackupController::class, 'store']);
        Route::get('/{backup}', [Client\Servers\BackupController::class, 'view']);
        Route::get('/{backup}/download', [Client\Servers\BackupController::class, 'download']);
        Route::post('/{backup}/lock', [Client\Servers\BackupController::class, 'toggleLock']);
        Route::middleware([ResourceLimit::Backup->middleware()])
            ->post('/{backup}/restore', [Client\Servers\BackupController::class, 'restore']);
        Route::delete('/{backup}', [Client\Servers\BackupController::class, 'delete']);
    });

    Route::group(['prefix' => '/startup'], function () {
        Route::get('/', [Client\Servers\StartupController::class, 'index']);
        Route::put('/variable', [Client\Servers\StartupController::class, 'update']);
    });

    Route::group(['prefix' => '/settings'], function () {
        Route::post('/rename', [Client\Servers\SettingsController::class, 'rename']);
        Route::post('/reinstall', [Client\Servers\SettingsController::class, 'reinstall']);
        Route::put('/docker-image', [Client\Servers\SettingsController::class, 'dockerImage']);
    });
});

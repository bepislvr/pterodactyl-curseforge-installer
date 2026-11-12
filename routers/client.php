<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;
use {appcontext}\ModpackInstallerController;

// Blueprint mounts this file under /api/client/extensions/modpackinstaller (routes/blueprint/client/modpackinstaller.php
// inside Route::prefix('/modpackinstaller'), itself under /api/client/extensions) with the middleware groups
// blueprint/api and blueprint/client-api (client bindings, so {server} resolves by uuid / uuidShort).
//   POST /api/client/extensions/modpackinstaller/servers/{server}/resolve
//   POST /api/client/extensions/modpackinstaller/servers/{server}/install
Route::group([
    'prefix' => '/servers/{server}',
    'middleware' => [AuthenticateServerAccess::class],
], function () {
    Route::post('/resolve', [ModpackInstallerController::class, 'resolve']);
    Route::post('/install', [ModpackInstallerController::class, 'install']);
});

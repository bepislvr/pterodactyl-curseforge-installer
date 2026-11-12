<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Controllers\Api\Client\Extensions\ModpackInstaller\ModpackInstallerController;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;

// Server-scoped endpoints. Final URL shape depends on how Blueprint mounts client routers
// (see CLAUDE.md, "Unverified assumptions"). The frontend expects:
//   POST /api/client/extensions/modpackinstaller/servers/{server}/resolve
//   POST /api/client/extensions/modpackinstaller/servers/{server}/install
Route::group([
    'prefix' => '/servers/{server}',
    'middleware' => [AuthenticateServerAccess::class],
], function () {
    Route::post('/resolve', [ModpackInstallerController::class, 'resolve']);
    Route::post('/install', [ModpackInstallerController::class, 'install']);
});

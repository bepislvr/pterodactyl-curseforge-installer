<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Extensions\ModpackInstaller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Models\Permission;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Services\ModpackInstaller\CurseForgeClient;
use Pterodactyl\Services\ModpackInstaller\CurseForgeException;

class ModpackInstallerController extends ClientApiController
{
    public function __construct(
        private CurseForgeClient $curseforge,
        private DaemonFileRepository $files,
    ) {
        parent::__construct();
    }

    /** Look up a modpack by ID/URL/slug and list installable (server pack) versions. */
    public function resolve(Request $request, Server $server): JsonResponse
    {
        $this->requirePermission($request, $server);
        $data = $request->validate(['query' => 'required|string|max:255']);

        try {
            $mod = $this->curseforge->resolveModpack($data['query']);
            $versions = $this->curseforge->serverPackFiles($mod['id']);
        } catch (CurseForgeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        if (empty($versions)) {
            return new JsonResponse([
                'error' => 'This modpack has no server pack. Only packs with an official server pack are supported.',
            ], 422);
        }

        return new JsonResponse(['modpack' => $mod, 'versions' => $versions]);
    }

    /** Download the server pack into the server's root and extract it. */
    public function install(Request $request, Server $server): JsonResponse
    {
        $this->requirePermission($request, $server);
        $data = $request->validate([
            'mod_id' => 'required|integer|min:1',
            'server_pack_file_id' => 'required|integer|min:1',
        ]);

        try {
            $download = $this->curseforge->serverPackDownload($data['mod_id'], $data['server_pack_file_id']);
        } catch (CurseForgeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        // Only allow CurseForge CDN hosts so the panel never pulls from an arbitrary URL.
        $host = parse_url($download['url'], PHP_URL_HOST) ?: '';
        if (!preg_match('/(^|\.)(forgecdn\.net|curseforge\.com)$/i', $host)) {
            return new JsonResponse(['error' => 'Unexpected download host: ' . $host], 422);
        }

        $fileName = basename($download['fileName']);
        $repo = $this->files->setServer($server);

        // foreground=true blocks until Wings finishes the download, so we can decompress right after.
        // TODO: large packs may exceed the HTTP timeout; consider polling Wings pull status instead.
        $repo->pull($download['url'], '/', ['filename' => $fileName, 'use_header' => false, 'foreground' => true]);
        $repo->decompressFile('/', $fileName);

        return new JsonResponse(['status' => 'installed', 'file' => $fileName]);
    }

    private function requirePermission(Request $request, Server $server): void
    {
        if (!$request->user()->can(Permission::ACTION_FILE_CREATE, $server)) {
            abort(403, 'You do not have permission to create files on this server.');
        }
    }
}

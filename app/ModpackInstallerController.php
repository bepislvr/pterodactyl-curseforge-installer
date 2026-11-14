<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Models\Permission;
use Pterodactyl\Models\Server;

class ModpackInstallerController extends ClientApiController
{
    public function __construct(
        private CurseForgeClient $curseforge,
        private WingsPackInstaller $installer,
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
            return $this->curseForgeError($e);
        }

        if (empty($versions)) {
            return new JsonResponse([
                'error' => 'This modpack has no server pack. Only packs with an official server pack are supported.',
            ], 422);
        }

        return $this->noStore(new JsonResponse(['modpack' => $mod, 'versions' => $versions]));
    }

    /** Browse/search modpacks (most popular first). Paginated with index/page_size. */
    public function search(Request $request, Server $server): JsonResponse
    {
        $this->requirePermission($request, $server);
        $data = $request->validate([
            'query' => 'nullable|string|max:100',
            'index' => 'nullable|integer|min:0|max:9950',
            'page_size' => 'nullable|integer|min:1|max:50',
        ]);

        try {
            $result = $this->curseforge->searchModpacks(
                $data['query'] ?? '',
                (int) ($data['index'] ?? 0),
                (int) ($data['page_size'] ?? 20)
            );
        } catch (CurseForgeException $e) {
            return $this->curseForgeError($e);
        }

        return $this->noStore(new JsonResponse($result));
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
            return $this->curseForgeError($e);
        }

        // serverPackDownload() already checked every redirect hop; check the final URL again before it reaches Wings.
        if (!CurseForgeClient::isAllowedDownloadUrl($download['url'])) {
            return new JsonResponse([
                'error' => 'Refusing to download from an unexpected location: ' . CurseForgeClient::describeUrl($download['url']),
            ], 422);
        }

        $fileName = basename($download['fileName']);

        // A large pack can take longer than PHP's execution limit.
        @set_time_limit(900);

        try {
            $this->installer->pullAndExtract($server, $download['url'], $fileName);
        } catch (InstallException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['status' => 'installed', 'file' => $fileName]);
    }

    /**
     * CurseForge errors as {"error": ...} with HTTP 422. When the author has disabled third-party distribution,
     * the project's CurseForge page is included so the UI can link to it for a manual download.
     */
    private function curseForgeError(CurseForgeException $e): JsonResponse
    {
        $body = ['error' => $e->getMessage()];
        if ($e instanceof DistributionDisabledException) {
            $body['distributionDisabled'] = true;
            $body['projectUrl'] = $e->projectUrl;
        }

        return $this->noStore(new JsonResponse($body, 422));
    }

    /** CurseForge data must not be saved or cached (API terms 3.1): tell browsers and proxies not to store it. */
    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function requirePermission(Request $request, Server $server): void
    {
        if (!$request->user()->can(Permission::ACTION_FILE_CREATE, $server)) {
            abort(403, 'You do not have permission to create files on this server.');
        }
    }
}

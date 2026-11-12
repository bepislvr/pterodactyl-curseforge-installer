<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Models\Permission;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

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
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse($result);
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

        // Only allow CurseForge CDN hosts over https so the panel never pulls from an arbitrary URL.
        $scheme = strtolower((string) parse_url($download['url'], PHP_URL_SCHEME));
        $host = parse_url($download['url'], PHP_URL_HOST) ?: '';
        if ($scheme !== 'https' || !preg_match('/(^|\.)(forgecdn\.net|curseforge\.com)$/i', $host)) {
            return new JsonResponse(['error' => 'Refusing to download from an unexpected location: ' . $scheme . '://' . $host], 422);
        }

        $fileName = basename($download['fileName']);
        $repo = $this->files->setServer($server);

        // A large pack can take longer than PHP's execution limit.
        @set_time_limit(900);

        // DaemonFileRepository::pull() uses the panel's Guzzle timeout (GUZZLE_TIMEOUT, default 15s),
        // which is too short for a foreground download. Same Wings endpoint, per-request timeout.
        try {
            $repo->getHttpClient()->post(
                sprintf('/api/servers/%s/files/pull', $server->uuid),
                [
                    'json' => [
                        'url' => $download['url'],
                        'root' => '/',
                        'file_name' => $fileName,
                        'use_header' => false,
                        'foreground' => true,
                    ],
                    'timeout' => 600,
                ]
            );
        } catch (GuzzleException $e) {
            return new JsonResponse(['error' => 'Download failed: ' . $this->wingsMessage($e)], 422);
        }

        try {
            $repo->decompressFile('/', $fileName);
        } catch (DaemonConnectionException $e) {
            // DaemonConnectionException wraps the Guzzle error; read Wings' own message from it when there is one.
            $prev = $e->getPrevious();
            $msg = $prev instanceof GuzzleException ? $this->wingsMessage($prev) : $e->getMessage();

            return new JsonResponse(['error' => 'Extraction failed: ' . $msg], 422);
        }

        return new JsonResponse(['status' => 'installed', 'file' => $fileName]);
    }

    /** Pull a human message out of a Wings error response (it uses "error" or "message"). */
    private function wingsMessage(GuzzleException $e): string
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            $body = json_decode((string) $e->getResponse()->getBody(), true);
            $msg = is_array($body) ? ($body['error'] ?? $body['message'] ?? null) : null;
            if ($msg) {
                return (string) $msg;
            }
        }

        return 'could not complete the transfer on the node. Please try again.';
    }

    private function requirePermission(Request $request, Server $server): void
    {
        if (!$request->user()->can(Permission::ACTION_FILE_CREATE, $server)) {
            abort(403, 'You do not have permission to create files on this server.');
        }
    }
}

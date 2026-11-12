<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Client\BlueprintClientLibrary as BlueprintExtensionLibrary;

/**
 * Thin wrapper around the CurseForge Core API (https://docs.curseforge.com).
 * Scope: modpacks that ship a server pack only.
 */
class CurseForgeClient
{
    private const BASE_URL = 'https://api.curseforge.com';
    private const GAME_ID_MINECRAFT = 432;
    private const CLASS_ID_MODPACKS = 4471;

    public function __construct(private BlueprintExtensionLibrary $blueprint)
    {
    }

    private function http(): PendingRequest
    {
        $key = (string) $this->blueprint->dbGet('modpackinstaller', 'api_key');
        if ($key === '') {
            throw new CurseForgeException('No CurseForge API key is configured. Ask an administrator to set one.');
        }

        return Http::baseUrl(self::BASE_URL)
            ->withHeaders(['x-api-key' => $key, 'Accept' => 'application/json'])
            ->timeout(20);
    }

    /** GET with uniform error handling. Throws CurseForgeException on connection problems and 401/403. */
    private function get(string $path, array $query = []): Response
    {
        try {
            $res = $this->http()->get($path, $query);
        } catch (ConnectionException $e) {
            throw new CurseForgeException('Could not reach the CurseForge API. Please try again.');
        }

        if (in_array($res->status(), [401, 403], true)) {
            throw new CurseForgeException('CurseForge rejected the API key (HTTP ' . $res->status() . '). Ask an administrator to check it.');
        }

        return $res;
    }

    /**
     * Search Minecraft modpacks, most popular first.
     *
     * `hasServerPack` is true when any of the pack's latestFiles (a short list CurseForge returns with the
     * search hit, not the full file history) carries a serverPackFileId. False means "none seen", not "none exist".
     *
     * @return array{results:array<int,array<string,mixed>>,index:int,pageSize:int,totalCount:int,hasMore:bool}
     */
    public function searchModpacks(string $filter, int $index, int $pageSize): array
    {
        $query = [
            'gameId' => self::GAME_ID_MINECRAFT,
            'classId' => self::CLASS_ID_MODPACKS,
            'sortField' => 2, // Popularity
            'sortOrder' => 'desc',
            'index' => $index,
            'pageSize' => $pageSize,
        ];
        $filter = trim($filter);
        if ($filter !== '') {
            $query['searchFilter'] = $filter;
        }

        $res = $this->get('/v1/mods/search', $query);
        if (!$res->ok()) {
            throw new CurseForgeException('Search failed (CurseForge returned ' . $res->status() . ').');
        }

        $results = [];
        foreach ($res->json('data') ?? [] as $mod) {
            $thumb = $mod['logo']['thumbnailUrl'] ?? null;
            $hasServerPack = false;
            foreach ($mod['latestFiles'] ?? [] as $file) {
                if (!empty($file['serverPackFileId'])) {
                    $hasServerPack = true;
                    break;
                }
            }
            $results[] = [
                'id' => (int) $mod['id'],
                'name' => (string) $mod['name'],
                'slug' => (string) $mod['slug'],
                'summary' => (string) ($mod['summary'] ?? ''),
                'thumbnailUrl' => is_string($thumb) && str_starts_with($thumb, 'https://') ? $thumb : null,
                'downloadCount' => (int) ($mod['downloadCount'] ?? 0),
                'hasServerPack' => $hasServerPack,
            ];
        }

        $page = $res->json('pagination') ?? [];
        $total = (int) ($page['totalCount'] ?? count($results));

        return [
            'results' => $results,
            'index' => $index,
            'pageSize' => $pageSize,
            'totalCount' => $total,
            // CurseForge caps index + pageSize at 10,000.
            'hasMore' => $index + count($results) < $total && $index + 2 * $pageSize <= 10000,
        ];
    }

    /**
     * Accepts a numeric project ID, a CurseForge modpack URL, or a bare slug.
     *
     * @return array{id:int,name:string,slug:string}
     */
    public function resolveModpack(string $reference): array
    {
        $reference = trim($reference);

        if (preg_match('/^\d+$/', $reference)) {
            $res = $this->get('/v1/mods/' . $reference);
            if (!$res->ok()) {
                throw new CurseForgeException('Modpack not found (CurseForge returned ' . $res->status() . ').');
            }
            $mod = $res->json('data');
            if (isset($mod['classId']) && (int) $mod['classId'] !== self::CLASS_ID_MODPACKS) {
                throw new CurseForgeException('Project ' . $reference . ' is not a Minecraft modpack.');
            }
        } else {
            $slug = $reference;
            if (preg_match('#curseforge\.com/minecraft/modpacks/([a-z0-9\-_]+)#i', $reference, $m)) {
                $slug = $m[1];
            }
            $res = $this->get('/v1/mods/search', [
                'gameId' => self::GAME_ID_MINECRAFT,
                'classId' => self::CLASS_ID_MODPACKS,
                'slug' => strtolower($slug),
            ]);
            if (!$res->ok() || empty($res->json('data'))) {
                throw new CurseForgeException('No modpack found for "' . $reference . '".');
            }
            $mod = $res->json('data')[0];
        }

        return ['id' => (int) $mod['id'], 'name' => $mod['name'], 'slug' => $mod['slug']];
    }

    /**
     * Files of a modpack that have a server pack attached, newest first.
     *
     * @return array<int,array{fileId:int,serverPackFileId:int,displayName:string,gameVersions:array,date:string}>
     */
    public function serverPackFiles(int $modId): array
    {
        $res = $this->get("/v1/mods/{$modId}/files", ['pageSize' => 50]);
        if (!$res->ok()) {
            throw new CurseForgeException('Could not list files (CurseForge returned ' . $res->status() . ').');
        }

        $out = [];
        foreach ($res->json('data') ?? [] as $file) {
            if (!empty($file['serverPackFileId']) && ($file['isAvailable'] ?? true)) {
                $out[] = [
                    'fileId' => (int) $file['id'],
                    'serverPackFileId' => (int) $file['serverPackFileId'],
                    'displayName' => $file['displayName'],
                    'gameVersions' => $file['gameVersions'] ?? [],
                    'date' => $file['fileDate'] ?? '',
                ];
            }
        }
        usort($out, fn ($a, $b) => strcmp($b['date'], $a['date']));

        return $out;
    }

    /**
     * Download info for a server pack file.
     *
     * @return array{url:string,fileName:string}
     */
    public function serverPackDownload(int $modId, int $serverPackFileId): array
    {
        $res = $this->get("/v1/mods/{$modId}/files/{$serverPackFileId}");
        if (!$res->ok()) {
            throw new CurseForgeException('Server pack file not found (CurseForge returned ' . $res->status() . ').');
        }
        $file = $res->json('data');
        $url = $file['downloadUrl'] ?? null;

        if (!$url) {
            // Authors can disable third-party downloads, which hides downloadUrl. Try the dedicated
            // endpoint before giving up.
            $dl = $this->get("/v1/mods/{$modId}/files/{$serverPackFileId}/download-url");
            $url = $dl->ok() ? $dl->json('data') : null;
        }

        if (!$url) {
            throw new CurseForgeException(
                'The author has disabled third-party downloads for this server pack. Download it manually from CurseForge.'
            );
        }

        return ['url' => $url, 'fileName' => $file['fileName']];
    }
}

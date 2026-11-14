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
 *
 * CurseForge 3rd Party API Terms, section 3.1: nothing obtained through the API may be saved or cached.
 * This class must not write API data to the cache, database, session, files or logs, and must not retry
 * requests automatically. Every call is made on an explicit user action and the result is only returned.
 */
class CurseForgeClient
{
    private const BASE_URL = 'https://api.curseforge.com';

    /**
     * The only place these IDs are defined. They are not stated in the CurseForge docs (seen in third-party
     * usage only); checkIds() verifies them against the live API when an admin saves a key.
     */
    public const GAME_ID_MINECRAFT = 432;
    public const CLASS_ID_MODPACKS = 4471;
    public const GAME_SLUG_MINECRAFT = 'minecraft';
    public const CLASS_SLUG_MODPACKS = 'modpacks';
    private const MAX_CDN_REDIRECTS = 3;

    public function __construct(private BlueprintExtensionLibrary $blueprint)
    {
    }

    private function http(?string $key = null): PendingRequest
    {
        $key ??= (string) $this->blueprint->dbGet('modpackinstaller', 'api_key');
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

        if ($res->status() === 429) {
            throw new CurseForgeException(self::rateLimitMessage($res));
        }
        if (in_array($res->status(), [401, 403], true)) {
            throw new CurseForgeException(
                'CurseForge refused the request (HTTP ' . $res->status() . '). The API key may be invalid or its access may be limited. '
                . 'Ask an administrator to check it.'
            );
        }

        return $res;
    }

    /**
     * Message for HTTP 429. Includes CurseForge's Retry-After value when it sent one. The request is never
     * retried automatically: the user decides when to try again.
     */
    public static function rateLimitMessage(Response $res): string
    {
        $msg = 'CurseForge is limiting requests for this API key (HTTP 429).';
        $retryAfter = trim((string) $res->header('Retry-After'));
        if (preg_match('/^\d{1,6}$/', $retryAfter)) {
            return $msg . ' Please wait ' . (int) $retryAfter . ' seconds before trying again.';
        }
        // The header may also be an HTTP date. Only show it if it is plain text of a sane length.
        if ($retryAfter !== '' && preg_match('/^[A-Za-z0-9 ,:+\-]{1,40}$/', $retryAfter)) {
            return $msg . ' Please try again after ' . $retryAfter . '.';
        }

        return $msg . ' Please wait a few minutes before trying again.';
    }

    /**
     * Names of a project's authors, as plain strings.
     *
     * @return array<int,string>
     */
    private static function authorNames(mixed $mod): array
    {
        $names = [];
        foreach ((is_array($mod) && is_array($mod['authors'] ?? null)) ? $mod['authors'] : [] as $author) {
            $name = is_array($author) ? self::text($author['name'] ?? '') : '';
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Link to the project's own page on curseforge.com, for credit and for manual downloads.
     * Uses the URL CurseForge returned if it is an https curseforge.com URL, otherwise builds it from the slug.
     */
    public static function projectUrl(mixed $mod): ?string
    {
        $url = is_array($mod) && is_array($mod['links'] ?? null) ? ($mod['links']['websiteUrl'] ?? null) : null;
        if (is_string($url)
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && preg_match('/(^|\.)curseforge\.com$/i', (string) (parse_url($url, PHP_URL_HOST) ?: '')) === 1) {
            return $url;
        }
        $slug = is_array($mod) && is_scalar($mod['slug'] ?? null) ? (string) $mod['slug'] : '';
        if (preg_match('/^[a-z0-9][a-z0-9\-_]*$/i', $slug)) {
            return 'https://www.curseforge.com/minecraft/modpacks/' . $slug;
        }

        return null;
    }

    /**
     * Authors can switch off distribution through third-party services (the "project distribution toggle").
     * The API reports it as allowModDistribution (boolean or null). Only an explicit false means "disabled".
     */
    public static function distributionDisabled(mixed $mod): bool
    {
        return is_array($mod) && array_key_exists('allowModDistribution', $mod) && $mod['allowModDistribution'] === false;
    }

    private static function distributionDisabledException(mixed $mod): DistributionDisabledException
    {
        return new DistributionDisabledException(
            'The author of this modpack has disabled downloads through third-party tools. '
            . 'Download the server pack manually from its CurseForge page.',
            self::projectUrl($mod)
        );
    }

    /**
     * Check the hardcoded game and class IDs against CurseForge: game GAME_ID_MINECRAFT must be Minecraft and
     * CLASS_ID_MODPACKS must be a class of that game named Modpacks. Never throws; every failure (bad key,
     * network, unexpected data) is reported in the result.
     *
     * @param string|null $apiKey key to test (e.g. one just entered by an admin); null uses the stored key
     */
    public function checkIds(?string $apiKey = null): IdCheckResult
    {
        $game = $this->checkRequest('/v1/games/' . self::GAME_ID_MINECRAFT, [], $apiKey);
        if ($game instanceof IdCheckResult) {
            return $game;
        }
        $data = $game->json('data');
        if (!is_array($data) || !isset($data['id'])) {
            return IdCheckResult::unexpected('/v1/games/' . self::GAME_ID_MINECRAFT . ' returned no game data.');
        }
        $gameName = self::text($data['name'] ?? '');
        $gameSlug = self::text($data['slug'] ?? '');
        if ((int) $data['id'] !== self::GAME_ID_MINECRAFT
            || (strtolower($gameSlug) !== self::GAME_SLUG_MINECRAFT && strtolower($gameName) !== 'minecraft')) {
            return IdCheckResult::mismatch(sprintf(
                'Game %d is "%s" (slug "%s", id %d), not Minecraft.',
                self::GAME_ID_MINECRAFT, $gameName, $gameSlug, (int) $data['id']
            ));
        }

        $cats = $this->checkRequest('/v1/categories', ['gameId' => self::GAME_ID_MINECRAFT, 'classesOnly' => 'true'], $apiKey);
        if ($cats instanceof IdCheckResult) {
            return $cats;
        }
        $classes = $cats->json('data');
        if (!is_array($classes)) {
            return IdCheckResult::unexpected('/v1/categories returned no class list.');
        }

        $match = null;
        $modpacksElsewhere = null;
        foreach ($classes as $c) {
            if (!is_array($c) || !isset($c['id'])) {
                continue;
            }
            if ((int) $c['id'] === self::CLASS_ID_MODPACKS) {
                $match = $c;
            } elseif (strtolower(self::text($c['slug'] ?? '')) === self::CLASS_SLUG_MODPACKS) {
                $modpacksElsewhere = $c;
            }
        }

        if ($match === null) {
            $msg = sprintf('Class %d is not among the %d classes CurseForge lists for game %d.',
                self::CLASS_ID_MODPACKS, count($classes), self::GAME_ID_MINECRAFT);
            if ($modpacksElsewhere !== null) {
                $msg .= sprintf(' A class "%s" exists with id %d.', self::text($modpacksElsewhere['name'] ?? ''), (int) $modpacksElsewhere['id']);
            }

            return IdCheckResult::mismatch($msg);
        }

        $className = self::text($match['name'] ?? '');
        $classSlug = self::text($match['slug'] ?? '');
        $problems = [];
        if (strtolower($classSlug) !== self::CLASS_SLUG_MODPACKS && strtolower($className) !== 'modpacks') {
            $problems[] = sprintf('is named "%s" (slug "%s"), expected "Modpacks"', $className, $classSlug);
        }
        if (array_key_exists('isClass', $match) && $match['isClass'] !== true) {
            $problems[] = 'is not marked as a class (isClass is false)';
        }
        if (isset($match['gameId']) && (int) $match['gameId'] !== self::GAME_ID_MINECRAFT) {
            $problems[] = sprintf('belongs to game %d', (int) $match['gameId']);
        }
        if ($problems !== []) {
            $msg = sprintf('Class %d %s.', self::CLASS_ID_MODPACKS, implode(', ', $problems));
            if ($modpacksElsewhere !== null) {
                $msg .= sprintf(' A class "%s" exists with id %d.', self::text($modpacksElsewhere['name'] ?? ''), (int) $modpacksElsewhere['id']);
            }

            return IdCheckResult::mismatch($msg);
        }

        return IdCheckResult::ok(sprintf(
            'Game %d is %s and class %d is %s.', self::GAME_ID_MINECRAFT, $gameName, self::CLASS_ID_MODPACKS, $className
        ));
    }

    /** A field from a CurseForge response as a short string, whatever type it arrived as. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? mb_substr((string) $value, 0, 100) : '';
    }

    /** One request for checkIds(): the response if it is a 200, otherwise the failure as a result. */
    private function checkRequest(string $path, array $query, ?string $apiKey): Response|IdCheckResult
    {
        try {
            // Short timeouts: this runs while the admin's save request waits.
            $res = $this->http($apiKey)->timeout(10)->connectTimeout(5)->get($path, $query);
        } catch (CurseForgeException $e) {
            return IdCheckResult::failed(IdCheckResult::NO_KEY, $e->getMessage());
        } catch (ConnectionException $e) {
            return IdCheckResult::failed(IdCheckResult::UNREACHABLE, 'Could not reach the CurseForge API to check the IDs.');
        }

        if (in_array($res->status(), [401, 403], true)) {
            return IdCheckResult::failed(IdCheckResult::AUTH, sprintf(
                'CurseForge rejected the API key (HTTP %d on %s). The key was saved, but searches and installs will fail until it is fixed.',
                $res->status(), $path
            ));
        }
        if ($res->status() === 429) {
            return IdCheckResult::failed(IdCheckResult::HTTP_ERROR, self::rateLimitMessage($res));
        }
        if (!$res->ok()) {
            return IdCheckResult::failed(IdCheckResult::HTTP_ERROR, sprintf('CurseForge returned HTTP %d for %s.', $res->status(), $path));
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
                'authors' => self::authorNames($mod),
                'projectUrl' => self::projectUrl($mod),
                'distributionDisabled' => self::distributionDisabled($mod),
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
     * Refuses (DistributionDisabledException) when the author has disabled third-party distribution.
     *
     * @return array{id:int,name:string,slug:string,authors:array<int,string>,projectUrl:?string}
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
                throw new CurseForgeException(
                    'No modpack found for "' . $reference . '". It may not exist, or its author may not allow third-party tools.'
                );
            }
            $mod = $res->json('data')[0];
        }

        if (self::distributionDisabled($mod)) {
            throw self::distributionDisabledException($mod);
        }

        return [
            'id' => (int) $mod['id'],
            'name' => $mod['name'],
            'slug' => $mod['slug'],
            'authors' => self::authorNames($mod),
            'projectUrl' => self::projectUrl($mod),
        ];
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
     * The author's distribution setting is checked again here, at install time: the project is fetched and an
     * explicit allowModDistribution=false is refused before the file is even looked up. If CurseForge then
     * withholds the file's downloadUrl, that is treated as the author's refusal as well. No other way of
     * obtaining the file is tried.
     *
     * @return array{url:string,fileName:string}
     */
    public function serverPackDownload(int $modId, int $serverPackFileId): array
    {
        $modRes = $this->get("/v1/mods/{$modId}");
        if (!$modRes->ok()) {
            throw new CurseForgeException('Modpack not found (CurseForge returned ' . $modRes->status() . ').');
        }
        $mod = $modRes->json('data');
        if (self::distributionDisabled($mod)) {
            throw self::distributionDisabledException($mod);
        }

        $res = $this->get("/v1/mods/{$modId}/files/{$serverPackFileId}");
        if (!$res->ok()) {
            throw new CurseForgeException('Server pack file not found (CurseForge returned ' . $res->status() . ').');
        }
        $file = $res->json('data');
        $url = is_array($file) ? ($file['downloadUrl'] ?? null) : null;

        if (!is_string($url) || $url === '') {
            throw self::distributionDisabledException($mod);
        }

        return ['url' => $this->resolveCdnUrl($url), 'fileName' => $file['fileName']];
    }

    /** https and a CurseForge host. The only URLs ever handed to Wings. */
    public static function isAllowedDownloadUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');

        return $scheme === 'https' && preg_match('/(^|\.)(forgecdn\.net|curseforge\.com)$/i', $host) === 1;
    }

    /** scheme://host of a URL, for error messages. */
    public static function describeUrl(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) . '://' . (parse_url($url, PHP_URL_HOST) ?: '');
    }

    /**
     * Follow CDN redirects on the panel side and return the final URL.
     *
     * Wings never follows redirects (it fails with "got bad response status from endpoint: 302 Found"), and
     * edge.forgecdn.net answers every file with a 302 to mediafilez.forgecdn.net. Every hop must pass
     * isAllowedDownloadUrl(), so a redirect off the CurseForge CDN is refused, not followed.
     */
    public function resolveCdnUrl(string $url): string
    {
        // Wings parses the URL with Go's net/url; CurseForge file names can contain spaces.
        $url = str_replace(' ', '%20', $url);

        for ($hop = 0; $hop <= self::MAX_CDN_REDIRECTS; $hop++) {
            if (!self::isAllowedDownloadUrl($url)) {
                throw new CurseForgeException('Refusing to download from an unexpected location: ' . self::describeUrl($url));
            }

            try {
                $res = Http::withoutRedirecting()->timeout(20)->head($url);
            } catch (ConnectionException $e) {
                throw new CurseForgeException('Could not reach the CurseForge download server. Please try again.');
            }

            if ($res->successful()) {
                return $url;
            }

            $location = $res->redirect() ? trim((string) $res->header('Location')) : '';
            if ($location === '') {
                throw new CurseForgeException('The CurseForge download server returned HTTP ' . $res->status() . ' for this file.');
            }
            if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
                $location = self::describeUrl($url) . $location;
            }
            $url = str_replace(' ', '%20', $location);
        }

        throw new CurseForgeException('Too many redirects from the CurseForge download server.');
    }
}

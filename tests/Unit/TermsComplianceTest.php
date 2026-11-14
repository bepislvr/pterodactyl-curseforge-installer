<?php

namespace ModpackInstaller\Tests;

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\CurseForgeClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\ModpackInstallerController;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\WingsPackInstaller;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Client\BlueprintClientLibrary;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\FakeDb;
use Pterodactyl\Models\Server;

/**
 * Checks against the CurseForge 3rd Party API Terms and Conditions (see CLAUDE.md, "Terms compliance"):
 *  - nothing obtained through the API is saved or cached (section 3.1): search, resolve and install must not
 *    write CurseForge data to the cache, the database, files, the session or the logs;
 *  - the API key only goes to api.curseforge.com and never into a response;
 *  - a 429 gives a clear message and is not retried;
 *  - an author's "no third-party distribution" setting is honoured at lookup and at install time.
 *
 * All HTTP is faked; preventStrayRequests() fails the test on any real request.
 */
class TermsComplianceTest extends TestCase
{
    private const API = 'https://api.curseforge.com';
    private const KEY = 'CANARY-KEY-5b1e';

    /** Strings that only exist in the faked CurseForge responses. None may end up stored anywhere. */
    private const CANARIES = [
        'CANARY-NAME-7f3a', 'CANARY-SUMMARY-91cd', 'CANARY-AUTHOR-2e44', 'canary-slug-c0de',
        'CANARY-FILE-ab12', 'CANARY-DISPLAY-77fe', 'canary-cdn-path-3d9',
    ];

    private string $storage;
    /** @var array<int,string> */
    private array $cacheWrites = [];
    /** @var array<int,string> */
    private array $queries = [];
    /** @var array<int,string> */
    private array $logged = [];

    protected function getEnvironmentSetUp($app): void
    {
        // A storage directory of its own, so every file the framework writes during a test can be inspected.
        $this->storage = sys_get_temp_dir() . '/modpackinstaller-compliance-' . bin2hex(random_bytes(6));
        foreach (['/framework/cache/data', '/framework/sessions', '/framework/views', '/logs', '/app'] as $dir) {
            mkdir($this->storage . $dir, 0777, true);
        }
        $app->useStoragePath($this->storage);
        $app['config']->set('cache.default', 'file');
        $app['config']->set('cache.stores.file.path', $this->storage . '/framework/cache/data');
        $app['config']->set('session.driver', 'file');
        $app['config']->set('session.files', $this->storage . '/framework/sessions');
        $app['config']->set('logging.default', 'single');
        $app['config']->set('logging.channels.single.path', $this->storage . '/logs/laravel.log');
    }

    protected function setUp(): void
    {
        parent::setUp();
        FakeDb::$data = ['modpackinstaller::api_key' => self::KEY];
        Http::preventStrayRequests();

        Event::listen(KeyWritten::class, function (KeyWritten $e) {
            $this->cacheWrites[] = $e->key;
        });
        Event::listen(QueryExecuted::class, function (QueryExecuted $e) {
            $this->queries[] = $e->sql;
        });
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message . ' ' . json_encode($e->context);
        });
    }

    protected function tearDown(): void
    {
        $storage = $this->storage;
        parent::tearDown();
        // Remove only the files this test's own storage directory contains.
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($storage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($storage);
    }

    // ---- fixtures ---------------------------------------------------------------------------------------------

    private static function mod(array $overrides = []): array
    {
        return array_merge([
            'id' => 1001, 'gameId' => 432, 'classId' => 4471,
            'name' => 'CANARY-NAME-7f3a', 'slug' => 'canary-slug-c0de', 'summary' => 'CANARY-SUMMARY-91cd',
            'links' => ['websiteUrl' => 'https://www.curseforge.com/minecraft/modpacks/canary-slug-c0de'],
            'authors' => [['id' => 5, 'name' => 'CANARY-AUTHOR-2e44', 'url' => 'https://www.curseforge.com/members/x']],
            'logo' => ['thumbnailUrl' => 'https://media.forgecdn.net/avatars/thumbnails/1/2/64/64/x.png'],
            'downloadCount' => 12345, 'allowModDistribution' => true,
            'latestFiles' => [['id' => 2001, 'serverPackFileId' => 2002]],
        ], $overrides);
    }

    private static function clientFile(): array
    {
        return [
            'id' => 2001, 'modId' => 1001, 'displayName' => 'CANARY-DISPLAY-77fe', 'fileName' => 'client.zip',
            'fileDate' => '2026-10-01T00:00:00Z', 'gameVersions' => ['1.20.1', 'Forge'], 'isAvailable' => true,
            'serverPackFileId' => 2002,
        ];
    }

    private static function serverPackFile(array $overrides = []): array
    {
        return array_merge([
            'id' => 2002, 'modId' => 1001, 'displayName' => 'Server Pack', 'fileName' => 'CANARY-FILE-ab12.zip',
            'isServerPack' => true, 'isAvailable' => true,
            'downloadUrl' => 'https://edge.forgecdn.net/files/2002/canary-cdn-path-3d9/CANARY-FILE-ab12.zip',
        ], $overrides);
    }

    /** The fake API and CDN for a pack that allows distribution. */
    private function fakeCurseForge(array $modOverrides = [], array $fileOverrides = []): void
    {
        $mod = self::mod($modOverrides);
        Http::fake([
            self::API . '/v1/mods/search*' => Http::response([
                'data' => [$mod],
                'pagination' => ['index' => 0, 'pageSize' => 20, 'resultCount' => 1, 'totalCount' => 1],
            ]),
            self::API . '/v1/mods/1001/files/2002' => Http::response(['data' => self::serverPackFile($fileOverrides)]),
            self::API . '/v1/mods/1001/files*' => Http::response(['data' => [self::clientFile()]]),
            self::API . '/v1/mods/1001' => Http::response(['data' => $mod]),
            'https://edge.forgecdn.net/*' => Http::response('', 302, [
                'Location' => 'https://mediafilez.forgecdn.net/files/2002/canary-cdn-path-3d9/CANARY-FILE-ab12.zip',
            ]),
            'https://mediafilez.forgecdn.net/*' => Http::response('', 200),
        ]);
    }

    private function controller(?RecordingInstaller $installer = null): ModpackInstallerController
    {
        return new ModpackInstallerController(
            new CurseForgeClient(new BlueprintClientLibrary()),
            $installer ?? new RecordingInstaller()
        );
    }

    private function request(array $body): Request
    {
        $request = Request::create('/api/client/extensions/modpackinstaller/servers/x/any', 'POST', $body);
        $request->setUserResolver(fn () => new class {
            public function can(string $permission, mixed $server): bool
            {
                return $permission === 'file.create';
            }
        });

        return $request;
    }

    private function body(JsonResponse $response): array
    {
        return $response->getData(true);
    }

    /** Fails if anything was written to cache, database or logs, or if a canary or the key is in any stored file. */
    private function assertNothingPersisted(): void
    {
        $this->assertSame([], $this->cacheWrites, 'CurseForge data must not be cached.');
        $this->assertSame([], $this->queries, 'CurseForge data must not be written to the database.');
        $this->assertSame([], $this->logged, 'CurseForge data must not be logged.');
        $this->assertSame([], FakeDb::$data === ['modpackinstaller::api_key' => self::KEY] ? [] : FakeDb::$data,
            'Only the API key may be in the extension\'s settings.');

        // Flush what the framework would write at the end of a request, then read every file in storage.
        if ($this->app->bound('session.store') || $this->app->resolved('session')) {
            $this->app['session']->driver()->save();
        }
        $stored = serialize($this->app['session']->driver()->all()) . serialize(Cache::get('modpackinstaller'));
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile()) {
                $stored .= "\n" . $file->getPathname() . "\n" . file_get_contents($file->getPathname());
            }
        }
        foreach ([...self::CANARIES, self::KEY] as $canary) {
            $this->assertStringNotContainsString($canary, $stored, "\"$canary\" was stored on disk or in the session.");
        }
    }

    // ---- nothing is saved or cached ---------------------------------------------------------------------------

    public function test_the_detector_itself_notices_cache_log_and_file_writes(): void
    {
        Cache::put('modpackinstaller', 'CANARY-NAME-7f3a', 60);
        logger()->info('CANARY-NAME-7f3a');

        $this->assertNotSame([], $this->cacheWrites);
        $this->assertNotSame([], $this->logged);
        $this->assertStringContainsString('CANARY-NAME-7f3a', file_get_contents($this->storage . '/logs/laravel.log'));
        $cacheFiles = glob($this->storage . '/framework/cache/data/*/*/*');
        $this->assertNotEmpty($cacheFiles);
        $this->assertStringContainsString('CANARY-NAME-7f3a', file_get_contents($cacheFiles[0]));
    }

    public function test_search_returns_curseforge_data_and_stores_none_of_it(): void
    {
        $this->fakeCurseForge();

        $response = $this->controller()->search($this->request(['query' => 'canary']), new Server());
        $hit = $this->body($response)['results'][0];

        $this->assertSame('CANARY-NAME-7f3a', $hit['name']);
        $this->assertSame(['CANARY-AUTHOR-2e44'], $hit['authors']);
        $this->assertSame('https://www.curseforge.com/minecraft/modpacks/canary-slug-c0de', $hit['projectUrl']);
        $this->assertFalse($hit['distributionDisabled']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        Http::assertSentCount(1);
        $this->assertNothingPersisted();
    }

    public function test_resolve_returns_authors_and_project_link_and_stores_nothing(): void
    {
        $this->fakeCurseForge();

        $response = $this->controller()->resolve($this->request(['query' => '1001']), new Server());
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['CANARY-AUTHOR-2e44'], $body['modpack']['authors']);
        $this->assertSame('https://www.curseforge.com/minecraft/modpacks/canary-slug-c0de', $body['modpack']['projectUrl']);
        $this->assertSame('CANARY-DISPLAY-77fe', $body['versions'][0]['displayName']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $this->assertNothingPersisted();
    }

    public function test_install_hands_the_file_to_the_users_server_and_stores_nothing(): void
    {
        $this->fakeCurseForge();
        $installer = new RecordingInstaller();

        $response = $this->controller($installer)->install(
            $this->request(['mod_id' => 1001, 'server_pack_file_id' => 2002]),
            new Server()
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([[
            '00000000-0000-0000-0000-000000000001',
            'https://mediafilez.forgecdn.net/files/2002/canary-cdn-path-3d9/CANARY-FILE-ab12.zip',
            'CANARY-FILE-ab12.zip',
        ]], $installer->calls);
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $this->assertNothingPersisted();
    }

    public function test_the_api_key_is_only_sent_to_the_curseforge_api_host(): void
    {
        $this->fakeCurseForge();

        $this->controller()->install($this->request(['mod_id' => 1001, 'server_pack_file_id' => 2002]), new Server());

        $apiCalls = 0;
        foreach (Http::recorded() as [$request]) {
            /** @var HttpRequest $request */
            $host = parse_url($request->url(), PHP_URL_HOST);
            if ($host === 'api.curseforge.com') {
                $apiCalls++;
                $this->assertTrue($request->hasHeader('x-api-key', self::KEY));
            } else {
                $this->assertFalse($request->hasHeader('x-api-key'), "The API key was sent to $host.");
            }
            $this->assertStringNotContainsString(self::KEY, $request->url());
        }
        $this->assertSame(2, $apiCalls, 'An install makes two API calls: the project and the file.');
    }

    // ---- rate limiting ----------------------------------------------------------------------------------------

    public function test_429_gives_a_clear_message_with_retry_after_and_is_not_retried(): void
    {
        Http::fake([self::API . '/*' => Http::response('CANARY-NAME-7f3a', 429, ['Retry-After' => '30'])]);

        $response = $this->controller()->search($this->request(['query' => 'canary']), new Server());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            'CurseForge is limiting requests for this API key (HTTP 429). Please wait 30 seconds before trying again.',
            $this->body($response)['error']
        );
        Http::assertSentCount(1);
        $this->assertNothingPersisted();
    }

    public function test_429_without_retry_after_and_with_a_date(): void
    {
        Http::fake([self::API . '/*' => Http::sequence()
            ->push('', 429)
            ->push('', 429, ['Retry-After' => 'Sat, 14 Nov 2026 12:00:00 GMT'])
            ->push('', 429, ['Retry-After' => '<script>alert(1)</script>'])]);
        $server = new Server();

        $this->assertStringEndsWith('Please wait a few minutes before trying again.',
            $this->body($this->controller()->resolve($this->request(['query' => '1001']), $server))['error']);
        $this->assertStringEndsWith('Please try again after Sat, 14 Nov 2026 12:00:00 GMT.',
            $this->body($this->controller()->resolve($this->request(['query' => '1001']), $server))['error']);
        $odd = $this->body($this->controller()->resolve($this->request(['query' => '1001']), $server))['error'];
        $this->assertStringNotContainsString('script', $odd);
        Http::assertSentCount(3);
    }

    public function test_429_during_install_reaches_neither_the_cdn_nor_wings(): void
    {
        Http::fake([self::API . '/*' => Http::response('', 429, ['Retry-After' => '120'])]);
        $installer = new RecordingInstaller();

        $response = $this->controller($installer)->install(
            $this->request(['mod_id' => 1001, 'server_pack_file_id' => 2002]),
            new Server()
        );

        $this->assertStringContainsString('Please wait 120 seconds', $this->body($response)['error']);
        $this->assertSame([], $installer->calls);
        Http::assertSentCount(1);
    }

    // ---- author consent (project distribution toggle) ---------------------------------------------------------

    public function test_lookup_refuses_a_pack_whose_author_disabled_distribution_and_links_to_curseforge(): void
    {
        $this->fakeCurseForge(['allowModDistribution' => false]);

        $response = $this->controller()->resolve($this->request(['query' => '1001']), new Server());
        $body = $this->body($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            'The author of this modpack has disabled downloads through third-party tools. '
            . 'Download the server pack manually from its CurseForge page.',
            $body['error']
        );
        $this->assertTrue($body['distributionDisabled']);
        $this->assertSame('https://www.curseforge.com/minecraft/modpacks/canary-slug-c0de', $body['projectUrl']);
        // Only the project was fetched; the file list was not requested.
        Http::assertSentCount(1);
        $this->assertNothingPersisted();
    }

    public function test_lookup_by_slug_refuses_too(): void
    {
        $this->fakeCurseForge(['allowModDistribution' => false]);

        $response = $this->controller()->resolve($this->request(['query' => 'canary-slug-c0de']), new Server());

        $this->assertTrue($this->body($response)['distributionDisabled']);
        Http::assertSentCount(1);
    }

    public function test_search_marks_packs_whose_author_disabled_distribution(): void
    {
        $this->fakeCurseForge(['allowModDistribution' => false]);

        $hit = $this->body($this->controller()->search($this->request(['query' => 'canary']), new Server()))['results'][0];

        $this->assertTrue($hit['distributionDisabled']);
    }

    public function test_install_refuses_when_distribution_is_disabled_even_if_a_download_url_exists(): void
    {
        // The browser can post any IDs to /install, so the setting is checked again there.
        $this->fakeCurseForge(['allowModDistribution' => false]);
        $installer = new RecordingInstaller();

        $response = $this->controller($installer)->install(
            $this->request(['mod_id' => 1001, 'server_pack_file_id' => 2002]),
            new Server()
        );
        $body = $this->body($response);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertTrue($body['distributionDisabled']);
        $this->assertSame('https://www.curseforge.com/minecraft/modpacks/canary-slug-c0de', $body['projectUrl']);
        $this->assertSame([], $installer->calls, 'Nothing may be downloaded.');
        // The project was fetched and nothing else: no file lookup, no download-url call, no CDN request.
        Http::assertSentCount(1);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'forgecdn.net') || str_contains($r->url(), 'download-url'));
    }

    public function test_install_treats_a_withheld_download_url_as_refusal_and_tries_nothing_else(): void
    {
        $this->fakeCurseForge([], ['downloadUrl' => null]);
        $installer = new RecordingInstaller();

        $response = $this->controller($installer)->install(
            $this->request(['mod_id' => 1001, 'server_pack_file_id' => 2002]),
            new Server()
        );

        $this->assertTrue($this->body($response)['distributionDisabled']);
        $this->assertSame([], $installer->calls);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'download-url')
            || parse_url($r->url(), PHP_URL_HOST) !== 'api.curseforge.com');
    }

    public function test_a_null_distribution_flag_is_not_treated_as_disabled(): void
    {
        $this->fakeCurseForge(['allowModDistribution' => null]);

        $response = $this->controller()->resolve($this->request(['query' => '1001']), new Server());

        $this->assertSame(200, $response->getStatusCode());
    }

    // ---- project link -----------------------------------------------------------------------------------------

    public function test_project_link_is_only_ever_an_https_curseforge_url(): void
    {
        $base = 'https://www.curseforge.com/minecraft/modpacks/';
        $this->assertSame($base . 'my-pack', CurseForgeClient::projectUrl(['slug' => 'my-pack']));
        $this->assertSame($base . 'my-pack', CurseForgeClient::projectUrl(['slug' => 'my-pack', 'links' => ['websiteUrl' => 'javascript:alert(1)']]));
        $this->assertSame($base . 'my-pack', CurseForgeClient::projectUrl(['slug' => 'my-pack', 'links' => ['websiteUrl' => 'https://curseforge.com.evil.example/x']]));
        $this->assertSame($base . 'my-pack', CurseForgeClient::projectUrl(['slug' => 'my-pack', 'links' => ['websiteUrl' => 'http://www.curseforge.com/x']]));
        $this->assertSame('https://legacy.curseforge.com/x', CurseForgeClient::projectUrl(['slug' => 'my-pack', 'links' => ['websiteUrl' => 'https://legacy.curseforge.com/x']]));
        $this->assertNull(CurseForgeClient::projectUrl(['slug' => '../../evil?x=1']));
        $this->assertNull(CurseForgeClient::projectUrl(null));
    }
}

/** Stands in for Wings: records what the controller would have had the node download. */
class RecordingInstaller extends WingsPackInstaller
{
    /** @var array<int,array{0:string,1:string,2:string}> */
    public array $calls = [];

    public function __construct()
    {
    }

    public function pullAndExtract(Server $server, string $url, string $fileName): void
    {
        $this->calls[] = [$server->uuid, $url, $fileName];
    }
}

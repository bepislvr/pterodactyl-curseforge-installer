<?php

namespace ModpackInstaller\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\FakeDb;
use Pterodactyl\Http\Controllers\Admin\Extensions\modpackinstaller\modpackinstallerExtensionController;

/**
 * The admin save runs the ID check, never blocks saving on it, and shows the result on the admin page.
 * Routes mirror the ones Blueprint registers (GET index / PATCH update); auth middleware is the panel's and not tested here.
 */
class AdminSaveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeDb::$data = [];
        Http::preventStrayRequests();
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(function () {
            Route::get('/admin/extensions/modpackinstaller', [modpackinstallerExtensionController::class, 'index'])
                ->name('admin.extensions.modpackinstaller.index');
            Route::patch('/admin/extensions/modpackinstaller', [modpackinstallerExtensionController::class, 'update']);
        });
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Blueprint installs admin/view.blade.php as the panel view admin.extensions.modpackinstaller.index.
        $views = sys_get_temp_dir() . '/modpackinstaller-test-views';
        @mkdir($views . '/admin/extensions/modpackinstaller', 0777, true);
        copy(dirname(__DIR__, 2) . '/admin/view.blade.php', $views . '/admin/extensions/modpackinstaller/index.blade.php');
        $app['config']->set('view.paths', [$views]);
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    private function save(string $key)
    {
        return $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)
            ->patch('/admin/extensions/modpackinstaller', ['api_key' => $key]);
    }

    public function test_good_key_is_saved_and_success_is_shown(): void
    {
        Http::fake([CheckIdsTest::GAME_URL => Http::response(CheckIdsTest::game()), CheckIdsTest::CATEGORIES_URL => Http::response(CheckIdsTest::classes())]);

        $this->save('  good-key  ')->assertRedirect('/admin/extensions/modpackinstaller')
            ->assertSessionHas('modpackinstaller_id_check', fn ($v) => $v['status'] === 'ok' && $v['ok'] === true);
        $this->assertSame('good-key', FakeDb::$data['modpackinstaller::api_key']);

        $html = $this->followingRedirects()->save('good-key')->assertOk()->getContent();
        $this->assertStringContainsString('alert-success', $html);
        $this->assertStringContainsString('CurseForge confirmed the game and class IDs', $html);
        $this->assertStringContainsString('Game 432 is Minecraft and class 4471 is Modpacks.', $html);
        $this->assertStringNotContainsString('good-key', $html);
    }

    public function test_mismatch_still_saves_and_shows_a_warning_with_what_curseforge_returned(): void
    {
        FakeDb::$data['modpackinstaller::api_key'] = 'old-key';
        Http::fake([
            CheckIdsTest::GAME_URL => Http::response(CheckIdsTest::game()),
            CheckIdsTest::CATEGORIES_URL => Http::response(CheckIdsTest::classes(CheckIdsTest::category(4471, '<b>Bukkit</b> Plugins', 'bukkit-plugins'))),
        ]);

        $html = $this->followingRedirects()->save('new-key')->assertOk()->getContent();

        $this->assertSame('new-key', FakeDb::$data['modpackinstaller::api_key']);
        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringContainsString('(Minecraft = 432, Modpacks = 4471)', $html);
        // CurseForge text is escaped, not rendered as HTML.
        $this->assertStringContainsString('Class 4471 is named &quot;&lt;b&gt;Bukkit&lt;/b&gt; Plugins&quot;', $html);
        $this->assertStringNotContainsString('<b>Bukkit</b>', $html);
        $this->assertStringNotContainsString('new-key', $html);
    }

    public function test_rejected_key_still_saves_and_warns(): void
    {
        Http::fake([CheckIdsTest::GAME_URL => Http::response('', 403)]);

        $html = $this->followingRedirects()->save('bad-key')->assertOk()->getContent();

        $this->assertSame('bad-key', FakeDb::$data['modpackinstaller::api_key']);
        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringContainsString('CurseForge rejected the API key (HTTP 403 on /v1/games/432)', $html);
    }

    public function test_network_failure_still_saves_and_warns(): void
    {
        Http::fake(function ($request) {
            throw new ConnectException('cURL error 28: timed out', new PsrRequest('GET', $request->url()));
        });

        $html = $this->followingRedirects()->save('some-key')->assertOk()->getContent();

        $this->assertSame('some-key', FakeDb::$data['modpackinstaller::api_key']);
        $this->assertStringContainsString('Could not reach the CurseForge API to check the IDs.', $html);
    }

    public function test_blank_save_keeps_key_and_skips_the_check(): void
    {
        FakeDb::$data['modpackinstaller::api_key'] = 'old-key';
        Http::fake();

        $html = $this->followingRedirects()->save('   ')->assertOk()->getContent();

        $this->assertSame('old-key', FakeDb::$data['modpackinstaller::api_key']);
        Http::assertNothingSent();
        $this->assertStringNotContainsString('alert-', $html);
    }
}

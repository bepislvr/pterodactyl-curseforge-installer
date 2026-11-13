<?php

namespace ModpackInstaller\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\CurseForgeClient;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\IdCheckResult;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Client\BlueprintClientLibrary;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\FakeDb;

/**
 * CurseForgeClient::checkIds() against faked CurseForge responses. No request leaves the process:
 * preventStrayRequests() fails the test on any URL that is not faked.
 */
class CheckIdsTest extends TestCase
{
    public const GAME_URL = 'https://api.curseforge.com/v1/games/432';
    public const CATEGORIES_URL = 'https://api.curseforge.com/v1/categories*';

    protected function setUp(): void
    {
        parent::setUp();
        FakeDb::$data = [];
        Http::preventStrayRequests();
    }

    private function client(): CurseForgeClient
    {
        return new CurseForgeClient(new BlueprintClientLibrary());
    }

    public static function game(array $overrides = []): array
    {
        return ['data' => array_merge(['id' => 432, 'name' => 'Minecraft', 'slug' => 'minecraft', 'status' => 2, 'apiStatus' => 2], $overrides)];
    }

    /** Shape of a Core API Category with isClass. */
    public static function category(int $id, string $name, string $slug, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'gameId' => 432, 'name' => $name, 'slug' => $slug,
            'url' => "https://www.curseforge.com/minecraft/$slug", 'isClass' => true,
        ], $extra);
    }

    public static function classes(?array $modpacks = null): array
    {
        $list = [
            self::category(6, 'Mods', 'mc-mods'),
            self::category(12, 'Resource Packs', 'texture-packs'),
            self::category(17, 'Worlds', 'worlds'),
        ];
        $list[] = $modpacks ?? self::category(4471, 'Modpacks', 'modpacks');

        return ['data' => $list];
    }

    public function test_correct_response_confirms_both_ids(): void
    {
        Http::fake([self::GAME_URL => Http::response(self::game()), self::CATEGORIES_URL => Http::response(self::classes())]);

        $result = $this->client()->checkIds('test-key');

        $this->assertSame(IdCheckResult::OK, $result->status);
        $this->assertTrue($result->isOk());
        $this->assertSame('Game 432 is Minecraft and class 4471 is Modpacks.', $result->message);
        $this->assertSame(['status' => 'ok', 'message' => $result->message, 'ok' => true], $result->toArray());

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->url() === self::GAME_URL && $r->hasHeader('x-api-key', 'test-key'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.curseforge.com/v1/categories?gameId=432&classesOnly=true'
            && $r->hasHeader('x-api-key', 'test-key'));
    }

    public function test_uses_stored_key_when_none_given(): void
    {
        FakeDb::$data['modpackinstaller::api_key'] = 'stored-key';
        Http::fake([self::GAME_URL => Http::response(self::game()), self::CATEGORIES_URL => Http::response(self::classes())]);

        $this->assertTrue($this->client()->checkIds()->isOk());
        Http::assertSent(fn (Request $r) => $r->hasHeader('x-api-key', 'stored-key'));
    }

    public function test_no_key_is_reported_without_a_request(): void
    {
        Http::fake();

        $result = $this->client()->checkIds();

        $this->assertSame(IdCheckResult::NO_KEY, $result->status);
        $this->assertStringContainsString('No CurseForge API key', $result->message);
        Http::assertNothingSent();
    }

    public function test_wrong_class_name_is_a_mismatch_naming_what_curseforge_returned(): void
    {
        Http::fake([
            self::GAME_URL => Http::response(self::game()),
            self::CATEGORIES_URL => Http::response(self::classes(self::category(4471, 'Bukkit Plugins', 'bukkit-plugins'))),
        ]);

        $result = $this->client()->checkIds('k');

        $this->assertSame(IdCheckResult::MISMATCH, $result->status);
        $this->assertFalse($result->isOk());
        $this->assertSame('Class 4471 is named "Bukkit Plugins" (slug "bukkit-plugins"), expected "Modpacks".', $result->message);
    }

    public function test_class_4471_that_is_not_a_class_is_a_mismatch(): void
    {
        Http::fake([
            self::GAME_URL => Http::response(self::game()),
            self::CATEGORIES_URL => Http::response(self::classes(self::category(4471, 'Modpacks', 'modpacks', ['isClass' => false, 'gameId' => 1]))),
        ]);

        $result = $this->client()->checkIds('k');

        $this->assertSame(IdCheckResult::MISMATCH, $result->status);
        $this->assertSame('Class 4471 is not marked as a class (isClass is false), belongs to game 1.', $result->message);
    }

    public function test_missing_class_is_a_mismatch_and_points_at_a_modpacks_class_under_another_id(): void
    {
        Http::fake([
            self::GAME_URL => Http::response(self::game()),
            self::CATEGORIES_URL => Http::response(self::classes(self::category(9999, 'Modpacks', 'modpacks'))),
        ]);

        $result = $this->client()->checkIds('k');

        $this->assertSame(IdCheckResult::MISMATCH, $result->status);
        $this->assertSame('Class 4471 is not among the 4 classes CurseForge lists for game 432. A class "Modpacks" exists with id 9999.', $result->message);
    }

    public function test_missing_class_with_empty_list(): void
    {
        Http::fake([self::GAME_URL => Http::response(self::game()), self::CATEGORIES_URL => Http::response(['data' => []])]);

        $result = $this->client()->checkIds('k');

        $this->assertSame(IdCheckResult::MISMATCH, $result->status);
        $this->assertSame('Class 4471 is not among the 0 classes CurseForge lists for game 432.', $result->message);
    }

    public function test_wrong_game_is_a_mismatch_and_skips_the_category_call(): void
    {
        Http::fake([self::GAME_URL => Http::response(self::game(['name' => 'Terraria', 'slug' => 'terraria'])), self::CATEGORIES_URL => Http::response(self::classes())]);

        $result = $this->client()->checkIds('k');

        $this->assertSame(IdCheckResult::MISMATCH, $result->status);
        $this->assertSame('Game 432 is "Terraria" (slug "terraria", id 432), not Minecraft.', $result->message);
        Http::assertSentCount(1);
    }

    public static function authStatuses(): array
    {
        return ['401' => [401], '403' => [403]];
    }

    #[DataProvider('authStatuses')]
    public function test_rejected_key_is_reported_as_auth(int $status): void
    {
        Http::fake([self::GAME_URL => Http::response(['error' => 'nope'], $status), self::CATEGORIES_URL => Http::response(self::classes())]);

        $result = $this->client()->checkIds('bad-key');

        $this->assertSame(IdCheckResult::AUTH, $result->status);
        $this->assertFalse($result->wasChecked());
        $this->assertStringStartsWith("CurseForge rejected the API key (HTTP $status on /v1/games/432).", $result->message);
        $this->assertStringNotContainsString('bad-key', $result->message);
        Http::assertSentCount(1);
    }

    #[DataProvider('authStatuses')]
    public function test_rejected_key_on_the_category_call(int $status): void
    {
        Http::fake([self::GAME_URL => Http::response(self::game()), self::CATEGORIES_URL => Http::response('', $status)]);

        $result = $this->client()->checkIds('k');

        $this->assertSame(IdCheckResult::AUTH, $result->status);
        $this->assertStringContainsString("HTTP $status on /v1/categories", $result->message);
    }

    public function test_network_failure_is_reported_as_unreachable(): void
    {
        Http::fake(function ($request) {
            throw new ConnectException('cURL error 7: Failed to connect', new PsrRequest('GET', $request->url()));
        });

        $result = $this->client()->checkIds('k');

        $this->assertSame(IdCheckResult::UNREACHABLE, $result->status);
        $this->assertSame('Could not reach the CurseForge API to check the IDs.', $result->message);
    }

    public function test_server_error_is_reported_with_status(): void
    {
        Http::fake([self::GAME_URL => Http::response('oops', 503)]);

        $result = $this->client()->checkIds('k');

        $this->assertSame(IdCheckResult::HTTP_ERROR, $result->status);
        $this->assertSame('CurseForge returned HTTP 503 for /v1/games/432.', $result->message);
    }

    public function test_non_json_is_unexpected(): void
    {
        Http::fake([self::GAME_URL => Http::response('not json')]);

        $result = $this->client()->checkIds('k');

        $this->assertSame(IdCheckResult::UNEXPECTED, $result->status);
        $this->assertSame('/v1/games/432 returned no game data.', $result->message);
    }

    public function test_odd_field_types_do_not_throw(): void
    {
        Http::fake([self::GAME_URL => Http::response(self::game(['name' => ['x'], 'slug' => null])), self::CATEGORIES_URL => Http::response(self::classes())]);
        $this->assertSame(IdCheckResult::MISMATCH, $this->client()->checkIds('k')->status);
    }
}

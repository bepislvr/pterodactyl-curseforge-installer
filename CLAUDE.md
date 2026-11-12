# CLAUDE.md

Blueprint extension for Pterodactyl: install CurseForge modpack server packs onto a server.
Read `README.md` first for scope. Keep scope small: server packs only, ID/URL/slug input.

## Your job

This scaffold was written from memory without a running panel. Make it actually work.

1. (Done by reading, see Verification record.)
2. Get `blueprint -build` passing on a dev panel and fix whatever breaks.
3. Test with 2-3 real packs that have server packs, plus one without a server pack, plus one
   with downloads disabled, and confirm each error path shows a clear message.
4. Then work through the roadmap below.

## Verification record (phase 1, by reading sources; nothing was run)

Sources: Blueprint docs and framework source (BlueprintFramework/web content/docs, BlueprintFramework/framework
`scripts/commands/extensions/install.sh`, `routes/blueprint.php`, `app/Providers/Blueprint/RouteServiceProvider.php`,
release beta-2026-08), Pterodactyl panel v1.15.2, Wings (main), docs.curseforge.com. None of this was executed
against a running panel. `blueprint -build` has NOT been run.

Confirmed
- Admin controller: namespace `Pterodactyl\Http\Controllers\Admin\Extensions\{identifier}`, class `{identifier}ExtensionController`,
  methods `index` (GET) / `update` (PATCH), route name `admin.extensions.modpackinstaller.index`, view
  `admin.extensions.modpackinstaller.index`, `dbGet($table,$record,$default=null)` / `dbSet($table,$record,$value)`.
- `BlueprintClientLibrary` import path, and it is container-injectable (no constructor dependencies).
- Client router prefix is `/api/client/extensions/modpackinstaller`, so the React URLs
  `/api/client/extensions/modpackinstaller/servers/{uuid}/(resolve|install)` are right. The `blueprint/client-api` group
  binds `{server}` by uuid / uuidShort.
- `DaemonFileRepository::pull($url,$directory,$params)` params `filename`, `use_header`, `foreground` map to Wings
  `file_name`, `use_header`, `foreground`; `decompressFile($root,$file)` exists with a 15 minute timeout.
- Permission constant `Permission::ACTION_FILE_CREATE` = `file.create`; `$user->can($perm, $server)` goes through ServerPolicy.
- CurseForge: `x-api-key` header, `/v1/mods/search` with `gameId`, `classId`, `slug`, `searchFilter`, `sortField`
  (2 = Popularity), `sortOrder`, `index`, `pageSize` (max 50); `/v1/mods/{id}/files/{fileId}/download-url`;
  `serverPackFileId` is a nullable int on File objects.

Corrected
- `conf.yml`: `target` is now `beta-2026-08`. `icon: "icon.jpg"` pointed at a missing file (fatal at install), now empty.
  `dashboard.components` must be a directory, not a file. `requests.app` is symlinked to
  `app/BlueprintFramework/Extensions/modpackinstaller`, it does NOT merge into the panel `app/`.
- PHP classes moved to flat files under `app/` using the `{appcontext}` namespace placeholder
  (`Pterodactyl\BlueprintFramework\Extensions\modpackinstaller`). The old `Pterodactyl\Services\...` and
  `Pterodactyl\Http\Controllers\...` namespaces would never have been installed. `CurseForgeException` split to its own file for PSR-4.
- `dashboard/components.yml` is now `dashboard/Components.yml` (case matters) with the real schema:
  `Navigation.Routes[]` with `Name`, `Path`, `Type: server`, `Component`, `Permission`, `AdminOnly`. The old
  `Components.Router.Server` shape does not exist. The `.tsx` is wired by Blueprint importing
  `@blueprint/extensions/modpackinstaller/ModpackInstaller` (default export) into `resources/scripts/blueprint/extends/routers/routes.ts`.
  The page URL is `/server/{id}/modpack-installer`.
- Wings pull: the panel's Guzzle timeout is `GUZZLE_TIMEOUT` (default 15s), so a foreground pull through
  `pull()` would time out on real packs. The controller now posts to the same Wings endpoint with a 600s per-request timeout.
  Wings returns 400 with `message` on a failed foreground download; Guzzle/Daemon exceptions are now mapped to
  `{"error": ...}` / 422 (they were uncaught before and would have surfaced as a generic panel error).
- Added: https-only check on the download URL, classId check for numeric project IDs, 401/403 and connection-failure
  handling for CurseForge calls, skip of `isAvailable: false` files.
- Admin view no longer echoes the stored API key into the HTML; blank field keeps the current key.

Still unknown (needs a real panel)
- `Permission: "'file.create'"` in Components.yml: install.sh pastes it raw into TypeScript. By reading the bash YAML
  parser the inner quotes should survive, but it was not executed. If the build fails in routes.ts, drop `Permission`.
- Whether PSR-4 resolves classes through the `app/BlueprintFramework/Extensions` symlink on the panel (no optimized/authoritative classmap).
- CurseForge: game ID 432 and class ID 4471 are not stated in the docs; only seen in third-party usage
  (itzg/docker-minecraft-server issue 3591). `downloadUrl` is documented as a plain string, null when the author disables
  distribution is community knowledge, handled defensively.
- The CDN allowlist (`forgecdn.net`, `curseforge.com`): the docs do not name download hosts. `edge.forgecdn.net` and
  `mediafilez.forgecdn.net` are expected and match; confirm with a real pack.
- Whether PHP/web server timeouts allow a multi-minute blocking install (`set_time_limit(900)` is set; nginx/FPM limits are the host's).
- Test with 2-3 real packs, one without a server pack, one with downloads disabled.

## Conventions

- Never expose the API key to the browser. All CurseForge calls stay server-side.
- Never pass user-supplied URLs to Wings. Only URLs returned by CurseForge on allowed hosts.
- Require `file.create` permission on every endpoint.
- Errors returned to the UI are `{ "error": "<message>" }` with HTTP 422.

## Roadmap (after it works)

1. Optional "backup before install" using Pterodactyl's backup service.
2. Optional wipe of the server root (with confirmation and disk-space check).
3. Poll Wings for pull status instead of a blocking `foreground` pull.
4. Modpack search/browse UI using `/v1/mods/search` (gameId 432, classId 4471).
5. Per-egg handling: accept EULA, set Java version, adjust startup command for Forge/NeoForge/Fabric.
6. Cache CurseForge responses; respect rate limits.

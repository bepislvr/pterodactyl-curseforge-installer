# CLAUDE.md

Blueprint extension for Pterodactyl: install CurseForge modpack server packs onto a server.
Read `README.md` first for scope. Keep scope small: server packs only; search/browse plus ID/URL/slug input.

## Your job

This scaffold was written from memory without a running panel. Make it actually work.

1. (Done by reading, see Verification record.)
2. (Done, see "Run record (phase 2)".) `blueprint -build` passes on a real panel.
3. Needs Wings + a real CurseForge API key. Test with 2-3 real packs that have server packs, plus one without a server pack, plus one
   with downloads disabled, and confirm each error path shows a clear message.
4. Then work through the roadmap below. (Search UI is done and runs on the panel; not yet tried with real CurseForge data.)

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
- PHP classes moved to flat files under `app/` in namespace `Pterodactyl\BlueprintFramework\Extensions\modpackinstaller`
  (written literally; the `{appcontext}` placeholder is broken, see phase 2). The old `Pterodactyl\Services\...` and
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

Still unknown (needs Wings and a real CurseForge API key)
- CurseForge: game ID 432 and class ID 4471 are not stated in the docs; only seen in third-party usage
  (itzg/docker-minecraft-server issue 3591). `downloadUrl` is documented as a plain string; null when the author disables
  distribution is community knowledge, handled defensively.
- The CDN allowlist (`forgecdn.net`, `curseforge.com`): the docs do not name download hosts. `edge.forgecdn.net` and
  `mediafilez.forgecdn.net` are expected and match; confirm with a real pack.
- Real download + extraction by Wings, and whether a large pack finishes inside nginx `fastcgi_read_timeout`
  (300s in the official Pterodactyl nginx config; the controller allows 600s pull + up to 15 min extract).
- Test with 2-3 real packs, one without a server pack, one with downloads disabled.

## Run record (phase 2, 2026-11-12, executed on a test VM)

Setup: Ubuntu 26.04, Pterodactyl panel v1.15.2 per the official docs (nginx, plain http, MariaDB, Redis, pteroq), but
PHP 8.5 from Ubuntu because the ondrej PPA has no 26.04 build (composer constraint `^8.2 || ^8.3` accepts it).
reCAPTCHA disabled in `.env` for scripted logins. Blueprint beta-2026-08 per its docs, developer mode on. No Wings, no Docker.

Fixed by running it
- `{appcontext}` is broken on beta-2026-08: install.sh does `sed "s~{appcontext}~Pterodactyl\BlueprintFramework\Extensions\$identifier~g"`
  and GNU sed treats `\B`, `\E`, `\m` in the replacement as escapes, giving `PterodactylBlueprintFrameworkxtensionsmodpackinstaller`
  (route:list failed with class not found). The namespace is now written literally in `app/*.php` and `routers/client.php`.
- The panel's axios instance (`@/api/http`) has a 20s timeout, so the browser gave up on `/install` long before Wings finished.
  The install call now passes its own 15 minute timeout.
- UI error text used only `response.data.error`, so 403s and validation errors (`{"errors":[{detail}]}`) showed
  "Request failed with status code 422". Now uses the panel's `httpErrorToHuman`, which handles both.
- Clearer messages for a non-https download URL and for Wings extraction errors (no more "(request id: )").

Verified by running
- `blueprint -build` succeeds (only warning: no icon); webpack compiles; `tsc --noEmit` reports no errors in the extension
  (two pre-existing i18n type errors in the stock panel).
- `Permission: "'file.create'"` lands in routes.ts as `permission: 'file.create'`; nav entry shows for the owner and is hidden for
  a subuser without `file.create`, whose direct URL gets the panel's "Access Denied" page.
- `route:list`: admin GET/PATCH and client POST search/resolve/install, client routes with middleware `blueprint/api`,
  `RequireTwoFactorAuthentication`, `blueprint/client-api`, `throttle:api.client`, `AuthenticateServerAccess`.
- PSR-4 autoloads the classes through the `app/BlueprintFramework/Extensions/modpackinstaller` symlink even with
  `composer install --optimize-autoloader` (classes are not in the classmap; PSR-4 fallback works). Container resolves all classes.
- Admin page renders; saving stores `modpackinstaller::api_key`; the key is not echoed; a blank save keeps it.
- Client API through the full HTTP stack: a bad key gives 422 `{"error":"CurseForge rejected the API key (HTTP 403)..."}`
  (real call to api.curseforge.com), a subuser without `file.create` gets 403, unknown server 404, bad input 422 validation.
- Server page in headless Chromium: renders, shows backend errors, and (with the three endpoints mocked in the browser)
  list, server pack badge, load more, debounce, empty state, result click to resolve, version select, install busy state,
  success message, and "no server pack" / "downloads disabled" errors.
- Install controller against a fake Wings on the node address (CurseForge lookup stubbed in a test script): posts
  `/api/servers/{uuid}/files/pull` with `url, root "/", file_name, use_header false, foreground true` and the node's bearer token,
  then `/files/decompress` with `root, file`; `fileName` path traversal is stripped by `basename`; non-https and lookalike
  hosts (`forgecdn.net.evil.com`) are refused; Wings 400s and an unreachable node become 422 `{"error": ...}`.

## Search endpoint (phase 3; endpoint and UI run on the panel, not against real CurseForge data)

`POST .../servers/{server}/search` with `query` (optional, max 100), `index` (0-9950), `page_size` (1-50, default 20).
Same `file.create` check and `{"error": ...}`/422 shape as the other endpoints. Backend `CurseForgeClient::searchModpacks()`
calls `/v1/mods/search` with gameId 432, classId 4471, sortField 2 (Popularity) desc, `searchFilter`, `index`, `pageSize`, and
returns only `id, name, slug, summary, thumbnailUrl (https only), downloadCount, hasServerPack` plus paging info.
`hasServerPack` is derived from `latestFiles[].serverPackFileId`, which is only a short list, so false is not proof of absence.
The React page debounces input (400 ms), ignores stale responses, shows loading/empty/error states, and a result click calls
the existing `/resolve` flow with the project ID. Unknown: exact CurseForge pagination fields (`pagination.totalCount` is
assumed from the Core API schema) and `hasServerPack` accuracy on real data.

## Conventions

- Never expose the API key to the browser. All CurseForge calls stay server-side.
- Never pass user-supplied URLs to Wings. Only URLs returned by CurseForge on allowed hosts.
- Require `file.create` permission on every endpoint.
- Errors returned to the UI are `{ "error": "<message>" }` with HTTP 422.

## Roadmap (after it works)

1. Optional "backup before install" using Pterodactyl's backup service.
2. Optional wipe of the server root (with confirmation and disk-space check).
3. Poll Wings for pull status instead of a blocking `foreground` pull.
4. Per-egg handling: accept EULA, set Java version, adjust startup command for Forge/NeoForge/Fabric.
5. Cache CurseForge (search hits CurseForge on every keystroke pause and on page load; caching and rate-limit handling matter more now) responses; respect rate limits.

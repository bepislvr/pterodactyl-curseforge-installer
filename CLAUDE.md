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

Still unknown (needs a real CurseForge API key)
- CurseForge game ID 432 and class ID 4471: **checked by code, not yet confirmed against the live API.** They are not
  stated in the docs (only seen in third-party usage, itzg/docker-minecraft-server issue 3591). See "CurseForge ID check".
- `downloadUrl` is documented as a plain string; null when the author disables
  distribution is community knowledge. An empty value is treated as the author's refusal (see "Terms compliance").
- Which host CurseForge's `downloadUrl` uses, and that the redirect chain ends in a 200 for a real file
  (only a made-up path was tried: edge.forgecdn.net 302 -> mediafilez.forgecdn.net 403).
- Response shapes on real data (search, files, pagination), and the full install of a real pack.
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

## Run record (phase 2b, 2026-11-12: Wings, no CurseForge key)

Setup: Docker 29.9.3 (get.docker.com) and Wings v1.13.5 per the official docs on the same VM; location, node
(192.168.178.133, http, :8080) and allocation :25565 created in the admin UI; server "Forge Modpack" from the stock
Forge egg (MC 1.20.1, Java 17 image, egg install completed). No CurseForge API key: nothing was fetched from
curseforge.com except the keyless/fake-key error checks against api.curseforge.com.

Fixed by running it
- Wings never follows redirects (downloader `CheckRedirect` returns `ErrUseLastResponse`) and fails a non-200 with a
  generic 500. `edge.forgecdn.net` answers with `302 -> mediafilez.forgecdn.net` (seen with a keyless HEAD on a made-up
  path). `CurseForgeClient::resolveCdnUrl()` now follows up to 3 redirects on the panel with HEAD; every hop and the final
  URL must pass `isAllowedDownloadUrl()` (https + forgecdn.net/curseforge.com, unchanged), and a non-2xx end becomes a clear
  error. Spaces are sent as `%20`. Checked with `Http::fake()` (off-CDN redirect, http downgrade, relative Location, loop,
  missing Location: all refused or resolved correctly; no request to the off-CDN host).
- The downloaded archive stayed in the server root after extraction (300 MB on the test pack). Now deleted after a
  successful extraction, kept on failure.
- After the 600 s pull timeout the user saw "Download failed", but Wings keeps downloading and leaves the archive
  unextracted (observed: finished at 683 s). cURL errno 28 now gives a message saying so; errno 7 (node unreachable) keeps
  "Download failed".
- nginx 504 at 300 s showed "Request failed with status code 504" although the install finished (observed at 410 s).
  The UI now says the install may still be running.
- Wings calls moved to `WingsPackInstaller` (no behaviour change) so they can be exercised without the controller.

Verified by running
- Wings blocks private destinations (`remote_download_allowlist` empty): pull of http://192.168.178.133:8000/... ->
  "The URL or IP address provided could not be resolved to a valid destination." No request reached the test server.
- With the VM IP temporarily allowlisted in Wings (reverted afterwards), `WingsPackInstaller::pullAndExtract()` against
  `python3 -m http.server`: small pack and a name with spaces (raw and %20) download and extract into the server root,
  owned by `pterodactyl`; a 404 gives "Download failed: An unexpected error was encountered..." (Wings 500).
- ~300 MB zip over LAN: pull 0.2 s, extract ~0.2 s, 0.4 s total, no timeouts.
- Throttled ~300 MB: at 750 KB/s through nginx + PHP-FPM, nginx returned 504 at 300 s, PHP finished the install at
  409.8 s; at 450 KB/s, Guzzle's pull timeout fired at 600.0 s.
- Endpoints with no key and a fake key (CurseForge returned 403) all give 422 `{"error": ...}`; a subuser without
  `file.create` gets 403 on all three endpoints; nav entry hidden for that subuser.
- The panel's client API rate limit (256/min per user) returned 429 when two browser suites ran back to back.

## Search endpoint (phase 3; endpoint and UI run on the panel, not against real CurseForge data)

`POST .../servers/{server}/search` with `query` (optional, max 100), `index` (0-9950), `page_size` (1-50, default 20).
Same `file.create` check and `{"error": ...}`/422 shape as the other endpoints. Backend `CurseForgeClient::searchModpacks()`
calls `/v1/mods/search` with gameId 432, classId 4471, sortField 2 (Popularity) desc, `searchFilter`, `index`, `pageSize`, and
returns only `id, name, slug, summary, thumbnailUrl (https only), downloadCount, hasServerPack` plus paging info.
`hasServerPack` is derived from `latestFiles[].serverPackFileId`, which is only a short list, so false is not proof of absence.
The React page debounces input (400 ms), ignores stale responses, shows loading/empty/error states, and a result click calls
the existing `/resolve` flow with the project ID. Since 2026-11-14 it requests nothing on page load and searches while
typing only from 3 characters (see "Terms compliance"). `pagination.totalCount` is in the documented Pagination schema
(READ in docs.curseforge.com, not seen live). Unknown: `hasServerPack` accuracy on real data.

## CurseForge ID check (2026-11-13; tested with Http::fake() only)

Status of game ID 432 / class ID 4471: **checked by code, not yet confirmed against the live API** (no real key was available).

- Defined once, as `CurseForgeClient::GAME_ID_MINECRAFT` and `CurseForgeClient::CLASS_ID_MODPACKS`. Nothing else may hardcode them.
- `CurseForgeClient::checkIds(?string $apiKey)` calls `GET /v1/games/432` and `GET /v1/categories?gameId=432&classesOnly=true`
  and returns an `IdCheckResult` (`status` + `message`), never throws. Statuses: `ok`; `mismatch` (game is not Minecraft,
  class 4471 missing, or not named Modpacks / not `isClass` / other gameId; names a `modpacks`-slug class under another ID
  if one exists); `unexpected` (no data); `auth` (401/403); `unreachable` (connection error/timeout, 10 s / 5 s connect);
  `http_error` (other non-200); `no_key`. Messages quote what CurseForge returned, never the key.
- Runs when an admin saves a non-blank key, after the key is stored (never blocks saving). The result is rendered in the
  save response as a success or warning alert (escaped). It is not put in the session (changed 2026-11-14: it quotes
  names CurseForge returned, and API data must not be stored). A blank save skips it.
- Assumed response shapes (from the Core API schema, not seen live): Game `{id,name,slug}`, Category `{id,gameId,name,slug,isClass}`.
  The game passes on slug `minecraft` or name `Minecraft`; the class on slug `modpacks` or name `Modpacks`.
- Tests: `tests/` is a dev-only harness (Orchestra Testbench 9 / Laravel 11, PHPUnit 11; stubs for the Blueprint libraries
  and panel base classes in `tests/stubs`). Not part of the extension: install/`-build` do not install it, and exports exclude it
  (see "Exports exclude tests/").
  `composer install -d tests && tests/vendor/bin/phpunit -c tests/phpunit.xml`. `Http::preventStrayRequests()` is on, so no test
  reaches the network. Covers: correct response, wrong class name, class missing (with and without a Modpacks class elsewhere),
  not-a-class, wrong game, 401/403 on either call, network failure, 503, non-JSON, odd field types, stored vs given key, no key,
  and the admin save (key stored in every case, success/warning rendered, HTML escaped, key not echoed, blank save sends nothing,
  nothing from CurseForge in the session, key removal).
- To confirm for real: save a real key on the admin page and read the alert. Then update the status above.

## Exports exclude tests/ (2026-11-13, verified on the test panel)

`blueprint -export` copies the whole dev folder (`cp -r dev/* tmp/`) and zips it, excluding only `.dist`, `.git`, `.gitkeep`.
Without help, `tests/` (with its 8,760-line `composer.lock`, and `tests/vendor` if installed) would ship in the `.blueprint` package.

- `conf.yml` `data.directory: "private"` holds only `private/export.sh`. Blueprint runs it only from `blueprint -export`, after
  the copy and before the zip, with cwd and `BLUEPRINT_EXPORT_DIRECTORY` = `<panel>/.blueprint/tmp`. It deletes `tests/` there and nothing else.
- Flag `developerEscalateExportScript` is needed: Blueprint runs as root, so the copy is root-owned and the default
  (www-data) hook cannot delete it (observed: the export then still contained `tests/`). The flag only affects export.
- Safety: the hook deletes nothing unless the realpath of the export directory equals `$PTERODACTYL_DIRECTORY/.blueprint/tmp`,
  equals the cwd, is not `.blueprint/dev`, has no `.git`, holds this extension's `conf.yml`, and `EXTENSION_IDENTIFIER` is
  `modpackinstaller`. A symlinked `tests` is unlinked, not followed. Checked with a fake panel tree: the dev folder,
  the git checkout, unset env, wrong identifier, `.git`, `tmp` symlinked to `dev`: all refused, nothing deleted.
- Blueprint zips even if the hook fails, so a refusal is only a loud message. After exporting, check:
  `unzip -l modpackinstaller.blueprint | grep tests/` must print nothing.
- Verified: real export lists only `admin/`, `app/`, `dashboard/`, `routers/`, `private/export.sh`, `conf.yml`, `README.md`,
  `CLAUDE.md` (no `tests/`, `tests/vendor`, `composer.lock`); `tests/` unchanged (sha256) in the dev folder and in git afterwards.
  `-build` and `-install` of the exported package never ran the hook; install copies `export.sh` into
  `.blueprint/extensions/modpackinstaller/private/` but no Blueprint command other than export runs it.
- Blueprint quirk (not ours): export leaves an empty `.blueprint/tmp`, and the next `-build` then copies the dev folder into
  `tmp/dev/` and fails with "Extension configuration file not found". That failure removes `tmp`; the build after it works.

## Terms compliance (2026-11-14)

Compliance pass against the CurseForge 3rd Party API Terms and Conditions. Not legal advice. No real CurseForge API
call was made; all API behaviour was tested with `Http::fake()`.

Sources, fetched as raw pages with curl and read in full on 2026-11-14 (READ; quotes below are exact):
- T: CurseForge 3rd Party API Terms and Conditions ("Modified on: Wed, 14 Aug, 2024"),
  https://support.curseforge.com/en/support/solutions/articles/9000207405-curse-forge-3rd-party-api-terms-and-conditions
  The page text has no printed section numbers; they come from its numbered list (1 Definitions, 2 Use of Platform API,
  3 Restrictions and Obligations of Developer, 4 Representations and Warranties, 5 Intellectual Property Rights,
  6 No Warranty, 7 Indemnification, 8 Term and Termination, 9 Effects of Termination, 10 Survival, 11 Publicity,
  12 Miscellaneous). The first paragraph of section 3 (3.1) letters two items "(d)" and two items "(e)".
- A: About the CurseForge API and How to Apply for a Key ("Modified on: Tue, 3 Dec, 2024"),
  https://support.curseforge.com/support/solutions/articles/9000208346-about-the-curseforge-api-and-how-to-apply-for-a-key
  Its link "3rd Party Developers API Terms of Service" points to T. There is no separate document behind that name.
- D: Project Distribution Toggle ("Modified on: Thu, 31 Oct, 2024"),
  https://support.curseforge.com/en/support/solutions/articles/9000207877-project-distribution-toggle
  It names no API field. `allowModDistribution` ("Is mod allowed to be distributed", boolean or null) is from the
  Mod schema on docs.curseforge.com.
- The application form itself (forms.monday.com) was not opened.

| # | Requirement (exact quote) | Where it matters | Status | Evidence / fix |
|---|---|---|---|---|
| 1 | T 3.1: Developer shall not "save or cache any data obtained through the API or SDK" | `app/CurseForgeClient.php` (all calls), `app/ModpackInstallerController.php`, `admin/controller.php`, `dashboard/ModpackInstaller.tsx` | Compliant after fix, for API responses | Was not compliant in one place: the ID check result (game and class names from CurseForge) was flashed to the session. Now rendered in the save response. Whole repo searched: no Cache, DB, Log, Storage, file or browser-storage call touches API data; the only stored value is the API key. JSON responses now send `Cache-Control: no-store`. `TermsComplianceTest` and `AdminSaveTest` fail on any write (RAN). |
| 2 | T 3.1, same quote, applied to the server pack file itself | `app/WingsPackInstaller.php` | **Unclear: needs CurseForge's answer** | The file is saved to the user's own server on request; that is the product. Behaviour unchanged. |
| 3 | T 2.3: "Upon the External App exceeding a certain quota, to be decided and updated from time to time" | `dashboard/ModpackInstaller.tsx` (search), `CurseForgeClient::get()` | Compliant after fix (quota itself is not published) | Was: one search on every page load and on every typing pause. Now: no request on page load; typing searches from 3 characters after 400 ms; Search button or Enter otherwise; an identical in-flight search is not sent twice. Calls per action: search 1, lookup 2, install 2, key save 2. No retry loops anywhere (READ). 429 gives a message with `Retry-After` and is not retried (RAN with fakes). |
| 4 | T 2.2: the API Key "is non-transferable and may not be shared with any third party. Developer may not disclose the API Key to any third party" | `admin/controller.php`, `admin/view.blade.php`, `CurseForgeClient::http()`, README | Compliant in code; distribution model needs the user's decision | No key in the repo. Entered per installation, never echoed, sent only to `api.curseforge.com` (test asserts it is not sent to the CDN hosts), not logged. Stored unencrypted in the `settings` table (documented). Whether every panel admin applying for their own key is what CurseForge expects: ask CurseForge. |
| 5 | T 9: on termination "promptly delete or destroy all copies of any API Key" | `admin/controller.php` | Compliant after fix | The admin page could not remove a key (a blank save kept it). Added "Remove the saved key" (`dbForget`). Database backups are the operator's job. |
| 6 | D: authors choose whether "3rd party developers can or cannot distribute your project information and files"; A: reviewers look at "How it takes Authors' consent when it comes to third party distribution of their creations" | `CurseForgeClient::resolveModpack()`, `serverPackDownload()`, `searchModpacks()` | Compliant after fix | Was: `allowModDistribution` unused; on a missing `downloadUrl` the code made a second attempt through `/download-url`. Now: explicit `false` is refused at lookup and again at install (before the file is looked up), with a link to the project page; an empty `downloadUrl` is refused the same way and the second attempt is gone. Search results flag such packs and clicking one makes no request. RAN with fakes. Real API behaviour for such packs: not seen. |
| 7 | T 3.1: shall not "circumvent any security mechanisms of the Platform, Platform API and/or any SDK" | whole repo | Compliant | No scraping, no guessed URL patterns, no user-agent override (READ: the only hosts contacted are `api.curseforge.com` and, by HEAD, the CDN URL the API returned). |
| 8 | T 3.1: shall not "conceal your identity or geographic location when accessing the API, including accessing the API through a proxy server or VPN" | deployment, README | Unclear: operator's responsibility | The code sets no proxy. A panel whose outgoing traffic goes through a proxy or VPN would breach this. Stated in the README. |
| 9 | T 3.1: shall not "remove or obscure any copyright or other notices from the Platform, Platform API and/or any SDK" | extraction, UI | Compliant | Server packs are extracted unchanged. UI now shows authors and a link to the project page. |
| 10 | T 3.1: shall not "rent, lease, modify, copy, loan, transfer, sublicense, distribute or create derivative works of the Platform, Platform API and/or any SDK" | repo contents | Compliant | The repo contains no CurseForge code, SDK or documentation copy. Files are not re-hosted or mirrored; the archive is deleted after extraction (RAN earlier on Wings) and kept only if extraction fails. |
| 11 | T 3.1: shall not use the API "in order build any product or service that competes, directly or indirectly, with CF, CurseForge for Studios, or the Platform"; T 1: an External App must provide "substantial functionality above and beyond the functionality and materials provided through the Platform API" | the project as a whole | **Unclear: CurseForge's judgement** | Not something code can settle. |
| 12 | T 3.1: "All Platform APIs and SDKs must be used in accordance with applicable documentation." | `CurseForgeClient.php` | Compliant as far as READ | Endpoints, parameters and the 50 / 10,000 paging limits match docs.curseforge.com. |
| 13 | T 3.2(d): no External App shall "include, or transmit any virus, worm, adware, malware, or other malicious software" | install flow | Unclear | The extension passes on files CurseForge hosts and does not scan them; it does not verify hashes (Wings has no checksum endpoint). |
| 14 | T 12 (Relationship of Parties): neither party "shall hold itself out as an agent, legal representative, partner, subsidiary, joint venturer, servant or employee of the other"; T 11: Overwolf may reference the Developer and the app | README, admin view, page footer, `conf.yml` name | Compliant after fix; name is the user's decision | Added a non-affiliation line to the README, the admin page and the page footer. No logos are used. The extension's name starts with "CurseForge": the user decides whether to rename. |
| 15 | T 4(b): "all information provided to Overwolf by Developer is accurate and correct, and will be promptly updated" | README, the submitted application | Needs the user | README corrected (it contradicted itself about Wings testing, and suggested checking the terms without saying what they require). The application text was not available to compare with the code. |

Changes made in this pass (all RAN through PHPUnit with faked HTTP unless marked):
- `CurseForgeClient`: `rateLimitMessage()`; `authorNames()`, `projectUrl()` (https on curseforge.com only, else built
  from the slug), `distributionDisabled()`; `resolveModpack()` and `serverPackDownload()` refuse disabled projects with
  `DistributionDisabledException`; `/download-url` fallback removed; the 401/403 message no longer blames only the key.
- `ModpackInstallerController`: error body gains `distributionDisabled` and `projectUrl`; `Cache-Control: no-store`.
- Admin: result rendered without the session; key removal; non-affiliation line.
- React page: no request on page load, 3-character minimum, Search button, duplicate in-flight search dropped, authors
  and CurseForge links, manual-download message with link, footer line, labels and `role="alert"`.
- Tests: `TermsComplianceTest` (15 tests) and four more cases in `AdminSaveTest`. The persistence test uses real file
  cache, file session and file log drivers in a private storage directory, listens for cache, database and log events,
  and searches every stored file for marker strings from the faked responses and for the key. One test proves the
  detector notices a cache write and a log line.

Not covered by tests: the React page's request behaviour (no JS test runner in this repo; checked by type check and by
reading), Wings' own logging of the download URL, and anything about real CurseForge responses.

Open items that code cannot settle:
1. Does saving a server pack onto the user's own server count as "save or cache any data obtained through the API"
   (T 3.1), or as distribution beyond what the author's toggle allows? (CurseForge)
2. Is one key per panel installation, applied for by each administrator, the model CurseForge expects, given T 2.2? (CurseForge)
3. Quota: what volume is acceptable (T 2.3)? (CurseForge)
4. Does CurseForge see this as competing or as lacking "substantial functionality" (row 11)? (CurseForge)
5. Extension name beginning with "CurseForge" (user).
6. Whether a missing `downloadUrl` should stay a hard refusal; removing the `/download-url` attempt is the cautious
   reading and may refuse packs that the API would have served (user, after real-key testing).
7. The text of the submitted application versus this code (user).

## Conventions

- Never expose the API key to the browser. All CurseForge calls stay server-side.
- Never pass user-supplied URLs to Wings. Only URLs returned by CurseForge on allowed hosts.
- Require `file.create` permission on every endpoint.
- Errors returned to the UI are `{ "error": "<message>" }` with HTTP 422.
- **No caching or storing of CurseForge API data.** Nothing from an API response may be written to the cache, the
  database, files, the session, logs, queue payloads or browser storage. No automatic retries, and no API request that
  is not started by a user action. `tests/Unit/TermsComplianceTest.php` enforces this; keep it passing.
- Honour the author's distribution setting at lookup and at install. Never look for another way to get a file that
  CurseForge does not hand out (no scraping, no guessed URLs, no spoofed user agent).
- Do not try to engineer around a CurseForge term. If a term blocks a feature, stop and ask the user.

## Roadmap (after it works)

1. Optional "backup before install" using Pterodactyl's backup service.
2. Optional wipe of the server root (with confirmation and disk-space check).
3. Poll Wings for pull status instead of a blocking `foreground` pull.
4. Per-egg handling: accept EULA, set Java version, adjust startup command for Forge/NeoForge/Fabric.
5. Fewer CurseForge calls, without caching (the API terms forbid saving or caching API data; see "Terms compliance").

# CLAUDE.md

Blueprint extension for Pterodactyl: install CurseForge modpack server packs onto a server.
Read `README.md` first for scope. Keep scope small: server packs only, ID/URL/slug input.

## Your job

This scaffold was written from memory without a running panel. Make it actually work.

1. Verify every item under "Unverified assumptions" against the current Blueprint docs
   (https://blueprint.zip/docs) and fix the code to match.
2. Get `blueprint -build` passing on a dev panel and fix whatever breaks.
3. Test with 2-3 real packs that have server packs, plus one without a server pack, plus one
   with downloads disabled, and confirm each error path shows a clear message.
4. Then work through the roadmap below.

## Unverified assumptions (check these first)

- `conf.yml`: `target` value, field names, and whether `requests.app: "app"` merges into the panel's `app/` directory.
- Admin controller/view naming: class names, namespace, route name `admin.extensions.modpackinstaller.index`, `dbGet`/`dbSet` signatures.
- Client library import (`BlueprintClientLibrary`) and whether it is injectable in a service class.
- Client router mounting: final URL prefix. The React page assumes
  `/api/client/extensions/modpackinstaller/servers/{uuid}/(resolve|install)`.
- `dashboard/components.yml` schema for registering a server subpage, and how a `.tsx` file is wired in.
- `DaemonFileRepository::pull()` params (`filename`, `use_header`, `foreground`) and `decompressFile()` in the installed Pterodactyl version.
- CurseForge: game ID 432, modpack class ID 4471, `serverPackFileId` on file objects, `downloadUrl` possibly null.
- The CDN host allowlist in the install controller (`forgecdn.net`, `curseforge.com`) is not too strict.

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

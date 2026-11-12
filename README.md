# CurseForge Modpack Installer (Pterodactyl / Blueprint extension)

Adds a "Modpack Installer" page to each server in Pterodactyl. A user pastes a CurseForge
modpack URL, slug or project ID (or searches and picks from the popular list), picks a version, and the extension downloads the modpack's
official **server pack** straight onto the server (via Wings) and extracts it.

**Status: v0.1. Builds and runs on Pterodactyl 1.15.2 + Blueprint beta-2026-08. Not yet tested with Wings or a real
CurseForge API key**, so no real download or extraction has happened. See `CLAUDE.md` ("Run record") for what was tested.

## Scope (deliberately small)

- Only modpacks that ship an official server pack (`serverPackFileId` set).
- Input is a search (most popular first, paginated), or a project ID, slug or URL as a fallback.
- The "server pack" badge in search results only reflects the pack's `latestFiles`; it can be absent for a pack that has older server packs. Selecting a pack always checks the full file list.
- No loader installation or startup command changes. The server pack's own scripts/egg handle that.

## Layout

| Path | Purpose |
|---|---|
| `conf.yml` | Blueprint extension manifest |
| `admin/` | Admin page for the CurseForge API key |
| `app/CurseForgeClient.php` | CurseForge API wrapper (namespace `Pterodactyl\BlueprintFramework\Extensions\modpackinstaller`, symlinked by Blueprint) |
| `app/ModpackInstallerController.php` | `search`, `resolve` and `install` endpoints |
| `routers/client.php` | Server-scoped routes |
| `dashboard/` | React page and `Components.yml` registration (this directory is `dashboard.components`) |

## Install (dev)

1. Install Blueprint on your panel and enable developer mode.
2. Copy this repo into the panel's Blueprint dev folder (`.blueprint/dev`), then run `blueprint -build`.
3. Open Admin > Extensions > CurseForge Modpack Installer and enter your CurseForge API key.
4. Open a server and use the "Modpack Installer" page.

## API (all under `/api/client/extensions/modpackinstaller/servers/{server}`)

All endpoints are POST, require the `file.create` server permission, and return errors as
`{"error": "..."}` with HTTP 422 (403 for a missing permission). The CurseForge key never leaves the panel.

| Endpoint | Body | Returns |
|---|---|---|
| `/search` | `query` (optional, max 100), `index` (default 0), `page_size` (1-50, default 20) | `results[]` (`id`, `name`, `slug`, `summary`, `thumbnailUrl`, `downloadCount`, `hasServerPack`), `index`, `pageSize`, `totalCount`, `hasMore`. Calls CurseForge `/v1/mods/search` with gameId 432, classId 4471, sorted by popularity. |
| `/resolve` | `query` (ID, URL or slug) | `modpack`, `versions[]` that have a server pack |
| `/install` | `mod_id`, `server_pack_file_id` | `{status, file}` after Wings downloads and extracts the pack |

## Verify on a dev panel

`blueprint -build` passes and the admin page, routes, autoloading and server page were checked on a real panel
(see `CLAUDE.md`, "Run record (phase 2)"). Not yet done: real CurseForge data and a real Wings download/extraction.

Note: don't use Blueprint's `{appcontext}` placeholder in this extension. On beta-2026-08 its sed replacement drops the
backslashes, so the namespace is written out literally.

Suggested steps (Blueprint Docker dev setup: https://blueprint.zip/guides/dev/docker):

```bash
# In the panel webroot, with Blueprint installed
mkdir -p .blueprint/dev
cp -r /path/to/pterodactyl-curseforge-installer/. .blueprint/dev/   # conf.yml must be at the top of dev/
blueprint -build                                                     # watch for errors in routes.ts and PHP
# Admin > Extensions > CurseForge Modpack Installer: save an API key
# Open a server: /server/<id>/modpack-installer
php artisan route:list | grep modpackinstaller                       # expect search, resolve, install
```

Then check:
- Search loads, debounces, paginates; clicking a result fills the version list.
- Install 2-3 packs with server packs, one without a server pack, one with third-party downloads disabled; each error is a clear message.
- A large pack finishes within your web server / PHP-FPM timeouts (the install call blocks).
- A subuser without `file.create` gets 403 and does not see the nav entry.

Only verified by reading docs, never executed: Wings pull/decompress behaviour against real files, CurseForge response
shapes on real data (game 432, class 4471, nullable `downloadUrl`, forgecdn host names, `pagination.totalCount`).

## Known limits

- Authors can disable third-party downloads; those packs return an error with a manual-download hint.
- The install call blocks until Wings finishes the download and extraction. The browser waits up to 15 minutes, but the
  official Pterodactyl nginx config sets `fastcgi_read_timeout 300`, so a pack that takes longer gets a 504 while the
  install continues on the node.
- Extraction can overwrite existing files. The UI warns, but there is no automatic backup yet.
- Check CurseForge's API terms to confirm your use case is allowed.

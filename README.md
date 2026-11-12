# CurseForge Modpack Installer (Pterodactyl / Blueprint extension)

Adds a "Modpack Installer" page to each server in Pterodactyl. A user pastes a CurseForge
modpack URL, slug or project ID (or searches and picks from the popular list), picks a version, and the extension downloads the modpack's
official **server pack** straight onto the server (via Wings) and extracts it.

**Status: v0.1 scaffold. Not yet run against a real panel.** See `CLAUDE.md` for what still needs verifying.

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
| `app/CurseForgeClient.php` | CurseForge API wrapper (namespace `{appcontext}`, symlinked by Blueprint) |
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

`blueprint -build` has **not been run** against this code. The sandbox it was written in could not reach Packagist,
had no running Docker daemon and no MySQL/MariaDB, so no panel could be stood up. Everything below was only checked by
reading the Blueprint, Pterodactyl 1.15.2, Wings and CurseForge sources and docs (see `CLAUDE.md`, "Verification record"),
plus `php -l` on the PHP files (with `{appcontext}` substituted) and a syntax-only TypeScript transpile of the React page.

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
- Build output: no fatal about `Permission` / `routes.ts` (if there is, remove `Permission` from `dashboard/Components.yml`).
- Classes autoload from `app/BlueprintFramework/Extensions/modpackinstaller` (no "class not found").
- Search loads, debounces, paginates; clicking a result fills the version list.
- Install 2-3 packs with server packs, one without a server pack, one with third-party downloads disabled; each error is a clear message.
- A large pack finishes within your web server / PHP-FPM timeouts (the install call blocks).
- A subuser without `file.create` gets 403 and does not see the nav entry.

Only verified by reading docs, never executed: conf.yml field names, `{appcontext}` substitution and symlink, route
mounting and prefix, `Components.yml` to `routes.ts` wiring (including the quoted `Permission`), admin controller
discovery and PATCH handling, Wings pull/decompress behaviour, CurseForge response shapes (game 432, class 4471,
nullable `downloadUrl`, forgecdn host names).

## Known limits

- Authors can disable third-party downloads; those packs return an error with a manual-download hint.
- The install call blocks until Wings finishes the download, so very large packs may time out.
- Extraction can overwrite existing files. The UI warns, but there is no automatic backup yet.
- Check CurseForge's API terms to confirm your use case is allowed.

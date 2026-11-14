# CurseForge Modpack Installer (Pterodactyl / Blueprint extension)

Adds a "Modpack Installer" page to each server in Pterodactyl. A user searches for a Minecraft modpack (or pastes a
CurseForge modpack URL, slug or project ID), picks a version, and the extension has the server's own node (Wings)
download the modpack's official **server pack** from CurseForge onto that server and extract it.

This extension is an independent project. It is **not affiliated with, endorsed by or sponsored by CurseForge or
Overwolf**. "CurseForge" is used only to say which service it talks to. See [Terms and compliance](#terms-and-compliance).

**Status: v0.1. Builds and runs on Pterodactyl 1.15.2 + Blueprint beta-2026-08 + Wings 1.13.5. Not yet tested with a real
CurseForge API key**: Wings download and extraction were tested with locally served sample zips only. See `CLAUDE.md` ("Run record").

## Scope (deliberately small)

- Only modpacks that ship an official server pack (`serverPackFileId` set).
- Input is a search (most popular first, paginated), or a project ID, slug or URL as a fallback.
- Nothing is requested from CurseForge when the page opens. Typing searches after a pause from 3 characters; the
  Search button (or Enter) searches right away, and with an empty box lists the most popular packs.
- Search results and the version view show the pack's authors and a link to its CurseForge page.
- Packs whose author has disabled third-party distribution are not downloaded. The page says so and links to the
  pack's CurseForge page for a manual download.
- The "server pack" badge in search results only reflects the pack's `latestFiles`; it can be absent for a pack that has older server packs. Selecting a pack always checks the full file list.
- No loader installation or startup command changes. The server pack's own scripts/egg handle that.

## Layout

| Path | Purpose |
|---|---|
| `conf.yml` | Blueprint extension manifest |
| `admin/` | Admin page for the CurseForge API key |
| `app/CurseForgeClient.php` | CurseForge API wrapper (namespace `Pterodactyl\BlueprintFramework\Extensions\modpackinstaller`, symlinked by Blueprint) |
| `app/ModpackInstallerController.php` | `search`, `resolve` and `install` endpoints |
| `app/IdCheckResult.php` | Result of the game/class ID check run when an admin saves an API key |
| `app/DistributionDisabledException.php` | Raised when the author has disabled third-party distribution; carries the project page URL |
| `app/WingsPackInstaller.php` | Has Wings pull the archive into the server root, extract it, then delete the archive |
| `routers/client.php` | Server-scoped routes |
| `dashboard/` | React page and `Components.yml` registration (this directory is `dashboard.components`) |
| `tests/` | Dev-only PHPUnit harness (`Http::fake()`, no network). Not installed by Blueprint, and removed from `blueprint -export` packages by `private/export.sh`. |
| `private/export.sh` | Export hook (`data.directory`): deletes `tests/` from Blueprint's temporary export copy only |

## Install (dev)

1. Install Blueprint on your panel and enable developer mode.
2. Copy this repo into the panel's Blueprint dev folder (`.blueprint/dev`), then run `blueprint -build`.
3. Open Admin > Extensions > CurseForge Modpack Installer and enter **your own** CurseForge API key (apply for one
   with CurseForge; do not use someone else's). Saving checks the hardcoded game ID (432, Minecraft) and class ID
   (4471, Modpacks) against CurseForge and shows the result; a warning does not stop the key from being saved.
   The same page can remove the saved key.
4. Open a server and use the "Modpack Installer" page.

## API (all under `/api/client/extensions/modpackinstaller/servers/{server}`)

All endpoints are POST, require the `file.create` server permission, and return errors as
`{"error": "..."}` with HTTP 422 (403 for a missing permission). The CurseForge key never leaves the panel.
Responses carry `Cache-Control: no-store`. When the author has disabled third-party distribution the error body also
has `distributionDisabled: true` and `projectUrl`. A CurseForge rate limit (HTTP 429) is reported as an error that
includes CurseForge's `Retry-After` value; the extension never retries on its own.

| Endpoint | Body | Returns |
|---|---|---|
| `/search` | `query` (optional, max 100), `index` (default 0), `page_size` (1-50, default 20) | `results[]` (`id`, `name`, `slug`, `summary`, `thumbnailUrl`, `downloadCount`, `hasServerPack`, `authors`, `projectUrl`, `distributionDisabled`), `index`, `pageSize`, `totalCount`, `hasMore`. Calls CurseForge `/v1/mods/search` with gameId 432, classId 4471, sorted by popularity. |
| `/resolve` | `query` (ID, URL or slug) | `modpack` (`id`, `name`, `slug`, `authors`, `projectUrl`), `versions[]` that have a server pack. Refused when distribution is disabled. |
| `/install` | `mod_id`, `server_pack_file_id` | `{status, file}` after Wings downloads and extracts the pack. Checks the distribution setting again first. |

## Verify on a dev panel

`blueprint -build` passes and the admin page, routes, autoloading and server page were checked on a real panel
(see `CLAUDE.md`, "Run record"). Not yet done: anything that needs a real CurseForge API key.

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
- Nothing loads until you type 3 characters or press Search; search debounces and paginates; clicking a result fills the version list.
- Install 2-3 packs with server packs, one without a server pack, one with third-party downloads disabled; each error is a clear message.
- A large pack finishes within your web server / PHP-FPM timeouts (the install call blocks).
- A subuser without `file.create` gets 403 and does not see the nav entry.

Never run against the real CurseForge API (no key yet): response shapes on real data, game 432 and class 4471,
what CurseForge returns for a pack with distribution disabled, the forgecdn host names and redirect chain, and an
install of a real pack. Wings download and extraction were run with locally served sample archives only.

## Known limits

- Authors can disable third-party distribution; those packs are refused at lookup and again at install, with a link
  to the pack's CurseForge page. If CurseForge withholds a file's download URL the pack is refused the same way; the
  extension does not try any other way of getting the file.
- The install call blocks until Wings finishes the download and extraction. Measured on a test VM:
  - nginx (`fastcgi_read_timeout 300` in the official config) returns 504 at 300 s. PHP keeps going and the install
    finishes; the UI says the install may still be running and to check the Files tab.
  - The panel stops waiting for the download after 600 s. Wings keeps downloading and leaves the archive in the server
    root unextracted; the UI says so. Extract it from the Files tab.
  - Raising `fastcgi_read_timeout` on the panel's nginx avoids the 504 for slow nodes.
- Downloads go through Wings, which refuses redirects and private/internal addresses. CurseForge's `edge.forgecdn.net`
  redirects, so the panel resolves CDN redirects first (every hop must be https on forgecdn.net/curseforge.com).
- Search, resolve and install count against the panel's per-user client API rate limit (256 requests/minute by default),
  shared with the rest of the panel UI.
- Extraction can overwrite existing files. The UI warns, but there is no automatic backup yet.
- The downloaded archive is deleted after a successful extraction and kept if extraction fails.

## Terms and compliance

Use of the CurseForge API is governed by the
[CurseForge 3rd Party API Terms and Conditions](https://support.curseforge.com/en/support/solutions/articles/9000207405-curse-forge-3rd-party-api-terms-and-conditions).
You need your own API key and you are responsible for following those terms. This is a description of what the code
does, not legal advice. **Confirmation from CurseForge that this use is acceptable is still pending** (see the open
question below).

What the extension does:

- Calls the CurseForge API only from the panel's backend, and only after something the user did: a search, a lookup,
  an install, or an administrator saving a key. Nothing is requested when a page opens, and nothing is retried
  automatically. A rate limit (HTTP 429) is shown to the user with CurseForge's `Retry-After` value.
- Keeps the API key on the panel's backend. It is sent only to `api.curseforge.com`, never to the browser, never
  written to logs, and can be removed on the admin page. It is stored unencrypted in the panel's `settings` table,
  so protect your database and its backups.
- Uses one key per installation. No key ships with this extension. A key is personal: the terms say it "may not be
  shared with any third party".
- Honours authors' distribution settings. If a project has third-party distribution switched off
  (`allowModDistribution` is false), or CurseForge withholds the file's download URL, nothing is downloaded and the
  user is pointed to the project's CurseForge page.
- Shows each pack's authors and links to its CurseForge page.
- Downloads a server pack only on an explicit, confirmed action by a user who may create files on that server, only
  from https URLs on `forgecdn.net` / `curseforge.com` that the API returned, and only onto that user's own server.
  The downloaded archive is deleted after a successful extraction.

What it does not do:

- It does not save or cache data from the API: no cache, no database rows, no files, no session data, no log
  entries, no browser storage. Responses are passed to the page with `Cache-Control: no-store` and kept only in the
  page's memory. Automated tests fail if a search, lookup or install stores anything.
- It does not re-host, mirror or redistribute files, and does not serve them to anyone else.
- It does not scrape curseforge.com, guess download URLs, or work around a refusal.
- It does not change or strip anything inside a server pack, including licence and credit files.

Things that are outside this extension's control, which you should know about:

- Wings writes the download URL to its own log on the node when it starts a download.
- If extraction fails, the downloaded archive stays in the server's files so the user can inspect it.
- The terms forbid reaching the API "through a proxy server or VPN". The panel calls the API directly; do not route
  the panel's outgoing traffic through a proxy or VPN.

Open question, not yet answered by CurseForge: the terms forbid to "save or cache any data obtained through the API".
The whole purpose of this extension is to put a server pack file, on the user's request, onto that user's own server.
Whether that counts as saving API data, or as distribution that needs more than the author's toggle, is for CurseForge
to say.

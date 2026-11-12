# CurseForge Modpack Installer (Pterodactyl / Blueprint extension)

Adds a "Modpack Installer" page to each server in Pterodactyl. A user pastes a CurseForge
modpack URL, slug or project ID, picks a version, and the extension downloads the modpack's
official **server pack** straight onto the server (via Wings) and extracts it.

**Status: v0.1 scaffold. Not yet run against a real panel.** See `CLAUDE.md` for what still needs verifying.

## Scope (deliberately small)

- Only modpacks that ship an official server pack (`serverPackFileId` set).
- Input is a project ID, slug or URL. No browsing or search UI yet.
- No loader installation or startup command changes. The server pack's own scripts/egg handle that.

## Layout

| Path | Purpose |
|---|---|
| `conf.yml` | Blueprint extension manifest |
| `admin/` | Admin page for the CurseForge API key |
| `app/Services/ModpackInstaller/CurseForgeClient.php` | CurseForge API wrapper |
| `app/Http/Controllers/Api/Client/Extensions/ModpackInstaller/` | `resolve` and `install` endpoints |
| `routers/client.php` | Server-scoped routes |
| `dashboard/` | React page and `components.yml` registration |

## Install (dev)

1. Install Blueprint on your panel and enable developer mode.
2. Copy this repo into the panel's Blueprint dev folder (`.blueprint/dev`), then run `blueprint -build`.
3. Open Admin > Extensions > CurseForge Modpack Installer and enter your CurseForge API key.
4. Open a server and use the "Modpack Installer" page.

## Known limits

- Authors can disable third-party downloads; those packs return an error with a manual-download hint.
- The install call blocks until Wings finishes the download, so very large packs may time out.
- Extraction can overwrite existing files. The UI warns, but there is no automatic backup yet.
- Check CurseForge's API terms to confirm your use case is allowed.

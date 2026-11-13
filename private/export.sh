#!/bin/bash
# Blueprint export hook: keeps the dev-only test harness (tests/) out of the exported .blueprint package.
#
# Blueprint runs this only from `blueprint -export`, after copying .blueprint/dev/* into .blueprint/tmp and before
# zipping .blueprint/tmp. It is not run by install, -build, update or remove (those only run private/install.sh,
# private/update.sh and private/remove.sh). It deletes tests/ from that temporary copy and nothing else.
#
# Every check below must pass, otherwise it deletes nothing. Blueprint zips regardless of this script's exit code,
# so a refusal is printed loudly: check the export with `unzip -l modpackinstaller.blueprint | grep tests/`.

refuse() {
  echo "modpackinstaller export hook: NOT removing tests/ from the export ($1). The package will contain tests/." >&2
  exit 1
}

[[ -n "${BLUEPRINT_EXPORT_DIRECTORY:-}" ]] || refuse "BLUEPRINT_EXPORT_DIRECTORY is not set"
[[ -n "${PTERODACTYL_DIRECTORY:-}" ]] || refuse "PTERODACTYL_DIRECTORY is not set"
[[ "${EXTENSION_IDENTIFIER:-}" == "modpackinstaller" ]] || refuse "unexpected extension identifier '${EXTENSION_IDENTIFIER:-}'"

target="$(realpath -e -- "$BLUEPRINT_EXPORT_DIRECTORY" 2>/dev/null)" || refuse "export directory does not exist"
expected="$(realpath -e -- "$PTERODACTYL_DIRECTORY/.blueprint/tmp" 2>/dev/null)" || refuse ".blueprint/tmp does not exist"
dev="$(realpath -m -- "$PTERODACTYL_DIRECTORY/.blueprint/dev")"
here="$(realpath -e -- "$PWD")"

# Only Blueprint's temporary export copy: never the dev folder, a git checkout, or anything else.
[[ "$target" == "$expected" ]] || refuse "export directory '$target' is not '$expected'"
[[ "$here" == "$target" ]] || refuse "working directory '$here' is not the export directory"
[[ "$target" != "$dev" ]] || refuse "export directory is the dev folder"
[[ ! -e "$target/.git" ]] || refuse "export directory contains .git"
grep -qE '^[[:space:]]*identifier:[[:space:]]*"?modpackinstaller"?[[:space:]]*$' "$target/conf.yml" 2>/dev/null \
  || refuse "export directory does not hold this extension's conf.yml"

if [[ -L "$target/tests" ]]; then
  rm -f -- "$target/tests"          # a symlink: remove the link only, never what it points to
elif [[ -e "$target/tests" ]]; then
  rm -rf --one-file-system -- "$target/tests"
fi

[[ ! -e "$target/tests" ]] || refuse "tests/ is still present after removal"
echo "modpackinstaller export hook: removed tests/ from $target (the export copy only)."

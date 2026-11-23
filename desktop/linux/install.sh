#!/bin/sh
set -eu
SOURCE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
python3 -c 'import gi; gi.require_version("Gtk", "3.0"); gi.require_version("WebKit2", "4.1"); gi.require_version("Notify", "0.7"); from gi.repository import WebKit2; assert hasattr(WebKit2.UserContentManager, "register_script_message_handler_with_reply")' || {
    echo 'Install the dependencies listed in README.md first.' >&2
    exit 1
}
DEST="${XDG_DATA_HOME:-$HOME/.local/share}/todoom-desktop"
BIN="$HOME/.local/bin"
APPS="${XDG_DATA_HOME:-$HOME/.local/share}/applications"
ICONS="${XDG_DATA_HOME:-$HOME/.local/share}/icons/hicolor/scalable/apps"
mkdir -p "$DEST" "$BIN" "$APPS" "$ICONS"
install -m 644 "$SOURCE/todoom.py" "$DEST/todoom.py"
for asset in todoom.svg todoom-attention.svg todoom.png todoom-attention.png reminders.js; do
    install -m 644 "$SOURCE/$asset" "$DEST/$asset"
done
install -m 644 "$SOURCE/todoom.svg" "$ICONS/space.quantumnet.todoom.Desktop.svg"
PNG_ICONS="${XDG_DATA_HOME:-$HOME/.local/share}/icons/hicolor/256x256/apps"
mkdir -p "$PNG_ICONS"
install -m 644 "$SOURCE/todoom.png" "$PNG_ICONS/space.quantumnet.todoom.Desktop.png"
python3 - "$DEST" "$BIN" "$APPS" <<'PY'
import pathlib, shlex, sys
dest, bin_dir, apps = map(pathlib.Path, sys.argv[1:])
launcher = bin_dir / 'todoom'
launcher.write_text('#!/bin/sh\nexec /usr/bin/env python3 ' + shlex.quote(str(dest / 'todoom.py')) + ' "$@"\n')
launcher.chmod(0o755)
# Desktop Exec uses double-quote escaping rather than shell quoting.
quoted = str(launcher).replace('\\', '\\\\').replace('"', '\\"').replace('`', '\\`').replace('$', '\\$').replace('%', '%%')
(apps / 'space.quantumnet.todoom.desktop').unlink(missing_ok=True)
(apps / 'space.quantumnet.todoom.Desktop.desktop').write_text(
    '[Desktop Entry]\nType=Application\nName=Todoom\nComment=Your tasks on your Todoom server\n'
    f'Exec="{quoted}"\nIcon=space.quantumnet.todoom.Desktop\nTerminal=false\nCategories=Office;ProjectManagement;\nStartupNotify=true\nStartupWMClass=space.quantumnet.todoom.Desktop\nDBusActivatable=false\n')
PY
if command -v update-desktop-database >/dev/null 2>&1; then
    update-desktop-database "$APPS" || true
fi
if command -v gtk-update-icon-cache >/dev/null 2>&1; then
    gtk-update-icon-cache -f -t "${XDG_DATA_HOME:-$HOME/.local/share}/icons/hicolor" || true
fi
echo 'Todoom installed. Open Todoom from your application menu.'
echo 'Choose your server on first launch, or use the Server button later.'

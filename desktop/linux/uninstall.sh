#!/bin/sh
set -eu
rm -f "$HOME/.local/bin/todoom"
rm -f "${XDG_DATA_HOME:-$HOME/.local/share}/applications/space.quantumnet.todoom.desktop"
rm -f "${XDG_DATA_HOME:-$HOME/.local/share}/applications/space.quantumnet.todoom.Desktop.desktop"
rm -f "${XDG_CONFIG_HOME:-$HOME/.config}/autostart/space.quantumnet.todoom.Desktop.desktop"
rm -f "${XDG_DATA_HOME:-$HOME/.local/share}/icons/hicolor/scalable/apps/space.quantumnet.todoom.svg"
rm -f "${XDG_DATA_HOME:-$HOME/.local/share}/icons/hicolor/scalable/apps/space.quantumnet.todoom.Desktop.svg"
rm -f "${XDG_DATA_HOME:-$HOME/.local/share}/icons/hicolor/256x256/apps/space.quantumnet.todoom.Desktop.png"
for asset in todoom.py todoom.svg todoom-attention.svg todoom.png todoom-attention.png reminders.js; do
    rm -f "${XDG_DATA_HOME:-$HOME/.local/share}/todoom-desktop/$asset"
done
echo 'Todoom removed. Server settings and login data were retained.'

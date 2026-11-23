# Todoom for Linux

A lightweight GTK desktop application for your existing Todoom server, with an application icon, system tray and native Linux task reminders. Tasks stay on that server and are shared with the web and mobile apps. Internet or LAN access to the server is required. No server modifications are needed.

## Install

Extract the archive, open a terminal in its folder, and run:

```sh
./install.sh
```

Open **Todoom** from your application menu. On first launch, enter your server URL (defaults to `https://todoom.quantumnet.space/`), then sign in with your Todoom account. The **Server** button lets you change it later. Use a complete URL including any port and subdirectory, for example `http://192.168.69.167:8080/todoom/` only if that is where your installation is served. The SSH account is not your Todoom login.

You can also run `~/.local/bin/todoom --server https://your-server.example/` or `~/.local/bin/todoom --configure`.

## Dependencies

Python 3, PyGObject, GTK 3, WebKitGTK 4.1 (2.40 or newer), libnotify and an AppIndicator library are required for all features. They are already installed on the PC used to build this app. Both AyatanaAppIndicator3 and AppIndicator3 are supported.

Arch / CachyOS:

```sh
sudo pacman -S python python-gobject gtk3 webkit2gtk-4.1 libnotify libappindicator-gtk3
```

Debian / Ubuntu with WebKitGTK 4.1 packages:

```sh
sudo apt install python3 python3-gi gir1.2-gtk-3.0 gir1.2-webkit2-4.1 gir1.2-notify-0.7 gir1.2-ayatanaappindicator3-0.1
```

Fedora:

```sh
sudo dnf install python3 python3-gobject gtk3 webkit2gtk4.1 libnotify libayatana-appindicator-gtk3
```

## Behaviour and data

- Normal Todoom login and features are served directly by your existing app, so server updates appear automatically.
- Each server has separate persistent cookies and web storage. Session expiry is controlled by the server.
- Ctrl+R or F5 reloads. External links open in your browser. Downloads offer a save dialog.
- Closing the window keeps Todoom running in the tray when a tray host is available. Choose **Quit Todoom** to exit completely. On desktops without a tray host, closing exits normally.
- Tray actions: open the app, check tasks now, server settings, test notification, start at login, and quit. A highlighted tray icon and count indicate delivered task reminders; opening Todoom clears this indicator.
- Enable **Notifications** in Todoom's **User Settings** for due-soon and overdue reminders. The app checks every 60 seconds using your authenticated server session, even with its window hidden. It uses the same task rules and acknowledgement endpoint as the mobile app. A reminder already acknowledged by the web or mobile app may not appear again on the PC.
- Notifications show task names, lists and due dates. Clicking one reopens Todoom. Your desktop's Do Not Disturb and notification settings control whether banners appear.
- Keep Todoom running in the tray to receive reminders. No reminders are delivered while the app is fully quit, the PC is asleep, or the server is unreachable. Checks resume when it runs again. The tray menu reports connection or sign-in problems.
- Enable **Start at login** in the tray menu to launch in the background after signing into Linux. Run `~/.local/bin/todoom --background` to do this manually.
- KDE Plasma supports the tray directly. GNOME needs an AppIndicator extension to display tray icons. Without an AppIndicator library, the main app and reminders work while the window stays open.
- Settings: `${XDG_CONFIG_HOME:-~/.config}/todoom/config.json`.
- Login and website data: `${XDG_DATA_HOME:-~/.local/share}/todoom/`.
- Cache: `${XDG_CACHE_HOME:-~/.cache}/todoom/`.
- TLS certificates are validated normally. HTTP is available for LAN servers but sends data unencrypted.

Run `./uninstall.sh` to remove the app. Settings and login data are retained; delete the above Todoom directories manually to reset them.

## Development checks

Run `python3 -m py_compile todoom.py` and `sh -n install.sh uninstall.sh` for syntax checks. `python3 tests/integration.py` runs an opt-in graphical integration test using a local fixture server and isolated settings; it sends one clearly labelled test notification. This checks polling, due-soon and overdue delivery, duplicate suppression, failed-delivery retries, acknowledgements, logout, tray behaviour and autostart settings without accessing real tasks.

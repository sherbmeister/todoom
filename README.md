# Todoom

Safe GitHub package for the Todoom PHP application.

## Setup

Run `composer install` if dependencies are needed, then create a writable `storage/` directory. The live SQLite database, backups, vendor folder, logs, and aaPanel files are intentionally not included.

## Linux desktop app

The [Linux desktop client](desktop/linux/README.md) connects to the same Todoom server as the web and mobile apps. It includes a server selector, application and system tray icons, native due-soon and overdue task reminders, and optional start at login.

Download this repository, then run:

```sh
cd desktop/linux
./install.sh
```

See the [desktop installation instructions](desktop/linux/README.md) for required Linux packages. Open Todoom from your application menu, choose your server, and sign in with your Todoom account. Set User Settings → reminders to Notifications and keep the app running in the tray to receive task alerts.

#!/usr/bin/env python3
"""Todoom Linux desktop client. Uses the existing server's web interface."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import sys
import time
from urllib.parse import urlsplit, urlunsplit

DEFAULT_SERVER = 'https://todoom.quantumnet.space/'
APP_ID = 'space.quantumnet.todoom.Desktop'
ICON_NAME = APP_ID
ASSETS = Path(__file__).resolve().parent
CONFIG = Path(os.environ.get('XDG_CONFIG_HOME', Path.home() / '.config')) / 'todoom/config.json'


def normalize_server(value):
    value = value.strip()
    if '://' not in value:
        value = 'https://' + value
    parts = urlsplit(value)
    if parts.scheme not in ('https', 'http') or not parts.hostname:
        raise ValueError('Enter an HTTP or HTTPS server address.')
    if parts.username or parts.password or parts.query or parts.fragment:
        raise ValueError('Use a server address without passwords, query parameters or fragments.')
    try:
        parts.port
    except ValueError as exc:
        raise ValueError('Enter a valid port number.') from exc
    if any(c.isspace() for c in value):
        raise ValueError('Server addresses cannot contain spaces.')
    return urlunsplit((parts.scheme, parts.netloc, parts.path.rstrip('/') + '/', '', ''))


def save_server(server):
    CONFIG.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    temp = CONFIG.with_suffix('.tmp')
    temp.write_text(json.dumps({'server': normalize_server(server)}, indent=2) + '\n')
    temp.chmod(0o600)
    temp.replace(CONFIG)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--server', help='Save this server address and launch')
    parser.add_argument('--configure', action='store_true', help='Open server settings')
    parser.add_argument('--background', action='store_true', help='Start in the system tray')
    args = parser.parse_args()
    if args.server:
        try:
            save_server(args.server)
        except ValueError as exc:
            parser.error(str(exc))
    try:
        import gi
        gi.require_version('Gtk', '3.0')
        gi.require_version('WebKit2', '4.1')
        gi.require_version('Notify', '0.7')
        gi.require_version('JavaScriptCore', '4.1')
        from gi.repository import Gtk, Gio, GLib, WebKit2, Notify, JavaScriptCore
    except (ImportError, ValueError):
        sys.exit('Missing GTK/WebKit/libnotify libraries. See README.md for installation instructions.')
    # GTK 3 uses the program name as the Wayland app_id. It must match the
    # desktop filename so Plasma can find the icon, including after tray restore.
    GLib.set_prgname(APP_ID)
    GLib.set_application_name('Todoom')
    AppIndicator = None
    for namespace in ('AyatanaAppIndicator3', 'AppIndicator3'):
        try:
            gi.require_version(namespace, '0.1')
            from importlib import import_module
            AppIndicator = import_module('gi.repository.' + namespace)
            break
        except (ImportError, ValueError):
            continue

    class Todoom(Gtk.Application):
        def __init__(self):
            super().__init__(application_id=APP_ID)
            self.window = None
            self.indicator = None
            self.notifications = []
            self.recent_reminders = {}
            self.unread = 0
            Notify.init('Todoom')
            self.server = DEFAULT_SERVER
            self.first_run = not CONFIG.exists()
            try:
                self.server = normalize_server(json.loads(CONFIG.read_text())['server'])
            except (OSError, ValueError, KeyError, TypeError):
                self.first_run = True

        def do_activate(self):
            if self.window:
                self.clear_attention()
                self.window.present()
                return
            Gtk.Window.set_default_icon_name(ICON_NAME)
            self.window = Gtk.ApplicationWindow(application=self, title='Todoom')
            self.window.connect('delete-event', self.close_window)
            self.window.set_default_size(1150, 800)
            self.window.set_icon_from_file(str(ASSETS / 'todoom.svg'))
            header = Gtk.HeaderBar(title='Todoom', show_close_button=True)
            self.window.set_titlebar(header)
            for label, tip, callback in [
                ('↻', 'Reload (Ctrl+R)', lambda *_: self.web.reload()),
                ('Server', 'Change the Todoom server', lambda *_: self.configure()),
            ]:
                button = Gtk.Button(label=label)
                button.set_tooltip_text(tip)
                button.connect('clicked', callback)
                header.pack_end(button)
            outer = Gtk.Box(orientation=Gtk.Orientation.VERTICAL)
            self.window.add(outer)
            self.error = Gtk.Label(label='Unable to connect. Check your connection or change Server.')
            self.error.set_margin_top(12)
            self.error.set_margin_bottom(12)
            self.error.set_no_show_all(True)
            outer.pack_start(self.error, False, False, 0)
            self.content = Gtk.Box(orientation=Gtk.Orientation.VERTICAL)
            outer.pack_start(self.content, True, True, 0)
            self.setup_tray()
            self.open_server()
            action = Gio.SimpleAction.new('reload', None)
            action.connect('activate', lambda *_: self.web.reload())
            self.add_action(action)
            self.set_accels_for_action('app.reload', ['<Primary>r', 'F5'])
            self.window.show_all()
            GLib.timeout_add_seconds(60, self.check_reminders)
            if self.first_run or args.configure:
                self.configure()
            elif args.background and self.tray_host_available():
                self.window.hide()

        def setup_tray(self):
            self.menu = Gtk.Menu()
            self.tray_status = Gtk.MenuItem(label='Sign in to receive task reminders')
            self.tray_status.set_sensitive(False)
            self.menu.append(self.tray_status)
            for label, callback in [
                ('Open Todoom', lambda *_: self.do_activate()),
                ('Check tasks now', lambda *_: self.check_reminders()),
                ('Server settings', lambda *_: (self.do_activate(), self.configure())),
                ('Test notification', lambda *_: self.notify('Todoom notifications', 'Task reminders will appear here. Click to open Todoom.')),
            ]:
                item = Gtk.MenuItem(label=label)
                item.connect('activate', callback)
                self.menu.append(item)
            startup = Gtk.CheckMenuItem(label='Start at login')
            self.autostart = Path(GLib.get_user_config_dir()) / 'autostart' / (APP_ID + '.desktop')
            startup.set_active(self.autostart.exists())
            startup.connect('toggled', self.toggle_autostart)
            self.menu.append(startup)
            self.menu.append(Gtk.SeparatorMenuItem())
            quit_item = Gtk.MenuItem(label='Quit Todoom')
            quit_item.connect('activate', lambda *_: self.quit())
            self.menu.append(quit_item)
            self.menu.show_all()
            if AppIndicator:
                self.indicator = AppIndicator.Indicator.new(APP_ID, str(ASSETS / 'todoom.png'), AppIndicator.IndicatorCategory.APPLICATION_STATUS)
                self.indicator.set_title('Todoom')
                self.indicator.set_icon_theme_path(str(ASSETS))
                self.indicator.set_attention_icon_full(str(ASSETS / 'todoom-attention.png'), 'Todoom task reminders')
                self.indicator.set_menu(self.menu)
                self.indicator.set_status(AppIndicator.IndicatorStatus.ACTIVE)

        def tray_host_available(self):
            if not self.indicator:
                return False
            try:
                bus = Gio.bus_get_sync(Gio.BusType.SESSION, None)
                reply = bus.call_sync('org.kde.StatusNotifierWatcher', '/StatusNotifierWatcher',
                                      'org.freedesktop.DBus.Properties', 'Get',
                                      GLib.Variant('(ss)', ('org.kde.StatusNotifierWatcher', 'IsStatusNotifierHostRegistered')),
                                      GLib.VariantType.new('(v)'), Gio.DBusCallFlags.NONE, 1000, None)
                return bool(reply.unpack()[0])
            except GLib.Error:
                return False

        def close_window(self, *_):
            if self.tray_host_available():
                self.window.hide()
                return True
            return False

        def toggle_autostart(self, item):
            try:
                if item.get_active():
                    self.autostart.parent.mkdir(parents=True, exist_ok=True)
                    filename = str(ASSETS / 'todoom.py')
                    quoted = filename.replace('\\', '\\\\').replace('"', '\\"').replace('`', '\\`').replace('$', '\\$').replace('%', '%%')
                    self.autostart.write_text('[Desktop Entry]\nType=Application\nName=Todoom\n'
                                              f'Exec=python3 "{quoted}" --background\nIcon={ICON_NAME}\n'
                                              'X-GNOME-Autostart-enabled=true\n')
                else:
                    self.autostart.unlink(missing_ok=True)
            except OSError as exc:
                self.error.set_text('Could not change start at login: ' + str(exc))
                self.error.show()

        def clear_attention(self):
            self.unread = 0
            if self.indicator:
                self.indicator.set_status(AppIndicator.IndicatorStatus.ACTIVE)
                self.indicator.set_label('', '')

        def notify(self, title, body):
            notification = Notify.Notification.new(title, body, str(ASSETS / 'todoom.png'))
            notification.set_app_name('Todoom')
            notification.set_hint('desktop-entry', GLib.Variant('s', APP_ID))
            notification.set_urgency(Notify.Urgency.NORMAL)
            notification.add_action('default', 'Open Todoom', lambda *_: self.do_activate(), None)
            notification.connect('closed', lambda n: self.notifications.remove(n) if n in self.notifications else None)
            try:
                if not notification.show():
                    return False
            except GLib.Error as exc:
                self.tray_status.set_label('Notifications unavailable: ' + exc.message)
                return False
            self.notifications.append(notification)
            return True

        def reminder_message(self, manager, value, reply):
            try:
                message = json.loads(value.to_json(0))
                if not isinstance(message, dict):
                    raise ValueError('Invalid reminder message')
                if message.get('kind') == 'status':
                    self.tray_status.set_label(str(message.get('text', ''))[:160])
                else:
                    tasks = message.get('tasks')
                    if not isinstance(tasks, list) or not 1 <= len(tasks) <= 100:
                        raise ValueError('Invalid task list')
                    now = time.monotonic()
                    self.recent_reminders = {k: t for k, t in self.recent_reminders.items() if now - t < 120}
                    fresh, keys = [], []
                    for task in tasks:
                        if not isinstance(task, dict) or not isinstance(task.get('id'), int) or task['id'] <= 0:
                            raise ValueError('Invalid task')
                        key = json.dumps([task.get(k) for k in ('id', 'type', 'title', 'due', 'list')])
                        if key not in self.recent_reminders and key not in keys:
                            fresh.append(task)
                            keys.append(key)
                    if fresh:
                        title = 'Todoom: ' + (('Task due soon' if fresh[0].get('type') == 'due_soon' else 'Overdue task') if len(fresh) == 1 else f'{len(fresh)} task reminders')
                        lines = [f"{str(t.get('title', 'Task'))[:200]} — {'Due soon' if t.get('type') == 'due_soon' else 'Overdue'} · {str(t.get('list', ''))[:100]} · Due {str(t.get('due', ''))[:60]}" for t in fresh[:4]]
                        if len(fresh) > 4:
                            lines.append(f'+{len(fresh) - 4} more tasks')
                        if not self.notify(title, GLib.markup_escape_text('\n'.join(lines))):
                            raise ValueError('Desktop notification service unavailable')
                        self.recent_reminders.update({k: now for k in keys})
                        self.unread += len(fresh)
                        if self.indicator:
                            self.indicator.set_status(AppIndicator.IndicatorStatus.ATTENTION)
                            self.indicator.set_label(str(self.unread), '99')
                        self.tray_status.set_label(f'{self.unread} task reminders — open Todoom to view')
                reply.return_value(JavaScriptCore.Value.new_boolean(value.get_context(), True))
            except (ValueError, TypeError) as exc:
                reply.return_error_message(str(exc))
            return True

        def check_reminders(self):
            if hasattr(self, 'web') and not self.web.is_loading():
                self.web.evaluate_javascript('window.todoomDesktopPoll?.();', -1, None, None, None, None, None)
            return True

        def open_server(self):
            self.recent_reminders.clear()
            self.clear_attention()
            for child in self.content.get_children():
                child.destroy()
            # Each server has separate persistent storage and login cookies.
            key = hashlib.sha256(self.server.encode()).hexdigest()[:24]
            data = Path(GLib.get_user_data_dir()) / 'todoom' / key
            cache = Path(GLib.get_user_cache_dir()) / 'todoom' / key
            data.mkdir(parents=True, exist_ok=True, mode=0o700)
            cache.mkdir(parents=True, exist_ok=True, mode=0o700)
            manager = WebKit2.WebsiteDataManager(base_data_directory=str(data), base_cache_directory=str(cache))
            context = WebKit2.WebContext.new_with_website_data_manager(manager)
            context.get_cookie_manager().set_persistent_storage(str(data / 'cookies.sqlite'), WebKit2.CookiePersistentStorage.SQLITE)
            self.web = WebKit2.WebView.new_with_context(context)
            self.web.get_settings().set_enable_page_cache(False)
            scripts = self.web.get_user_content_manager()
            scripts.connect('script-message-with-reply-received::todoomDesktop', self.reminder_message)
            scripts.register_script_message_handler_with_reply('todoomDesktop', None)
            bridge = (ASSETS / 'reminders.js').read_text().replace('__TODOOM_SERVER__', json.dumps(self.server))
            scripts.add_script(WebKit2.UserScript.new(bridge, WebKit2.UserContentInjectedFrames.TOP_FRAME,
                                                     WebKit2.UserScriptInjectionTime.START, None, None))
            self.web.connect('load-failed', self.load_failed)
            self.web.connect('load-changed', self.load_changed)
            self.web.connect('decide-policy', self.navigation)
            self.web.connect('permission-request', self.permission)
            context.connect('download-started', self.download)
            self.content.pack_start(self.web, True, True, 0)
            self.web.show()
            self.error.hide()
            self.web.load_uri(self.server)

        def load_failed(self, web, event, uri, error):
            if not error.matches(WebKit2.network_error_quark(), WebKit2.NetworkError.CANCELLED):
                self.error.show()
            return False

        def load_changed(self, web, event):
            if event == WebKit2.LoadEvent.STARTED:
                self.error.hide()
            elif event == WebKit2.LoadEvent.FINISHED:
                GLib.timeout_add_seconds(3, self.check_once)

        def check_once(self):
            self.check_reminders()
            return False

        def navigation(self, web, decision, kind):
            if kind not in (WebKit2.PolicyDecisionType.NAVIGATION_ACTION, WebKit2.PolicyDecisionType.NEW_WINDOW_ACTION):
                return False
            uri = decision.get_navigation_action().get_request().get_uri()
            target, server = urlsplit(uri), urlsplit(self.server)
            same_origin = (target.scheme, target.hostname, target.port) == (server.scheme, server.hostname, server.port)
            if same_origin:
                if kind == WebKit2.PolicyDecisionType.NEW_WINDOW_ACTION:
                    decision.ignore()
                    web.load_uri(uri)
                    return True
                return False
            decision.ignore()
            if target.scheme in ('https', 'http', 'mailto'):
                Gtk.show_uri_on_window(self.window, uri, Gtk.get_current_event_time())
            return True

        def permission(self, web, request):
            if isinstance(request, WebKit2.NotificationPermissionRequest):
                dialog = Gtk.MessageDialog(transient_for=self.window, modal=True, message_type=Gtk.MessageType.QUESTION,
                                           buttons=Gtk.ButtonsType.YES_NO, text='Allow Todoom desktop notifications?')
                response = dialog.run()
                dialog.destroy()
                request.allow() if response == Gtk.ResponseType.YES else request.deny()
            else:
                request.deny()
            return True

        def download(self, context, download):
            def destination(item, name):
                chooser = Gtk.FileChooserDialog(title='Save download', transient_for=self.window, action=Gtk.FileChooserAction.SAVE)
                chooser.add_buttons('Cancel', Gtk.ResponseType.CANCEL, 'Save', Gtk.ResponseType.ACCEPT)
                chooser.set_do_overwrite_confirmation(True)
                chooser.set_current_name(Path(name).name)
                if chooser.run() == Gtk.ResponseType.ACCEPT:
                    item.set_allow_overwrite(True)
                    item.set_destination(Path(chooser.get_filename()).as_uri())
                else:
                    item.cancel()
                chooser.destroy()
                return True
            download.connect('decide-destination', destination)

        def configure(self):
            dialog = Gtk.Dialog(title='Todoom server', transient_for=self.window, modal=True)
            dialog.add_buttons('Cancel', Gtk.ResponseType.CANCEL, 'Connect', Gtk.ResponseType.OK)
            dialog.set_default_response(Gtk.ResponseType.OK)
            box = dialog.get_content_area()
            box.set_spacing(12)
            box.set_border_width(20)
            label = Gtk.Label(label='Connect to your existing Todoom server.\nSign in using your normal Todoom account.')
            box.add(label)
            entry = Gtk.Entry()
            entry.set_width_chars(48)
            entry.set_text(self.server)
            entry.set_activates_default(True)
            box.add(entry)
            hint = Gtk.Label(label='Use HTTPS when possible. HTTP sends data unencrypted.')
            box.add(hint)
            dialog.show_all()
            while dialog.run() == Gtk.ResponseType.OK:
                try:
                    server = normalize_server(entry.get_text())
                    save_server(server)
                except (ValueError, OSError) as exc:
                    hint.set_text(str(exc))
                    continue
                self.server = server
                self.open_server()
                break
            dialog.destroy()

    return Todoom().run([sys.argv[0]])


if __name__ == '__main__':
    sys.exit(main())

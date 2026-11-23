#!/usr/bin/env python3
"""Opt-in desktop integration check: needs a graphical session and session D-Bus.

Uses an isolated profile and a local fixture server; never reads real tasks.
Run: python3 tests/integration.py
"""
import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

temporary = tempfile.TemporaryDirectory(prefix='todoom-integration-')
for name in ('XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'XDG_CACHE_HOME'):
    os.environ[name] = str(Path(temporary.name) / name)
import gi
gi.require_version('Gtk', '3.0')
gi.require_version('WebKit2', '4.1')
from gi.repository import Gio, GLib, Gtk

tasks = [dict(id=1, title='<Due soon & test>', list='Home', due='Today', type='due_soon'),
         dict(id=2, title='Overdue test', list='Work', due='Yesterday', type='overdue')]
state = dict(tasks=tasks, acks=0, logged_in=True, notices=[], fail=False, failed=False)


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        if not state['logged_in']:
            html = '<title>Sign in</title><input name="password">'
        else:
            payload = json.dumps(state['tasks']).replace('<', '\\u003c')
            html = '<title>Fixture</title><script type="application/json" id="todoom-overdue-payload" data-method="notifications" data-csrf="fixture">' + payload + '</script>'
        self.send_response(200)
        self.send_header('Content-Type', 'text/html; charset=utf-8')
        self.end_headers()
        self.wfile.write(html.encode())

    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', 0)))
        assert b'csrf=fixture' in body and self.path == '/?a=overdue-notified'
        state['acks'] += 1
        self.send_response(200)
        self.end_headers()

    def log_message(self, *_):
        pass


server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
threading.Thread(target=server.serve_forever, daemon=True).start()
spec = importlib.util.spec_from_file_location('todoom', Path(__file__).resolve().parents[1] / 'todoom.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
module.APP_ID = 'space.quantumnet.todoom.IntegrationTest'
module.save_server(f'http://127.0.0.1:{server.server_port}/')
sys.argv = [str(Path(module.__file__))]


def step(callback):
    def run():
        try:
            callback(Gio.Application.get_default())
        except Exception:
            import traceback
            traceback.print_exc()
            state['failed'] = True
            Gio.Application.get_default().quit()
        return False
    return run


def setup(app):
    assert app.indicator is not None
    state['real_notify'] = app.notify
    def notify(title, body):
        if state['fail']:
            return False
        state['notices'].append((title, body))
        return True
    app.notify = notify
    app.web.evaluate_javascript('window.Capacitor.Plugins.TodoomNotifications.notifyReminders(' + json.dumps({'tasks': tasks}) + ');', -1, None, None, None, None, None)


def first(app):
    assert len(state['notices']) == 1, state
    assert state['acks'] == 1, state
    assert '&lt;Due soon &amp; test&gt;' in state['notices'][0][1]
    assert 'Overdue' in state['notices'][0][1] and 'Due soon' in state['notices'][0][1]
    assert app.unread == 2
    # Same pending payload during an acknowledgement race must not show twice.
    app.check_reminders()


def failure(app):
    assert len(state['notices']) == 1 and state['acks'] == 2, state
    state['tasks'] = [dict(tasks[0], id=3)]
    state['fail'] = True
    app.check_reminders()


def retry(app):
    assert state['acks'] == 2, 'Failed notification was acknowledged'
    state['fail'] = False
    app.check_reminders()


def logout(app):
    assert len(state['notices']) == 2 and state['acks'] == 3, state
    state['logged_in'] = False
    app.check_reminders()


def finish(app):
    assert 'Sign in' in app.tray_status.get_label()
    assert state['acks'] == 3
    if app.tray_host_available():
        assert app.close_window() is True
        assert not app.window.get_visible()
        app.do_activate()
        assert app.window.get_visible() and app.unread == 0
    startup = Gtk.CheckMenuItem()
    startup.set_active(True)
    app.toggle_autostart(startup)
    assert '--background' in app.autostart.read_text()
    startup.set_active(False)
    app.toggle_autostart(startup)
    assert not app.autostart.exists()
    # Also send one real, clearly labelled notification through the desktop service.
    assert state['real_notify']('Todoom notification test', 'Tray task reminders are working. This is a test.')
    print('PASS: tray, background polling, due-soon/overdue delivery, deduplication, failure retry, acknowledgements, logout, autostart and desktop notification')
    state['finished'] = True
    app.quit()


for seconds, callback in [(1, setup), (6, first), (8, failure), (10, retry), (12, logout), (14, finish)]:
    GLib.timeout_add_seconds(seconds, step(callback))
module.main()
server.shutdown()
sys.exit(0 if state.get('finished') and not state['failed'] else 1)

/* Runs only in the configured server's top-level document. No native file or shell access. */
(() => {
  'use strict';
  const server = new URL(__TODOOM_SERVER__);
  if (location.origin !== server.origin) return;
  const send = (message) => window.webkit.messageHandlers.todoomDesktop.postMessage(message);
  const native = { notifyReminders: ({ tasks }) => send({ tasks }) };
  native.notifyOverdue = native.notifyReminders;
  // Reuse the web app's existing Android notification integration without enabling its mobile UI.
  window.Capacitor = window.Capacitor || {};
  window.Capacitor.Plugins = window.Capacitor.Plugins || {};
  window.Capacitor.Plugins.TodoomNotifications = native;
  let busy = false;
  window.todoomDesktopPoll = async () => {
    if (busy) return;
    busy = true;
    try {
      const response = await fetch(server.href, {
        credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(20000),
      });
      if (!response.ok) throw new Error('Server returned ' + response.status);
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      if (new URL(response.url).searchParams.get('a') === 'login' || page.querySelector('input[name="password"]')) {
        await send({ kind: 'status', text: 'Sign in to receive task reminders' });
        return;
      }
      const payload = page.getElementById('todoom-overdue-payload');
      if (!payload || payload.dataset.method !== 'notifications') {
        await send({ kind: 'status', text: 'Tasks checked · Reminders follow Todoom User Settings' });
        return;
      }
      const tasks = JSON.parse(payload.textContent || '[]');
      if (!Array.isArray(tasks)) throw new Error('Invalid server reminder payload');
      if (tasks.length) {
        // Acknowledge only after the desktop notification service accepts delivery.
        await native.notifyReminders({ tasks });
        const items = tasks.map(t => ({ id: t.id, type: t.type || 'overdue' }));
        const endpoint = new URL(server.href);
        endpoint.search = '?a=overdue-notified';
        const ack = await fetch(endpoint.href, {
          method: 'POST', credentials: 'same-origin', signal: AbortSignal.timeout(20000),
          headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
          body: new URLSearchParams({ csrf: payload.dataset.csrf || '', items: JSON.stringify(items) }),
        });
        if (!ack.ok || new URL(ack.url).searchParams.get('a') === 'login') throw new Error('Could not acknowledge reminders');
      } else {
        await send({ kind: 'status', text: 'Tasks checked at ' + new Date().toLocaleTimeString() });
      }
    } catch (error) {
      await send({ kind: 'status', text: 'Reminder check failed · ' + error.message }).catch(() => {});
    } finally {
      busy = false;
    }
  };
})();

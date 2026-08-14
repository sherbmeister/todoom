(function () {
  const translate = window.todoomTranslate || ((value) => value);
  const biometrics = window.Capacitor?.Plugins?.TodoomBiometrics;
  const appInfo = window.Capacitor?.Plugins?.TodoomAppInfo;
  const isMobileApp = Boolean(biometrics || window.Capacitor?.getPlatform?.() === "android");
  const overduePayload = document.getElementById("todoom-overdue-payload");
  if (isMobileApp) document.body.classList.add("is-mobile-app");

  document.querySelectorAll("[data-filter-drawer]").forEach((drawer) => {
    const hasActiveFilters = drawer.querySelector('input[name="q"]')?.value
      || drawer.querySelector('select[name="priority_order"]')?.value
      || drawer.querySelector('select[name="date_type"]')?.value !== "due"
      || drawer.querySelector('input[name="filter_date"]')?.value;
    if (window.matchMedia("(max-width: 760px)").matches && !hasActiveFilters) {
      drawer.removeAttribute("open");
    }
  });

  document.querySelectorAll("[data-mobile-list-select]").forEach((select) => {
    select.addEventListener("change", () => select.form?.submit());
  });

  const showUpdatePrompt = (installed, available) => {
    const key = `todoomUpdatePrompt:${available.versionCode}`;
    try {
      if (sessionStorage.getItem(key)) return false;
      sessionStorage.setItem(key, "1");
    } catch (error) {
      // If storage is blocked, showing the prompt is still harmless.
    }
    const shell = document.createElement("div");
    shell.className = "app-update-popup";
    shell.innerHTML = `<div class="app-update-card" role="alertdialog" aria-live="polite"><img src="assets/icon.svg" alt=""><p class="alert-kicker">${translate("Update available")}</p><h2>${translate("Todoom update available")}</h2><p class="muted">${translate("Installed")} ${installed.versionName || installed.versionCode}. ${translate("Available")} ${available.versionName}.</p><div class="btn-row"><button type="button" data-update-now>${translate("Open Quantum Appstore")}</button><button class="secondary" type="button" data-update-later>${translate("Later")}</button></div></div>`;
    const close = () => shell.remove();
    shell.querySelector("[data-update-later]")?.addEventListener("click", close);
    shell.querySelector("[data-update-now]")?.addEventListener("click", async () => {
      try {
        if (appInfo?.openAppstore) await appInfo.openAppstore();
        else {
          window.location.href = "intent://appstore.quantumnet.space/#Intent;scheme=https;package=com.myname.mystore;S.browser_fallback_url=https%3A%2F%2Fappstore.quantumnet.space%2F;end";
        }
      } catch (error) {
        window.location.href = "https://appstore.quantumnet.space/";
      }
      close();
    });
    document.body.appendChild(shell);
    window.setTimeout(() => shell.classList.add("show"), 30);
    return true;
  };

  const checkForAppUpdate = async () => {
    if (!isMobileApp) return;
    try {
      const response = await fetch("https://appstore.quantumnet.space/api/apps.json?t=" + Date.now(), { cache: "no-store" });
      if (!response.ok) return;
      const catalogue = await response.json();
      const installed = appInfo?.getInfo
        ? await appInfo.getInfo()
        : { packageName: "com.myname.todoom", versionName: "older app", versionCode: 0 };
      const app = (catalogue.apps || []).find((item) => item.package_name === installed.packageName);
      if (!app || Number(app.version_code) <= Number(installed.versionCode || 0)) return;
      const shown = showUpdatePrompt(installed, { versionName: app.version, versionCode: app.version_code });
      if (shown && appInfo?.notifyUpdateAvailable) {
        try {
          await appInfo.notifyUpdateAvailable({
            id: 99000 + (Number(app.version_code) % 1000),
            title: "Todoom update available",
            body: `Version ${app.version} is ready in Quantum Appstore.`
          });
        } catch (error) {
          // The in-app update prompt remains visible if native notifications are disabled.
        }
      }
    } catch (error) {
      // Update checks should never block the todo list.
    }
  };

  checkForAppUpdate();
  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) checkForAppUpdate();
  });
  window.setInterval(checkForAppUpdate, 6 * 60 * 60 * 1000);

  if (isMobileApp) {
    document.querySelectorAll("[data-mobile-app-field]").forEach((field) => {
      field.value = "1";
    });
    document.querySelectorAll("[data-auth-form]").forEach((form) => {
      form.addEventListener("submit", () => {
        try {
          sessionStorage.setItem("todoomMobileUnlocked", String(Date.now()));
        } catch (error) {
          // Session storage can be unavailable in unusual WebView privacy modes.
        }
      });
    });
  }

  const rememberMobileUnlock = () => {
    try {
      sessionStorage.setItem("todoomMobileUnlocked", String(Date.now()));
    } catch (error) {
      // Non-critical; the server-side trusted token still works.
    }
  };

  const runMobileSessionLock = async () => {
    if (!isMobileApp || !biometrics || !document.body.matches("[data-mobile-session-lock]")) return;
    const unlockedAt = Number(sessionStorage.getItem("todoomMobileUnlocked") || "0");
    if (unlockedAt && Date.now() - unlockedAt < 3 * 60 * 1000) {
      rememberMobileUnlock();
      return;
    }
    const overlay = document.createElement("div");
    overlay.className = "mobile-lock-overlay";
    overlay.innerHTML = `<div class="mobile-lock-card"><img src="assets/icon.svg" alt=""><h2>${translate("Unlock Todoom")}</h2><p class="muted">${translate("Use fingerprint or device unlock to open the app.")}</p><button type="button" class="biometric-button">${translate("Unlock")}</button><a href="?a=logout">${translate("Sign out")}</a><p class="muted field-help" data-lock-status></p></div>`;
    const button = overlay.querySelector("button");
    const status = overlay.querySelector("[data-lock-status]");
    document.body.appendChild(overlay);
    const unlock = async () => {
      button.disabled = true;
      status.textContent = translate("Waiting for fingerprint or device unlock.");
      try {
        const result = await biometrics.authenticate({
          title: "Unlock Todoom",
          subtitle: "Use fingerprint or device unlock to open the app.",
        });
        if (!result?.authenticated) throw new Error("Authentication was not completed.");
        rememberMobileUnlock();
        overlay.remove();
      } catch (error) {
        status.textContent = error.message || translate("Fingerprint unlock failed.");
        button.disabled = false;
      }
    };
    button.addEventListener("click", unlock);
    window.setTimeout(unlock, 250);
  };

  runMobileSessionLock();

  const markOverdueNotified = async (tasks, csrf) => {
    if (!tasks.length || !csrf) return;
    const items = tasks.map((task) => ({ id: task.id, type: task.type || "overdue" })).filter((task) => task.id);
    try {
      await fetch("?a=overdue-notified", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
        body: new URLSearchParams({ csrf, items: JSON.stringify(items) }),
      });
    } catch (error) {
      // The attention panel remains visible if the background acknowledgement fails.
    }
  };

  const reminderLabel = (task) => task.type === "due_soon" ? translate("Due soon") : translate("Overdue");

  const showOverduePopup = (tasks) => {
    if (!tasks.length) return false;
    const overdueCount = tasks.filter((task) => task.type !== "due_soon").length;
    const dueSoonCount = tasks.length - overdueCount;
    const title =
      overdueCount && dueSoonCount
        ? translate("Tasks need attention")
        : overdueCount
          ? translate(tasks.length === 1 ? "Overdue task" : "Overdue tasks")
          : translate(tasks.length === 1 ? "Task due soon" : "Tasks due soon");
    const shell = document.createElement("div");
    shell.className = "overdue-popup";
    shell.dataset.overduePopup = "";
    const list = tasks
      .slice(0, 4)
      .map((task) => `<li><strong></strong><span></span></li>`)
      .join("");
    shell.innerHTML = `<div class="overdue-popup-card" role="alertdialog" aria-live="assertive"><button type="button" class="overdue-popup-close" aria-label="Close overdue reminder">&times;</button><p class="alert-kicker">${translate("Heads-up")}</p><h2>${title}</h2><ul>${list}</ul><a class="btn" href="?status=overdue#tasks">${translate("View tasks")}</a></div>`;
    shell.querySelectorAll("li").forEach((item, index) => {
      const task = tasks[index];
      item.querySelector("strong").textContent = task.title;
      item.querySelector("span").textContent = `${reminderLabel(task)} · ${task.list} · ${translate("Due")} ${task.due}`;
    });
    const close = () => shell.remove();
    shell.addEventListener("click", (event) => {
      if (event.target === shell) close();
    });
    shell.querySelector(".overdue-popup-close")?.addEventListener("click", close);
    document.body.appendChild(shell);
    window.setTimeout(() => shell.classList.add("show"), 30);
    return true;
  };

  const runOverdueNotifications = async () => {
    if (!overduePayload || overduePayload.dataset.method !== "notifications") return;
    let tasks = [];
    try {
      tasks = JSON.parse(overduePayload.textContent || "[]");
    } catch (error) {
      return;
    }
    if (!Array.isArray(tasks) || tasks.length === 0) return;

    const compactReminderView = window.matchMedia("(max-width: 760px)").matches;
    const popupShown = compactReminderView ? false : showOverduePopup(tasks);
    let delivered = false;
    const nativeNotifications = window.Capacitor?.Plugins?.TodoomNotifications;
    if (nativeNotifications?.notifyReminders) {
      try {
        await nativeNotifications.notifyReminders({ tasks });
        delivered = true;
      } catch (error) {
        delivered = false;
      }
    } else if (nativeNotifications?.notifyOverdue) {
      try {
        await nativeNotifications.notifyOverdue({ tasks });
        delivered = true;
      } catch (error) {
        delivered = false;
      }
    } else if ("Notification" in window) {
      try {
        let permission = Notification.permission;
        if (permission === "default") permission = await Notification.requestPermission();
        if (permission === "granted") {
          const first = tasks[0];
          const extra = tasks.length > 1 ? ` +${tasks.length - 1} more` : "";
          new Notification(first.type === "due_soon" ? "Todoom task due soon" : "Todoom overdue task", {
            body: `${first.title}: ${reminderLabel(first)}${extra}.`,
            icon: "assets/icon.svg",
            tag: "todoom-due-reminder",
          });
          delivered = true;
        }
      } catch (error) {
        delivered = false;
      }
    }

    if (popupShown || delivered) {
      await markOverdueNotified(tasks, overduePayload.dataset.csrf || "");
    }
  };

  runOverdueNotifications();

  document.querySelectorAll("[data-confirm]").forEach((el) => {
    el.addEventListener("click", (event) => {
      if (!confirm(el.dataset.confirm)) event.preventDefault();
    });
  });

  const versionDialog = document.querySelector("[data-version-log]");
  if (versionDialog) {
    const closeVersionDialog = () => {
      if (typeof versionDialog.close === "function") versionDialog.close();
      else versionDialog.removeAttribute("open");
    };
    document.querySelectorAll("[data-version-log-open]").forEach((button) => {
      button.addEventListener("click", () => {
        button.closest("[data-user-menu]")?.removeAttribute("open");
        if (typeof versionDialog.showModal === "function") versionDialog.showModal();
        else versionDialog.setAttribute("open", "");
      });
    });
    versionDialog.querySelector("[data-version-log-close]")?.addEventListener("click", closeVersionDialog);
    versionDialog.addEventListener("click", (event) => {
      if (event.target === versionDialog) closeVersionDialog();
    });
  }

  const userMenu = document.querySelector("[data-user-menu]");
  if (userMenu) {
    userMenu.querySelector("summary")?.addEventListener("click", (event) => {
      event.preventDefault();
      userMenu.toggleAttribute("open");
    });
    document.addEventListener("click", (event) => {
      if (!userMenu.contains(event.target)) userMenu.removeAttribute("open");
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") userMenu.removeAttribute("open");
    });
  }

  const themeToggle = document.querySelector("[data-theme-toggle]");
  if (themeToggle) {
    themeToggle.addEventListener("click", (event) => {
      event.preventDefault();
      const next = document.body.dataset.theme === "dark" ? "light" : "dark";
      document.body.dataset.theme = next;
      document.cookie = `theme=${next}; Max-Age=31536000; Path=/; SameSite=Lax`;
      themeToggle.textContent = next === "dark" ? "Light mode" : "Dark mode";
    });
  }

  document.querySelectorAll("[data-mobile-biometric]").forEach(async (panel) => {
    const button = panel.querySelector("[data-biometric-login]");
    const status = panel.querySelector("[data-biometric-status]");
    if (!button || !biometrics) return;
    try {
      const availability = await biometrics.isAvailable();
      if (!availability?.available) return;
      panel.hidden = false;
    } catch (error) {
      return;
    }
    button.addEventListener("click", async () => {
      button.disabled = true;
      if (status) status.textContent = translate("Waiting for fingerprint or device unlock.");
      try {
        const result = await biometrics.authenticate({
          title: "Unlock Todoom",
          subtitle: "Use fingerprint or device unlock instead of your 2FA code.",
        });
        if (!result?.authenticated) throw new Error("Authentication was not completed.");
        const response = await fetch("?a=mobile-biometric-login", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
          body: new URLSearchParams({ csrf: button.dataset.csrf || "" }),
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) throw new Error(payload.message || "Mobile fingerprint login failed.");
        rememberMobileUnlock();
        window.location.href = payload.redirect || "?";
      } catch (error) {
        if (status) status.textContent = error.message || translate("Mobile fingerprint login failed.");
        button.disabled = false;
      }
    });
  });

  document.querySelectorAll("[data-mobile-trusted-unlock]").forEach(async (panel) => {
    const button = panel.querySelector("[data-mobile-unlock]");
    const status = panel.querySelector("[data-mobile-unlock-status]");
    if (!button || !biometrics || !isMobileApp) return;
    try {
      const availability = await biometrics.isAvailable();
      if (!availability?.available) return;
      panel.hidden = false;
    } catch (error) {
      return;
    }
    const unlock = async () => {
      button.disabled = true;
      if (status) status.textContent = translate("Waiting for fingerprint or device unlock.");
      try {
        const result = await biometrics.authenticate({
          title: "Unlock Todoom",
          subtitle: "Use fingerprint or device unlock instead of signing in again.",
        });
        if (!result?.authenticated) throw new Error("Authentication was not completed.");
        const response = await fetch("?a=mobile-unlock", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
          body: new URLSearchParams({ csrf: button.dataset.csrf || "" }),
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) throw new Error(payload.message || "Mobile unlock failed.");
        rememberMobileUnlock();
        window.location.href = payload.redirect || "?";
      } catch (error) {
        if (status) status.textContent = error.message || translate("Mobile unlock failed.");
        button.disabled = false;
      }
    };
    button.addEventListener("click", unlock);
    window.setTimeout(unlock, 350);
  });

  document.querySelectorAll("[data-captcha-refresh]").forEach((button) => {
    button.addEventListener("click", () => {
      const field = button.closest(".captcha-field");
      const image = field?.querySelector("[data-captcha-image]");
      const input = field?.querySelector('input[name="captcha_code"]');
      if (!image) return;
      image.src = `${image.dataset.captchaSource}&refresh=1&r=${Date.now()}`;
      if (input) {
        input.value = "";
        input.focus();
      }
    });
  });

  document.querySelectorAll("[data-task-description]").forEach((details) => {
    const label = details.querySelector("[data-description-label]");
    const sync = () => {
      if (label) label.textContent = translate(details.open ? "Hide description" : "Show description");
    };
    details.addEventListener("toggle", sync);
    sync();
  });

  document.querySelectorAll("[data-password-check]").forEach((input) => {
    const security = input.parentElement.querySelector("[data-password-security]");
    const warning = security?.querySelector("[data-password-warning]");
    if (!security || !warning) return;
    const base = translate("Use at least 12 characters with uppercase, lowercase, a number, and a symbol.");
    const check = () => {
      const value = input.value;
      if (!value) {
        warning.textContent = base;
        warning.classList.remove("weak", "strong");
        security.dataset.score = "0";
        return;
      }
      const missing = [];
      if (value.length < 12) missing.push(translate("12+ characters"));
      if (!/[a-z]/.test(value)) missing.push(translate("lowercase"));
      if (!/[A-Z]/.test(value)) missing.push(translate("uppercase"));
      if (!/\d/.test(value)) missing.push(translate("number"));
      if (!/[^A-Za-z0-9]/.test(value)) missing.push(translate("symbol"));
      const score = 5 - missing.length;
      security.dataset.score = String(score);
      warning.classList.toggle("weak", missing.length > 0);
      warning.classList.toggle("strong", missing.length === 0);
      if (!missing.length) warning.textContent = translate("Strong password.");
      else {
        const level = score <= 1 ? translate("Very weak") : score <= 3 ? translate("Weak") : translate("Almost secure");
        warning.textContent = `${level}. ${translate("Add")} ${missing.join(", ")}.`;
      }
    };
    input.addEventListener("input", check);
    check();
  });

  const sortable = document.querySelector("[data-list-sort]");
  if (sortable) {
    let dragged = null;
    let moved = false;

    const saveOrder = async () => {
      const ids = Array.from(sortable.querySelectorAll("[data-list-id]"), (item) => Number(item.dataset.listId));
      const body = new URLSearchParams({ csrf: sortable.dataset.csrf, ids: JSON.stringify(ids) });
      sortable.classList.add("saving");
      try {
        const response = await fetch(sortable.dataset.reorderUrl, {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
          body,
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error("Unable to save list order");
        sortable.classList.add("saved");
        window.setTimeout(() => sortable.classList.remove("saved"), 700);
      } catch (error) {
        sortable.classList.add("save-error");
        window.setTimeout(() => window.location.reload(), 900);
      } finally {
        sortable.classList.remove("saving");
      }
    };

    sortable.querySelectorAll("[data-list-id]").forEach((item) => {
      item.addEventListener("dragstart", (event) => {
        dragged = item;
        moved = false;
        item.classList.add("dragging");
        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData("text/plain", item.dataset.listId);
      });
      item.addEventListener("dragend", () => {
        item.classList.remove("dragging");
        sortable.querySelectorAll(".drag-target").forEach((target) => target.classList.remove("drag-target"));
        if (moved) saveOrder();
        dragged = null;
        window.setTimeout(() => { moved = false; }, 0);
      });
      item.addEventListener("click", (event) => {
        if (moved) event.preventDefault();
      });
    });

    sortable.addEventListener("dragover", (event) => {
      if (!dragged) return;
      event.preventDefault();
      event.dataTransfer.dropEffect = "move";
      const target = event.target.closest("[data-list-id]");
      if (!target || target === dragged) return;
      sortable.querySelectorAll(".drag-target").forEach((candidate) => candidate.classList.remove("drag-target"));
      target.classList.add("drag-target");
      const rect = target.getBoundingClientRect();
      const after = event.clientY > rect.top + rect.height / 2;
      sortable.insertBefore(dragged, after ? target.nextSibling : target);
      moved = true;
    });

    sortable.addEventListener("drop", (event) => event.preventDefault());
  }

  document.querySelectorAll("[data-bulk-form]").forEach((form) => {
    const boxes = Array.from(form.querySelectorAll('input[name="task_ids[]"]'));
    const selectAll = form.querySelector("[data-select-all]");
    const deleteButton = form.querySelector("[data-bulk-delete]");
    const sync = () => {
      const selected = boxes.filter((box) => box.checked).length;
      if (deleteButton) {
        deleteButton.disabled = selected === 0;
        deleteButton.textContent = selected ? `${translate("Delete selected")} (${selected})` : translate("Delete selected");
      }
      if (selectAll) {
        selectAll.checked = selected > 0 && selected === boxes.length;
        selectAll.indeterminate = selected > 0 && selected < boxes.length;
      }
    };
    boxes.forEach((box) => box.addEventListener("change", sync));
    if (selectAll) {
      selectAll.addEventListener("change", () => {
        boxes.forEach((box) => {
          box.checked = selectAll.checked;
        });
        sync();
      });
    }
    sync();
  });
})();

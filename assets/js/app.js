const onReady = (fn) => {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fn, { once: true });
  } else {
    fn();
  }
};

const roomsCache = new Map();
const toastIds = new Set();

function getCsrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute('content') || '' : '';
}

function getCsrfName() {
  const body = document.body;
  return body ? body.dataset.csrfName || 'csrf_token' : 'csrf_token';
}

function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; i += 1) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}

function sanitizeText(text) {
  return (text ?? '')
    .toString()
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function formatRelativeTime(isoString) {
  if (!isoString) return '';
  const now = new Date();
  const ts = new Date(isoString);
  if (Number.isNaN(ts.getTime())) return '';
  const diff = (ts.getTime() - now.getTime()) / 1000; // seconds
  const abs = Math.abs(diff);
  const units = [
    { step: 60, name: 'second' },
    { step: 60, name: 'minute' },
    { step: 24, name: 'hour' },
    { step: 7, name: 'day' },
    { step: 4.34524, name: 'week' },
    { step: 12, name: 'month' },
    { step: Infinity, name: 'year' },
  ];
  let delta = abs;
  let unit = 'second';
  for (const u of units) {
    if (delta < u.step) {
      unit = u.name;
      break;
    }
    delta /= u.step;
  }
  const value = Math.max(1, Math.round(delta));
  const label = value === 1 ? unit : `${unit}s`;
  return diff < 0 ? `${value} ${label} ago` : `in ${value} ${label}`;
}

function summarizeUserAgent(agent = '') {
  const raw = (agent || '').toString();
  const lower = raw.toLowerCase();
  if (!raw) return 'Unknown device';
  if (lower.includes('iphone') || lower.includes('ipad') || lower.includes('ios')) {
    if (lower.includes('crios')) return 'Chrome on iOS';
    if (lower.includes('fxios')) return 'Firefox on iOS';
    return 'Safari on iOS';
  }
  if (lower.includes('android')) {
    if (lower.includes('firefox')) return 'Firefox on Android';
    if (lower.includes('edg')) return 'Edge on Android';
    if (lower.includes('chrome')) return 'Chrome on Android';
    return 'Android browser';
  }
  if (lower.includes('mac os') || lower.includes('macintosh')) {
    if (lower.includes('safari') && !lower.includes('chrome')) return 'Safari on macOS';
    if (lower.includes('firefox')) return 'Firefox on macOS';
    if (lower.includes('chrome')) return 'Chrome on macOS';
  }
  if (lower.includes('windows')) {
    if (lower.includes('edg')) return 'Microsoft Edge';
    if (lower.includes('firefox')) return 'Firefox on Windows';
    if (lower.includes('chrome')) return 'Chrome on Windows';
  }
  if (lower.includes('linux')) {
    if (lower.includes('firefox')) return 'Firefox on Linux';
    if (lower.includes('chrome')) return 'Chrome on Linux';
  }
  const firstParen = raw.split('(')[0].trim();
  if (firstParen) return firstParen.slice(0, 64);
  return raw.slice(0, 64);
}

function initNav() {
  const toggle = document.getElementById('navToggle');
  const panel = document.getElementById('navPanel');
  const body = document.body;
  if (!toggle || !panel) return;

  const mq = window.matchMedia('(min-width: 980px)');
  const isDesktop = () => mq.matches;

  const syncAria = () => {
    if (isDesktop()) {
      panel.classList.remove('open');
      panel.removeAttribute('aria-hidden');
      toggle.classList.remove('is-active');
      toggle.setAttribute('aria-expanded', 'false');
      body.classList.remove('nav-open');
    } else {
      panel.setAttribute('aria-hidden', panel.classList.contains('open') ? 'false' : 'true');
    }
  };

  const open = () => {
    panel.classList.add('open');
    toggle.classList.add('is-active');
    toggle.setAttribute('aria-expanded', 'true');
    if (!isDesktop()) {
      panel.setAttribute('aria-hidden', 'false');
      body.classList.add('nav-open');
    }
  };

  const close = () => {
    panel.classList.remove('open');
    toggle.classList.remove('is-active');
    toggle.setAttribute('aria-expanded', 'false');
    if (!isDesktop()) {
      panel.setAttribute('aria-hidden', 'true');
    }
    body.classList.remove('nav-open');
  };

  toggle.addEventListener('click', (event) => {
    event.stopPropagation();
    if (panel.classList.contains('open')) {
      close();
    } else {
      open();
    }
  });

  document.addEventListener('click', (event) => {
    if (!panel.classList.contains('open')) return;
    if (panel.contains(event.target) || toggle.contains(event.target)) return;
    close();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && panel.classList.contains('open')) {
      close();
    }
  });

  panel.addEventListener('click', (event) => {
    if (isDesktop()) return;
    const link = event.target.closest('a');
    if (link) close();
  });

  const handleMatchChange = (event) => {
    if (event.matches) close();
    syncAria();
  };

  if (mq.addEventListener) {
    mq.addEventListener('change', handleMatchChange);
  } else if (mq.addListener) {
    mq.addListener(handleMatchChange);
  }

  syncAria();
}

async function fetchRoomsForBuilding(buildingId) {
  const key = String(buildingId || '');
  if (!key || key === '0') return [];
  if (roomsCache.has(key)) {
    return roomsCache.get(key);
  }
  try {
    const resp = await fetch(`/rooms.php?action=by_building&id=${encodeURIComponent(key)}`, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
    });
    if (!resp.ok) throw new Error('Failed to fetch rooms');
    const data = await resp.json();
    if (Array.isArray(data)) {
      roomsCache.set(key, data);
      return data;
    }
  } catch (err) {
    console.warn('Room lookup failed', err);
  }
  roomsCache.set(key, []);
  return [];
}

function initRooms() {
  const sources = document.querySelectorAll('[data-room-source]');
  if (!sources.length) return;

  const ensurePlaceholder = (target) => {
    if (!target || target.dataset.roomPlaceholder) return;
    const first = target.querySelector('option[value=""]');
    if (first) target.dataset.roomPlaceholder = first.textContent.trim();
  };

  const populateSelect = (target, rooms) => {
    if (!target) return;
    ensurePlaceholder(target);
    const placeholder = target.dataset.roomPlaceholder || 'Select room';
    const current = target.value;
    target.innerHTML = '';
    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = placeholder;
    target.appendChild(defaultOption);
    let found = false;
    rooms.forEach((room) => {
      const option = document.createElement('option');
      option.value = String(room.id);
      option.textContent = room.label || room.room_number || `Room ${room.id}`;
      if (String(room.id) === current) {
        option.selected = true;
        found = true;
      }
      target.appendChild(option);
    });
    if (!found) {
      target.value = '';
    }
  };

  const populateDatalist = (datalist, rooms) => {
    if (!datalist) return;
    datalist.innerHTML = '';
    rooms.forEach((room) => {
      const opt = document.createElement('option');
      opt.value = room.room_number || room.label || '';
      opt.label = room.label || opt.value;
      datalist.appendChild(opt);
    });
  };

  const validateInput = (input, rooms, buildingId) => {
    if (!input) return;
    const trimmed = input.value.trim();
    if (!buildingId) {
      input.setCustomValidity(trimmed ? 'Choose a building first.' : '');
      return;
    }
    if (!trimmed) {
      input.setCustomValidity('');
      return;
    }
    const exists = rooms.some((room) => {
      const number = (room.room_number || '').toLowerCase();
      return number && number === trimmed.toLowerCase();
    });
    input.setCustomValidity(exists ? '' : 'Room not found for this building.');
  };

  sources.forEach((select) => {
    const targetAttr = select.dataset.roomTarget || '';
    const targetIds = targetAttr.split(/\s+/).filter(Boolean);
    const inputId = select.dataset.roomInput;
    const datalistId = select.dataset.roomDatalist;
    const targets = targetIds.length
      ? targetIds.map((id) => document.getElementById(id)).filter(Boolean)
      : [];
    const target = targets[0] || null;
    const input = inputId ? document.getElementById(inputId) : null;
    const datalist = datalistId ? document.getElementById(datalistId) : null;

    targets.forEach((t) => ensurePlaceholder(t));
    if (!targets.length && target) ensurePlaceholder(target);

    const refresh = async () => {
      const buildingId = select.value;
      if (!buildingId) {
        targets.forEach((t) => {
          populateSelect(t, []);
          t.disabled = true;
        });
        if (!targets.length && target) {
          populateSelect(target, []);
          target.disabled = true;
        }
        if (datalist) datalist.innerHTML = '';
        if (input) input.setCustomValidity('');
        return;
      }
      const rooms = await fetchRoomsForBuilding(buildingId);
      targets.forEach((t) => {
        populateSelect(t, rooms);
        t.disabled = false;
      });
      if (!targets.length && target) {
        populateSelect(target, rooms);
        target.disabled = false;
      }
      populateDatalist(datalist, rooms);
      validateInput(input, rooms, buildingId);
    };

    select.addEventListener('change', refresh);

    if (input) {
      input.addEventListener('blur', async () => {
        const buildingId = select.value;
        if (!buildingId) {
          validateInput(input, [], '');
          return;
        }
        const rooms = await fetchRoomsForBuilding(buildingId);
        validateInput(input, rooms, buildingId);
      });
      input.addEventListener('input', () => {
        input.setCustomValidity('');
      });
    }

    if (select.value) {
      refresh();
    } else {
      targets.forEach((t) => {
        if (t) t.disabled = true;
      });
      if (!targets.length && target) target.disabled = true;
    }
  });
}

function createToast(item) {
  const stack = document.getElementById('toastStack');
  if (!stack) return null;
  const id = `toast-${item.id || `${Date.now()}-${Math.random().toString(16).slice(2)}`}`;
  if (toastIds.has(id)) return null;
  toastIds.add(id);

  const toast = document.createElement('article');
  toast.className = 'toast';
  toast.dataset.variant = item.variant || 'info';
  toast.dataset.toastId = id;

  const title = sanitizeText(item.title) || 'Notification';
  const bodyHtml = item.body ? sanitizeText(item.body).replace(/\n/g, '<br>') : '';
  const url = item.url ? sanitizeText(item.url) : '';
  const time = sanitizeText(item.created_at);
  const parsedDate = time ? new Date(time) : null;
  const hasValidDate = parsedDate && !Number.isNaN(parsedDate.getTime());
  const relative = hasValidDate ? formatRelativeTime(time) : '';
  const absolute = hasValidDate ? parsedDate.toLocaleString() : '';

  toast.innerHTML = `
    <button class="toast__close" type="button" aria-label="Dismiss notification">&times;</button>
    <div class="toast__title">${title}</div>
    ${bodyHtml ? `<div class="toast__body">${bodyHtml}</div>` : ''}
    ${(relative || absolute) ? `<div class="toast__meta">${relative ? `<span>${relative}</span>` : ''}${absolute ? `<time datetime="${time}">${absolute}</time>` : ''}</div>` : ''}
    ${url ? `<div class="toast__actions"><a href="${url}">Open</a></div>` : ''}
  `;

  const closeBtn = toast.querySelector('.toast__close');
  if (closeBtn) {
    closeBtn.addEventListener('click', () => dismissToast(toast));
  }

  stack.appendChild(toast);
  setTimeout(() => dismissToast(toast), 8000);
  return toast;
}

function dismissToast(toast) {
  if (!toast) return;
  const id = toast.dataset.toastId;
  toast.classList.add('is-leaving');
  setTimeout(() => {
    toast.remove();
    if (id) toastIds.delete(id);
  }, 180);
}

function initNotifications() {
  const dot = document.getElementById('notifDot');
  const body = document.body;
  if (!body || !dot) return;

  const streamUrl = body.dataset.notifStream;
  const pollUrl = body.dataset.notifPoll;
  const csrfName = getCsrfName();
  const csrfToken = getCsrfToken();
  const bellWrapper = document.querySelector('[data-notif-bell]');
  const popover = bellWrapper ? bellWrapper.querySelector('[data-notif-popover]') : null;
  const popoverList = popover ? popover.querySelector('[data-notif-popover-list]') : null;
  const popoverEmpty = popover ? popover.querySelector('[data-notif-popover-empty]') : null;
  const bellTrigger = bellWrapper ? bellWrapper.querySelector('[data-notif-bell-trigger]') : null;
  const peekUrl = '/notifications/api.php?action=peek&limit=3';
  const defaultEmptyText = popoverEmpty ? popoverEmpty.textContent : "You're all caught up.";
  let hidePopoverTimer = null;
  let lastPeekAt = 0;

  const markRead = (notificationId) => {
    if (!notificationId) return;
    const formData = new FormData();
    formData.append('action', 'mark_read');
    formData.append('id', String(notificationId));
    formData.append(csrfName, csrfToken);
    fetch('/notifications/api.php?action=mark_read', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: formData,
      keepalive: true,
    }).catch(() => {});
  };

  const renderCount = (count) => {
    const num = Number(count) || 0;
    if (num > 0) {
      dot.textContent = num > 99 ? '99+' : String(num);
      dot.classList.add('is-visible');
    } else {
      dot.textContent = '';
      dot.classList.remove('is-visible');
    }
    window.dispatchEvent(new CustomEvent('notifications:count', { detail: { count: num } }));
  };

  const showToasts = (items = []) => {
    items.forEach((item) => createToast(item));
  };

  const renderPeek = (items = []) => {
    if (!popoverList || !popoverEmpty) return;
    popoverList.innerHTML = '';
    if (!items.length) {
      popoverList.hidden = true;
      popoverEmpty.textContent = defaultEmptyText;
      popoverEmpty.hidden = false;
      return;
    }

    const fragment = document.createDocumentFragment();
    items.forEach((item) => {
      const li = document.createElement('li');
      li.className = 'nav__bell-item';
       li.dataset.notificationId = String(item.id || '');
      if (!item.is_read) {
        li.classList.add('is-unread');
      }

      const link = document.createElement('a');
      const href = typeof item.url === 'string' && item.url.trim() ? item.url : '/notifications/index.php';
      link.className = 'nav__bell-item-link';
      link.href = href;

      const title = document.createElement('div');
      title.className = 'nav__bell-item-title';
      title.innerHTML = sanitizeText(item.title || 'Notification');
      link.appendChild(title);

      if (item.body) {
        const rawBody = String(item.body || '').trim();
        if (rawBody !== '') {
          let snippet = rawBody;
          if (snippet.length > 160) {
            snippet = `${snippet.slice(0, 157)}…`;
          }
          const body = document.createElement('div');
          body.className = 'nav__bell-item-body';
          body.innerHTML = sanitizeText(snippet);
          link.appendChild(body);
        }
      }

      const rawTime = item.created_at || '';
      const rel = formatRelativeTime(rawTime);
      const parsed = rawTime ? new Date(rawTime) : null;
      const hasDate = parsed && !Number.isNaN(parsed.getTime());
      if (rel || hasDate) {
        const meta = document.createElement('div');
        meta.className = 'nav__bell-item-meta';
        if (rel) {
          const relNode = document.createElement('span');
          relNode.textContent = rel;
          meta.appendChild(relNode);
        }
        if (hasDate) {
          const time = document.createElement('time');
          time.dateTime = rawTime;
          time.textContent = parsed.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
          meta.appendChild(time);
        }
        link.appendChild(meta);
      }

      li.appendChild(link);
      fragment.appendChild(li);
    });

    popoverList.appendChild(fragment);
    popoverList.hidden = false;
    popoverEmpty.textContent = defaultEmptyText;
    popoverEmpty.hidden = true;
  };

  if (popoverList) {
    popoverList.addEventListener('click', (event) => {
      const link = event.target.closest('a.nav__bell-item-link');
      if (!link) return;
      const itemEl = event.target.closest('li.nav__bell-item');
      if (!itemEl) return;
      const notifId = itemEl.dataset.notificationId;
      if (notifId) {
        markRead(notifId);
      }
    });
  }

  const fetchPeek = async (force = false) => {
    if (!popover || !popoverList) return;
    const now = Date.now();
    if (!force && now - lastPeekAt < 15000) return;
    lastPeekAt = now;
    try {
      const resp = await fetch(peekUrl, {
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      if (!resp.ok) {
        throw new Error('bad_status');
      }
      const json = await resp.json();
      if (!json || json.ok !== true) {
        throw new Error('bad_payload');
      }
      renderPeek(Array.isArray(json.items) ? json.items : []);
      if (typeof json.count !== 'undefined') {
        renderCount(json.count);
      }
    } catch (err) {
      if (popoverEmpty) {
        popoverEmpty.textContent = 'Unable to load previews.';
        popoverEmpty.hidden = false;
      }
      if (popoverList) {
        popoverList.hidden = true;
        popoverList.innerHTML = '';
      }
    }
  };

  const openPopover = () => {
    if (!popover) return;
    if (hidePopoverTimer) {
      clearTimeout(hidePopoverTimer);
      hidePopoverTimer = null;
    }
    if (popover.hidden) {
      popover.hidden = false;
      requestAnimationFrame(() => {
        popover.classList.add('is-open');
      });
    } else {
      popover.classList.add('is-open');
    }
    fetchPeek();
  };

  const closePopover = () => {
    if (!popover) return;
    if (hidePopoverTimer) {
      clearTimeout(hidePopoverTimer);
    }
    hidePopoverTimer = setTimeout(() => {
      popover.classList.remove('is-open');
      popover.hidden = true;
    }, 120);
  };

  if (bellWrapper && popover) {
    bellWrapper.addEventListener('mouseenter', openPopover);
    bellWrapper.addEventListener('mouseleave', closePopover);
    popover.addEventListener('mouseenter', () => {
      if (hidePopoverTimer) {
        clearTimeout(hidePopoverTimer);
        hidePopoverTimer = null;
      }
    });
    popover.addEventListener('mouseleave', closePopover);
    popover.addEventListener('focusin', () => {
      if (hidePopoverTimer) {
        clearTimeout(hidePopoverTimer);
        hidePopoverTimer = null;
      }
    });
    popover.addEventListener('focusout', (event) => {
      if (!popover.contains(event.relatedTarget)) {
        closePopover();
      }
    });
    if (bellTrigger) {
      bellTrigger.addEventListener('focus', openPopover);
      bellTrigger.addEventListener('blur', closePopover);
      bellTrigger.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          closePopover();
        }
      });
    }
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !popover.hidden) {
        closePopover();
      }
    });
  }

  if (streamUrl && 'EventSource' in window) {
    let es;
    const connect = () => {
      es = new EventSource(streamUrl);
      es.addEventListener('count', (event) => {
        try {
          const data = JSON.parse(event.data || '{}');
          renderCount(data.count);
        } catch (err) {
          console.warn('Failed to parse count event', err);
        }
      });
      es.addEventListener('notifications', (event) => {
        try {
          const data = JSON.parse(event.data || '{}');
          if (Array.isArray(data.items)) {
            showToasts(data.items);
          }
        } catch (err) {
          console.warn('Failed to parse notifications event', err);
        }
      });
      es.onerror = () => {
        es.close();
        setTimeout(connect, 5000);
      };
    };
    connect();
    return;
  }

  // Fallback polling for older browsers
  if (pollUrl) {
    const poll = async () => {
      try {
        const resp = await fetch(pollUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        if (resp.ok) {
          const json = await resp.json();
          if (json && typeof json.count !== 'undefined') {
            renderCount(json.count);
          }
        }
      } catch (err) {
        console.warn('Notification poll failed', err);
      } finally {
        setTimeout(poll, 30000);
      }
    };
    poll();
  }
}

let pushAutoPromptAttempted = false;

function pushHasPromptedBefore() {
  try {
    return sessionStorage.getItem('pushAutoPrompted') === '1';
  } catch (err) {
    return false;
  }
}

function pushRememberPrompted() {
  try {
    sessionStorage.setItem('pushAutoPrompted', '1');
  } catch (err) {
    // ignore storage errors
  }
}

function emitPushStatus(detail) {
  document.dispatchEvent(new CustomEvent('push:status', { detail }));
}

function autoPromptPush(detail) {
  if (!detail || !detail.supported) return;
  if (typeof Notification === 'undefined') return;
  if (pushAutoPromptAttempted) return;

  if (Notification.permission === 'denied') {
    pushAutoPromptAttempted = true;
    pushRememberPrompted();
    return;
  }

  if (Notification.permission === 'default' && pushHasPromptedBefore()) {
    pushAutoPromptAttempted = true;
    return;
  }

  pushAutoPromptAttempted = true;

  const finalize = () => {
    if (typeof Notification === 'undefined') return;
    if (Notification.permission !== 'default') {
      pushRememberPrompted();
    } else {
      pushAutoPromptAttempted = false;
    }
  };

  if (typeof detail.ensureSubscription === 'function') {
    detail.ensureSubscription({ force: false })
      .then(() => {
        finalize();
        if (typeof detail.fetchStatus === 'function') {
          detail.fetchStatus().then((status) => {
            emitPushStatus(status);
          }).catch(() => {});
        }
      })
      .catch((err) => {
        finalize();
        if (err && err.message === 'permission_denied') {
          emitPushStatus({ ok: false, error: 'permission_denied' });
        }
      });
  } else {
    finalize();
  }
}

document.addEventListener('push:ready', (event) => autoPromptPush(event.detail));
if (typeof window !== 'undefined' && window.__pushController) {
  autoPromptPush(window.__pushController);
}

function initPush() {
  const body = document.body;
  if (!body) return;

  const swPath = body.dataset.serviceWorker || '';
  if ('serviceWorker' in navigator && swPath) {
    navigator.serviceWorker.register(swPath).catch((err) => {
      console.warn('Service worker registration failed', err);
    });
  }

  const announce = (detail) => {
    window.__pushController = detail;
    setTimeout(() => {
      document.dispatchEvent(new CustomEvent('push:ready', { detail }));
    }, 0);
  };

  if (body.dataset.auth !== '1') {
    announce({ supported: false, reason: 'unauthenticated' });
    return;
  }

  const subscribeEndpoint = body.dataset.pushSubscribe || '';
  let vapidKey = body.dataset.pushPublicKey || '';
  if (!vapidKey) {
    const vapidMeta = document.querySelector('meta[name="vapid-public-key"]');
    if (vapidMeta) {
      vapidKey = vapidMeta.getAttribute('content') || '';
    }
  }

  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
    announce({ supported: false, reason: 'unsupported' });
    return;
  }

  if (subscribeEndpoint === '') {
    announce({ supported: false, reason: 'missing-endpoint' });
    return;
  }

  if (vapidKey === '') {
    announce({ supported: false, reason: 'missing-key' });
    return;
  }

  const csrfName = getCsrfName();
  const csrfToken = getCsrfToken();

  const fetchStatus = async () => {
    const resp = await fetch(subscribeEndpoint, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!resp.ok) throw new Error('status_failed');
    return resp.json();
  };

  const sendIntent = async (intent, payload = {}) => {
    const bodyPayload = { intent, [csrfName]: csrfToken, ...payload };
    const resp = await fetch(subscribeEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(bodyPayload),
    });
    if (!resp.ok) throw new Error('request_failed');
    return resp.json();
  };

  const ensureSubscription = async ({ force = false } = {}) => {
    if (Notification.permission === 'denied') {
      throw new Error('permission_denied');
    }
    if (Notification.permission === 'default') {
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') {
        throw new Error('permission_denied');
      }
    }

    const registration = await navigator.serviceWorker.ready;
    let subscription = await registration.pushManager.getSubscription();
    if (subscription && !force) {
      const payload = subscription && typeof subscription.toJSON === 'function'
        ? subscription.toJSON()
        : JSON.parse(JSON.stringify(subscription));
      await sendIntent('subscribe', { subscription: payload });
      return { ok: true, subscription };
    }
    if (subscription) {
      try { await subscription.unsubscribe(); } catch (err) { /* ignore */ }
    }

    const convertedKey = urlBase64ToUint8Array(vapidKey);
    subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: convertedKey,
    });

    const payload = subscription && typeof subscription.toJSON === 'function'
      ? subscription.toJSON()
      : JSON.parse(JSON.stringify(subscription));
    await sendIntent('subscribe', { subscription: payload });
    return { ok: true, subscription };
  };

  const unsubscribe = async ({ disableAll = false } = {}) => {
    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.getSubscription();
    let endpoint = '';
    if (subscription) {
      endpoint = subscription.endpoint || '';
      try { await subscription.unsubscribe(); } catch (err) { /* ignore */ }
    }
    const intent = disableAll ? 'disable' : 'unsubscribe';
    const payload = endpoint ? { endpoint } : {};
    const response = await sendIntent(intent, payload);
    return response;
  };

  announce({
    supported: true,
    ensureSubscription,
    unsubscribe,
    fetchStatus,
    permission: () => Notification.permission,
  });
}

function initPushControls() {
  const statusEl = document.querySelector('[data-push-status]');
  const statusText = document.querySelector('[data-push-status-text]');
  const buttons = document.querySelectorAll('[data-push-action]');
  const devicesRegion = document.querySelector('[data-push-devices-region]');
  const devicesList = devicesRegion ? devicesRegion.querySelector('[data-push-device-list]') : null;
  const emptyState = devicesRegion ? devicesRegion.querySelector('[data-push-empty]') : null;
  if (!statusEl && !buttons.length) {
    return;
  }

  const updateStatus = (text) => {
    if (statusText) {
      statusText.textContent = text;
    }
  };

  const disableButtons = () => {
    buttons.forEach((btn) => { btn.disabled = true; });
  };

  const enableButtons = () => {
    buttons.forEach((btn) => { btn.disabled = false; });
  };

  const renderDevices = (devices) => {
    if (!devicesList || !emptyState) {
      return;
    }
    const entries = Array.isArray(devices) ? devices : [];
    if (!entries.length) {
      devicesList.innerHTML = '';
      devicesList.hidden = true;
      emptyState.hidden = false;
      return;
    }

    const csrfName = sanitizeText(getCsrfName());
    const csrfToken = sanitizeText(getCsrfToken());

    const items = entries.map((device) => {
      const id = Number(device.id) || 0;
      const kindRaw = (device.kind || '').toString();
      let kindLabel = 'Web push';
      if (kindRaw === 'fcm') kindLabel = 'Android push';
      else if (kindRaw === 'apns') kindLabel = 'iOS push';
      const uaSummary = sanitizeText(summarizeUserAgent(device.user_agent || ''));
      const lastUsedRaw = device.last_used_at || device.created_at || '';
      let metaHtml = '';
      if (lastUsedRaw) {
        const iso = lastUsedRaw.replace(' ', 'T');
        const relative = formatRelativeTime(iso);
        let metaText = `Last used ${lastUsedRaw}`;
        if (relative) {
          metaText = `Last used ${relative} · ${lastUsedRaw}`;
        }
        metaHtml = `<div class="device-row__meta">${sanitizeText(metaText)}</div>`;
      }

      return `
        <li class="device-row" data-device-id="${id}">
          <div class="device-row__main">
            <span class="device-row__kind">${sanitizeText(kindLabel)}</span>
            <div class="device-row__text">
              <div class="device-row__label">${uaSummary}</div>
              ${metaHtml}
            </div>
          </div>
          <form method="post" class="device-row__actions">
            <input type="hidden" name="action" value="revoke_device">
            <input type="hidden" name="device_id" value="${id}">
            <input type="hidden" name="${csrfName}" value="${csrfToken}">
            <button class="btn secondary small" type="submit">Disconnect</button>
          </form>
        </li>
      `;
    }).join('');

    devicesList.innerHTML = items;
    devicesList.hidden = false;
    emptyState.hidden = true;
  };

  let controller = { supported: false };

  const applyStatus = (result) => {
    if (!result) {
      updateStatus('Unable to load status');
      renderDevices([]);
      disableButtons();
      return;
    }
    if (result.error === 'permission_denied') {
      updateStatus('Permission denied');
      renderDevices([]);
      disableButtons();
      return;
    }
    if (result.ok === false) {
      updateStatus('Unable to load status');
      const devices = Array.isArray(result.devices) ? result.devices : [];
      renderDevices(devices);
      disableButtons();
      return;
    }

    const devices = Array.isArray(result.devices) ? result.devices : [];
    renderDevices(devices);

    if (result.vapid_ready === false) {
      updateStatus('Push not configured');
      disableButtons();
      return;
    }

    if (!controller || !controller.supported) {
      disableButtons();
    } else if (buttons.length) {
      enableButtons();
    }

    if (result.allow_push) {
      const count = devices.length;
      if (count > 0) {
        updateStatus(`Enabled on ${count} device${count === 1 ? '' : 's'}`);
      } else {
        updateStatus('Enabled');
      }
    } else {
      if (typeof Notification !== 'undefined' && Notification.permission === 'denied') {
        updateStatus('Permission denied');
        disableButtons();
      } else if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
        updateStatus('Awaiting permission');
      } else {
        updateStatus('Disabled');
      }
    }
  };

  const handleStatusBroadcast = (event) => {
    if (!event || !event.detail) {
      return;
    }
    applyStatus(event.detail);
  };

  const handleReady = async (event) => {
    controller = event.detail || { supported: false };
    if (!controller.supported) {
      const reason = controller.reason || '';
      if (reason === 'missing-key') {
        updateStatus('Push not configured');
      } else if (reason === 'unauthenticated') {
        updateStatus('Sign in to enable push');
      } else if (reason === 'missing-endpoint') {
        updateStatus('Push endpoint unavailable');
      } else {
        updateStatus('Push not supported');
      }
      disableButtons();
      renderDevices([]);
      return;
    }

    if (controller.permission && controller.permission() === 'denied') {
      updateStatus('Permission denied');
      disableButtons();
      renderDevices([]);
      return;
    }

    enableButtons();
    try {
      const result = await controller.fetchStatus();
      applyStatus(result);
      emitPushStatus(result);
    } catch (err) {
      console.warn('Push status fetch failed', err);
      updateStatus('Unable to load status');
      renderDevices([]);
    }
  };

  document.addEventListener('push:status', handleStatusBroadcast);
  document.addEventListener('push:ready', handleReady);
  if (window.__pushController) {
    handleReady({ detail: window.__pushController });
  }

  buttons.forEach((button) => {
    button.addEventListener('click', async () => {
      if (!controller || !controller.supported) {
        return;
      }
      const action = button.dataset.pushAction;
      button.disabled = true;
      try {
        if (action === 'enable') {
          await controller.ensureSubscription({ force: true });
          const result = await controller.fetchStatus();
          applyStatus(result);
          emitPushStatus(result);
        } else if (action === 'disable') {
          const result = await controller.unsubscribe({ disableAll: true });
          applyStatus(result);
          emitPushStatus(result);
        }
      } catch (err) {
        updateStatus('Push failed');
        console.warn('Push control failed', err);
      } finally {
        button.disabled = false;
      }
    });
  });

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', async (event) => {
      if (!controller || !controller.supported) {
        return;
      }
      if (event.data && event.data.type === 'pushsubscriptionchange') {
        try {
          const result = await controller.fetchStatus();
          applyStatus(result);
          emitPushStatus(result);
        } catch (err) {
          updateStatus('Unable to refresh status');
        }
      }
    });
  }
}

function initCommandPalette() {
  const palette = document.getElementById('commandPalette');
  const input = document.getElementById('commandPaletteInput');
  const results = document.getElementById('commandPaletteResults');
  if (!palette || !input || !results) return;

  const openButtons = document.querySelectorAll('[data-command-open]');
  const body = document.body;
  let visibleCommands = [];
  let activeIndex = 0;

  const baseCommands = (() => {
    const commands = [];
    const navLinks = document.querySelectorAll('.nav a.nav__link');
    navLinks.forEach((link) => {
      const label = link.textContent.trim();
      if (!label) return;
      commands.push({
        label,
        url: link.getAttribute('href'),
        description: 'Navigate',
        group: 'Navigation',
      });
    });

    commands.push(
      { label: 'Create Task', url: '/task_new.php', description: 'Draft a new task', group: 'Actions', shortcut: 'N' },
      { label: 'View Tasks', url: '/tasks.php', description: 'Open task list', group: 'Actions' },
      { label: 'Rooms Directory', url: '/rooms.php', description: 'Manage rooms and buildings', group: 'Data' },
      { label: 'Inventory', url: '/inventory.php', description: 'Open inventory overview', group: 'Data' },
      { label: 'Profile & Devices', url: '/account/profile.php', description: 'Manage account and notifications', group: 'Account' },
      { label: 'Notification Inbox', url: '/notifications/index.php', description: 'Review all alerts', group: 'Account' },
    );

    return commands;
  })();

  const closePalette = () => {
    if (palette.hasAttribute('hidden')) return;
    palette.dataset.state = 'closed';
    palette.setAttribute('hidden', '');
    body.classList.remove('command-open');
    results.innerHTML = '';
    input.value = '';
    visibleCommands = [];
    activeIndex = 0;
  };

  const openPalette = () => {
    if (!palette.hasAttribute('hidden')) return;
    palette.removeAttribute('hidden');
    palette.dataset.state = 'open';
    body.classList.add('command-open');
    input.value = '';
    filterCommands('');
    setTimeout(() => input.focus(), 0);
  };

  const activateItem = (index) => {
    const items = results.querySelectorAll('.command-palette__item');
    items.forEach((item) => item.setAttribute('aria-selected', 'false'));
    if (!items.length) return;
    const clamped = Math.max(0, Math.min(index, items.length - 1));
    const current = items[clamped];
    if (current) {
      current.setAttribute('aria-selected', 'true');
      current.scrollIntoView({ block: 'nearest' });
      activeIndex = clamped;
    }
  };

  const executeCommand = (command) => {
    if (!command) return;
    closePalette();
    if (command.action === 'open-task' && command.url) {
      window.location.href = command.url;
      return;
    }
    if (command.url) {
      window.location.href = command.url;
    }
    if (typeof command.handler === 'function') {
      command.handler();
    }
  };

  const renderCommands = (commands) => {
    visibleCommands = commands;
    results.innerHTML = '';
    if (!commands.length) {
      const empty = document.createElement('li');
      empty.className = 'command-palette__item';
      empty.setAttribute('role', 'option');
      empty.setAttribute('aria-disabled', 'true');
      empty.textContent = 'No matches found. Try broader terms or #ID.';
      results.appendChild(empty);
      activeIndex = 0;
      return;
    }

    let currentGroup = null;
    commands.forEach((cmd, idx) => {
      if (cmd.group && cmd.group !== currentGroup) {
        currentGroup = cmd.group;
        const groupLi = document.createElement('li');
        groupLi.className = 'command-palette__group';
        groupLi.textContent = currentGroup;
        groupLi.setAttribute('role', 'presentation');
        results.appendChild(groupLi);
      }
      const li = document.createElement('li');
      li.className = 'command-palette__item';
      li.setAttribute('role', 'option');
      li.dataset.index = String(idx);
      const metaParts = [];
      if (cmd.description) metaParts.push(`<span>${cmd.description}</span>`);
      if (cmd.shortcut) metaParts.push(`<span>${cmd.shortcut}</span>`);
      const meta = metaParts.length ? `<span class="command-palette__item-meta">${metaParts.join('')}</span>` : '';
      li.innerHTML = `
        <span class="command-palette__item-label">${cmd.label}</span>
        ${meta}
      `;
      li.addEventListener('click', () => {
        const position = Number(li.dataset.index);
        executeCommand(visibleCommands[position]);
      });
      results.appendChild(li);
    });

    activeIndex = 0;
    activateItem(activeIndex);
  };

  const buildSpecialCommands = (query) => {
    const specials = [];
    const trimmed = query.trim();
    const taskMatch = trimmed.match(/^#?(\d{1,8})$/);
    if (taskMatch) {
      const id = taskMatch[1];
      specials.push({
        label: `Open Task #${id}`,
        url: `/task_view.php?id=${id}`,
        description: 'Jump directly to task details',
        group: 'Shortcuts',
        action: 'open-task',
      });
    }
    return specials;
  };

  const filterCommands = (query) => {
    const normalized = query.trim().toLowerCase();
    const specials = buildSpecialCommands(normalized);
    if (!normalized) {
      renderCommands([...specials, ...baseCommands]);
      return;
    }
    const matches = baseCommands.filter((cmd) => {
      const haystack = [cmd.label, cmd.description, cmd.group]
        .filter(Boolean)
        .join(' ') 
        .toLowerCase();
      return haystack.includes(normalized);
    });
    renderCommands([...specials, ...matches]);
  };

  input.addEventListener('input', () => filterCommands(input.value));

  openButtons.forEach((btn) => {
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      openPalette();
    });
  });

  palette.addEventListener('click', (event) => {
    if (event.target.closest('[data-command-close]')) {
      closePalette();
    }
  });

  document.addEventListener('keydown', (event) => {
    if ((event.key === 'k' || event.key === 'K') && (event.metaKey || event.ctrlKey)) {
      event.preventDefault();
      if (palette.hasAttribute('hidden')) {
        openPalette();
      } else {
        closePalette();
      }
    }
  });

  input.addEventListener('keydown', (event) => {
    const items = results.querySelectorAll('.command-palette__item');
    if (!items.length) return;
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      activateItem(Math.min(activeIndex + 1, items.length - 1));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      activateItem(Math.max(activeIndex - 1, 0));
    } else if (event.key === 'Enter') {
      event.preventDefault();
      const el = items[activeIndex];
      if (el) {
        const index = Number(el.dataset.index);
        executeCommand(visibleCommands[index]);
      }
    } else if (event.key === 'Escape') {
      event.preventDefault();
      closePalette();
    }
  });

  window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closePalette();
    }
  });
}

onReady(() => {
  initNav();
  initNotifications();
  initPush();
  initPushControls();
  initRooms();
  initCommandPalette();
});
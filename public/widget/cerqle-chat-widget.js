/*!
 * Cerqle Website Live-Chat Widget
 * Self-contained, dependency-free, rendered inside a Shadow DOM so it never
 * collides with the host site's CSS. Loaded by the per-widget bootstrap served
 * at /widgets/chat/{key}.js, which sets window.__WB_CHAT__ = { key, config }.
 * Realtime is by polling the public widget API.
 */
(function () {
  'use strict';

  var boot = window.__WB_CHAT__;
  if (!boot || !boot.key || !boot.config) return;
  if (window.__WB_CHAT_WIDGET_MOUNTED__) return;
  window.__WB_CHAT_WIDGET_MOUNTED__ = true;

  var KEY = boot.key;
  var CFG = boot.config;
  var API = (CFG.api_base || '').replace(/\/$/, '');
  var COLOR = CFG.primary_color || '#ff762e';
  var LEFT = CFG.position === 'bottom_left';
  var LS_VISITOR = 'wb_chat_visitor_' + KEY;
  var LS_TOKEN = 'wb_chat_token_' + KEY;
  var LS_THREAD = 'wb_chat_thread_' + KEY;   // device-cached message history

  // ── State ──────────────────────────────────────────────────────────────────
  var visitorId = safeGet(LS_VISITOR);
  var token = safeGet(LS_TOKEN);
  var open = false;
  var started = false;      // session established
  var starting = false;
  var lastId = 0;
  var rendered = {};        // message id -> true (dedupe)
  var online = true;
  var pollTimer = null;
  var inviteTimer = null;
  var prechatNeeded = !!CFG.require_prechat && !safeGet('wb_chat_prechat_' + KEY);

  function safeGet(k) { try { return window.localStorage.getItem(k) || ''; } catch (e) { return ''; } }
  function safeSet(k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function initial(s) { return (s || 'S').trim().charAt(0).toUpperCase(); }

  // Device-cached message history: shows instantly on return visits (incl. any
  // agent replies that arrived while the visitor was away) before the network.
  var thread = loadThread();
  function loadThread() { try { return JSON.parse(safeGet(LS_THREAD) || '[]'); } catch (e) { return []; } }
  function saveThread() { safeSet(LS_THREAD, JSON.stringify(thread.slice(-200))); }

  // Identity passed from the client's website (e.g. their logged-in user).
  // Read once here and merged into the session request.
  function getSettings() { return window.CerqleSettings || window.cerqleSettings || {}; }
  function identityPayload(extra) {
    var s = getSettings();
    return {
      name: (extra && extra.name) || s.name || undefined,
      email: (extra && extra.email) || s.email || undefined,
      avatar: s.avatar || s.avatar_url || undefined,
      external_id: s.external_id || s.user_id || undefined,
      user_hash: s.user_hash || undefined
    };
  }

  // ── Shadow host ────────────────────────────────────────────────────────────
  var host = document.createElement('div');
  host.id = 'wb-chat-host';
  host.style.cssText = 'all:initial';
  (document.body || document.documentElement).appendChild(host);
  var root = host.attachShadow({ mode: 'open' });

  var style = document.createElement('style');
  style.textContent = css();
  root.appendChild(style);

  var wrap = document.createElement('div');
  wrap.className = 'wb-wrap ' + (LEFT ? 'wb-left' : 'wb-right');
  wrap.innerHTML = template();
  root.appendChild(wrap);

  // Element refs
  var launcher = root.querySelector('.wb-launcher');
  var panel = root.querySelector('.wb-panel');
  var badge = root.querySelector('.wb-badge');
  var body = root.querySelector('.wb-body');
  var form = root.querySelector('.wb-inputbar');
  var input = root.querySelector('.wb-input');
  var prechat = root.querySelector('.wb-prechat');
  var prechatForm = root.querySelector('.wb-prechat-form');
  var statusEl = root.querySelector('.wb-status');
  var invite = root.querySelector('.wb-launcher-invite');

  // Greeting bubble, then the cached history from this device.
  if (CFG.welcome_message) addBubble('agent', CFG.welcome_message, CFG.agent_name);
  thread.forEach(function (m) {
    rendered[m.id] = true;
    if (m.id > lastId) lastId = m.id;
    addBubble(m.role, m.body, m.agent_name, m.attachment_url, m.type, m.filename);
  });
  updateStatus();
  if (prechatNeeded) { prechat.style.display = 'block'; form.style.display = 'none'; }

  // ── Events ─────────────────────────────────────────────────────────────────
  launcher.addEventListener('click', function () { open ? close() : openPanel(); });
  invite.addEventListener('click', openPanel);
  root.querySelector('.wb-close').addEventListener('click', close);

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text) return;
    input.value = '';
    send(text);
  });

  if (prechatForm) {
    prechatForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var name = (root.querySelector('.wb-pc-name') || {}).value || '';
      var email = (root.querySelector('.wb-pc-email') || {}).value || '';
      ensureSession({ name: name, email: email }).then(function () {
        safeSet('wb_chat_prechat_' + KEY, '1');
        prechat.style.display = 'none';
        form.style.display = 'flex';
        input.focus();
      });
    });
  }

  // If a returning visitor already has a session, quietly restore + poll for
  // any replies that arrived while they were away.
  if (visitorId && token) { ensureSession().then(startPolling); }

  // Start discreetly, then make the live-chat invitation visible. Any page
  // scrolling dismisses it and restarts the five-second idle timer, so it never
  // distracts a visitor while they are actively reading the host website.
  scheduleInvite();
  document.addEventListener('scroll', function () {
    if (!open) scheduleInvite();
  }, true);

  // Public API for the host site: Cerqle('open' | 'close' | 'identify', data).
  // `identify`/`update` lets a site push identity after login (SPA) — re-runs the
  // session so the agent's contact gets the name/email/avatar.
  window.Cerqle = function (action, data) {
    if (action === 'open') { openPanel(); }
    else if (action === 'close') { close(); }
    else if (action === 'identify' || action === 'update') {
      window.CerqleSettings = Object.assign({}, getSettings(), data || {});
      started = false;
      ensureSession().then(startPolling);
    }
  };

  // ── Behaviour ──────────────────────────────────────────────────────────────
  function openPanel() {
    open = true;
    if (inviteTimer) { clearTimeout(inviteTimer); inviteTimer = null; }
    wrap.classList.remove('wb-show-invite');
    wrap.classList.add('wb-open');
    launcher.classList.add('wb-active');
    badge.style.display = 'none';
    if (!prechatNeeded && !started) { ensureSession().then(startPolling); }
    else { startPolling(); }
    setTimeout(function () { if (!prechatNeeded) input.focus(); scrollDown(); }, 60);
  }

  function close() {
    open = false;
    wrap.classList.remove('wb-open');
    launcher.classList.remove('wb-active');
  }

  function scheduleInvite() {
    if (inviteTimer) clearTimeout(inviteTimer);
    wrap.classList.remove('wb-show-invite');
    inviteTimer = setTimeout(function () {
      if (!open) wrap.classList.add('wb-show-invite');
    }, 5000);
  }

  function ensureSession(prechatData) {
    if (started) return Promise.resolve();
    if (starting) return starting;
    var body = { key: KEY, visitor_id: visitorId || undefined };
    var id = identityPayload(prechatData);
    for (var k in id) { if (id[k] !== undefined) body[k] = id[k]; }
    starting = post('/widget/v1/session', body).then(function (data) {
      if (!data) return;
      started = true;
      visitorId = data.visitor_id; token = data.token;
      safeSet(LS_VISITOR, visitorId); safeSet(LS_TOKEN, token);
      online = data.online !== false;
      (data.messages || []).forEach(function (m) { addMessage(m); });
      updateStatus(); scrollDown();
    }).catch(function () {}).then(function () { starting = false; });
    return starting;
  }

  function send(text) {
    ensureSession().then(function () {
      startPolling();
      return post('/widget/v1/messages', { key: KEY, message: text });
    }).then(function (data) {
      // Render from the server echo (carries the real id) so it dedupes cleanly
      // against the next poll — no optimistic double-render.
      if (data && data.message) addMessage(data.message);
    }).catch(function () {});
  }

  function startPolling() {
    if (pollTimer || !started) return;
    var tick = function () {
      poll().then(function () { pollTimer = setTimeout(tick, open ? 3000 : 12000); })
            .catch(function () { pollTimer = setTimeout(tick, 8000); });
    };
    pollTimer = setTimeout(tick, open ? 2500 : 12000);
  }

  function poll() {
    if (!token) return Promise.resolve();
    return get('/widget/v1/messages?key=' + encodeURIComponent(KEY) + '&after=' + lastId).then(function (data) {
      if (!data) return;
      if (typeof data.online === 'boolean') { online = data.online; updateStatus(); }
      (data.messages || []).forEach(function (m) {
        if (m.role === 'agent') { addMessage(m); if (!open) showBadge(); }
        else { addMessage(m); }
      });
    });
  }

  // ── Rendering ──────────────────────────────────────────────────────────────
  function addMessage(m) {
    if (!m || rendered[m.id]) return;
    rendered[m.id] = true;
    if (m.id > lastId) lastId = m.id;
    thread.push({ id: m.id, role: m.role, body: m.body, agent_name: m.agent_name, attachment_url: m.attachment_url, type: m.type, filename: m.filename });
    saveThread();
    addBubble(m.role, m.body, m.agent_name, m.attachment_url, m.type, m.filename);
  }

  function addBubble(role, text, name, attachmentUrl, type, filename) {
    var row = document.createElement('div');
    row.className = 'wb-row wb-' + (role === 'visitor' ? 'out' : 'in');
    var av = '';
    if (role !== 'visitor') {
      av = CFG.avatar_url
        ? '<img class="wb-av" src="' + esc(CFG.avatar_url) + '" alt="">'
        : '<span class="wb-av wb-av-ini">' + esc(initial(name || CFG.agent_name)) + '</span>';
    }
    var attachment = '';
    if (attachmentUrl && type === 'image') {
      attachment = '<img class="wb-media-image" src="' + esc(attachmentUrl) + '" alt="' + esc(filename || text || 'Image attachment') + '">';
    } else if (attachmentUrl) {
      attachment = '<a class="wb-media-file" href="' + esc(attachmentUrl) + '" target="_blank" rel="noopener noreferrer">' + esc(filename || 'Open attachment') + '</a>';
    }
    var caption = text ? '<div class="wb-caption">' + esc(text).replace(/\n/g, '<br>') + '</div>' : '';
    row.innerHTML = av + '<div class="wb-bubble">' + attachment + caption + '</div>';
    body.appendChild(row);
    scrollDown();
  }

  function updateStatus() {
    if (!statusEl) return;
    statusEl.innerHTML = online
      ? '<span class="wb-dot"></span>' + esc(CFG.subtitle || 'Online')
      : '<span class="wb-dot wb-dot-off"></span>' + esc(CFG.offline_message || 'Away — leave a message');
  }

  function showBadge() { badge.style.display = 'block'; }
  function scrollDown() { setTimeout(function () { body.scrollTop = body.scrollHeight; }, 30); }

  // ── HTTP ───────────────────────────────────────────────────────────────────
  function post(path, payload) {
    return fetch(API + path, {
      method: 'POST',
      headers: headers(),
      body: JSON.stringify(payload)
    }).then(handle);
  }
  function get(path) { return fetch(API + path, { method: 'GET', headers: headers() }).then(handle); }
  function headers() {
    var h = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
    if (token) h['X-Widget-Token'] = token;
    return h;
  }
  function handle(r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); }

  // ── Markup + styles ──────────────────────────────────────────────────────────
  function template() {
    var av = CFG.avatar_url
      ? '<img class="wb-head-av" src="' + esc(CFG.avatar_url) + '" alt="">'
      : '<span class="wb-head-av wb-av-ini">' + esc(initial(CFG.agent_name)) + '</span>';
    var pcName = (CFG.prechat_fields || []).indexOf('name') !== -1
      ? '<input class="wb-pc-name" type="text" placeholder="Your name" required>' : '';
    var pcEmail = (CFG.prechat_fields || []).indexOf('email') !== -1
      ? '<input class="wb-pc-email" type="email" placeholder="Email address" required>' : '';
    var launcherIcon = CFG.launcher_logo_url
      ? '<img class="wb-launcher-logo" src="' + esc(CFG.launcher_logo_url) + '" alt="">'
      : '<svg class="wb-ic-chat" width="26" height="26" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3C6.5 3 2 6.9 2 11.7c0 2.2 1 4.3 2.6 5.8-.1 1-.5 2.4-1.4 3.4 1.5-.2 3.2-.8 4.3-1.6 1.4.6 2.9.9 4.5.9 5.5 0 10-3.9 10-8.7S17.5 3 12 3z"/></svg>';
    var inviteTitle = CFG.launcher_text || 'Live Chat!';
    var inviteSubtitle = CFG.subtitle || 'One human agent online now!';
    return '' +
      '<div class="wb-panel" role="dialog" aria-label="Chat">' +
        '<div class="wb-header">' + av +
          '<div class="wb-head-info"><div class="wb-title">' + esc(CFG.title || 'Chat with us') + '</div>' +
          '<div class="wb-status"></div></div>' +
          '<button class="wb-close" aria-label="Close">&#x2715;</button>' +
        '</div>' +
        '<div class="wb-body"></div>' +
        '<div class="wb-prechat">' +
          '<p class="wb-pc-intro">Tell us who you are and we\'ll get right back to you.</p>' +
          '<form class="wb-prechat-form">' + pcName + pcEmail +
            '<button class="wb-pc-btn" type="submit">Start chat</button>' +
          '</form>' +
        '</div>' +
        '<form class="wb-inputbar">' +
          '<input class="wb-input" type="text" placeholder="Type your message…" autocomplete="off">' +
          '<button class="wb-send" type="submit" aria-label="Send">' +
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>' +
          '</button>' +
        '</form>' +
        '<div class="wb-brand">Powered by <b>' + esc(CFG.footer_company_name || 'Cerqle') + '</b></div>' +
      '</div>' +
      '<button class="wb-launcher-invite" type="button" aria-label="Open live chat">' +
        '<span class="wb-invite-card"><strong>' + esc(inviteTitle) + '</strong><small>' + esc(inviteSubtitle) + '</small></span>' +
      '</button>' +
      '<button class="wb-launcher" aria-label="Open chat">' +
        '<span class="wb-badge"></span>' +
        '<span class="wb-launcher-default">' + launcherIcon + '</span>' +
        '<svg class="wb-ic-close" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>' +
      '</button>';
  }

  function css() {
    return [
      ':host{all:initial}',
      '*{box-sizing:border-box;margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}',
      '.wb-wrap{position:fixed;bottom:20px;z-index:2147483647}',
      '.wb-right{right:20px}.wb-left{left:20px}',
      '.wb-launcher{position:relative;width:60px;height:60px;border-radius:50%;border:none;cursor:pointer;color:#fff;background:' + COLOR + ';box-shadow:0 6px 24px rgba(0,0,0,.24);display:flex;align-items:center;justify-content:center;overflow:hidden;transition:opacity .2s,transform .2s}',
      '.wb-launcher:hover{transform:scale(1.06)}.wb-launcher:active{transform:scale(.96)}',
      '.wb-ic-close{display:none}',
      '.wb-launcher-default{display:flex;align-items:center;justify-content:center}.wb-launcher-logo{width:30px;height:30px;object-fit:contain}.wb-active .wb-launcher-default{display:none}.wb-active .wb-ic-close{display:block}',
      '.wb-launcher-invite{position:absolute;bottom:3px;width:224px;max-width:calc(100vw - 104px);border:0;background:transparent;padding:0;cursor:pointer;text-align:left;opacity:0;pointer-events:none;transform:translateX(14px) scale(.92);transition:opacity .28s ease,transform .48s cubic-bezier(.18,1.18,.35,1)}',
      '.wb-right .wb-launcher-invite{right:72px;transform-origin:right center}.wb-left .wb-launcher-invite{left:72px;transform:translateX(-12px) scale(.96);transform-origin:left center}.wb-show-invite .wb-launcher-invite{opacity:1;pointer-events:auto;transform:translateX(0) scale(1)}',
      '.wb-invite-card{position:relative;display:block;width:100%;background:#fff;border-radius:11px;padding:11px 15px;box-shadow:0 5px 18px rgba(0,0,0,.16);color:#20242c}.wb-invite-card:after{content:"";position:absolute;top:50%;right:-8px;margin-top:-8px;border-width:8px 0 8px 9px;border-style:solid;border-color:transparent transparent transparent #fff}.wb-left .wb-invite-card:after{right:auto;left:-8px;border-width:8px 9px 8px 0;border-color:transparent #fff transparent transparent}.wb-invite-card strong{display:block;font-size:15px;line-height:1.2;font-weight:700}.wb-invite-card small{display:block;margin-top:3px;font-size:12px;line-height:1.3;color:#737984;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '.wb-badge{display:none;position:absolute;top:2px;right:2px;width:14px;height:14px;border-radius:50%;background:#ef4444;border:2px solid #fff}',
      '.wb-panel{position:absolute;bottom:74px;' + (LEFT ? 'left:0' : 'right:0') + ';width:370px;max-width:calc(100vw - 40px);height:560px;max-height:calc(100vh - 120px);background:#fff;border-radius:18px;box-shadow:0 16px 50px rgba(0,0,0,.22);display:flex;flex-direction:column;overflow:hidden;opacity:0;transform:translateY(12px) scale(.98);pointer-events:none;transition:opacity .2s,transform .22s cubic-bezier(.34,1.4,.6,1);transform-origin:bottom ' + (LEFT ? 'left' : 'right') + '}',
      '.wb-open .wb-panel{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}',
      '.wb-header{background:' + COLOR + ';color:#fff;padding:16px;display:flex;align-items:center;gap:11px}',
      '.wb-head-av,.wb-av{width:38px;height:38px;border-radius:50%;object-fit:cover;flex-shrink:0}',
      '.wb-av-ini{display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;background:rgba(255,255,255,.25);color:#fff}',
      '.wb-head-info{flex:1;min-width:0}',
      '.wb-title{font-weight:700;font-size:15px;line-height:1.3}',
      '.wb-status{font-size:12px;opacity:.9;display:flex;align-items:center;gap:6px;margin-top:2px}',
      '.wb-dot{width:8px;height:8px;border-radius:50%;background:#4ade80;display:inline-block}',
      '.wb-dot-off{background:#d1d5db}',
      '.wb-close{background:transparent;border:none;color:#fff;font-size:16px;cursor:pointer;opacity:.85;padding:4px;line-height:1}',
      '.wb-close:hover{opacity:1}',
      '.wb-body{flex:1;overflow-y:auto;padding:16px;background:#f7f8fa;display:flex;flex-direction:column;gap:10px}',
      '.wb-row{display:flex;align-items:flex-end;gap:8px;max-width:85%}',
      '.wb-in{align-self:flex-start}.wb-out{align-self:flex-end;flex-direction:row-reverse}',
      '.wb-row .wb-av{width:26px;height:26px;font-size:11px}',
      '.wb-bubble{padding:9px 13px;border-radius:16px;font-size:14px;line-height:1.45;word-wrap:break-word;white-space:normal}',
      '.wb-in .wb-bubble{background:#fff;color:#1f2430;border:1px solid #eceef2;border-bottom-left-radius:5px}',
      '.wb-out .wb-bubble{background:' + COLOR + ';color:#fff;border-bottom-right-radius:5px}',
      '.wb-media-image{display:block;max-width:100%;max-height:240px;border-radius:10px;object-fit:cover;margin-bottom:6px}.wb-caption:empty{display:none}.wb-media-file{display:block;color:inherit;font-weight:600;text-decoration:underline;word-break:break-word}',
      '.wb-prechat{display:none;padding:18px;background:#fff}',
      '.wb-pc-intro{font-size:13px;color:#6b7280;margin-bottom:12px}',
      '.wb-prechat-form{display:flex;flex-direction:column;gap:10px}',
      '.wb-prechat-form input{border:1px solid #dfe2e8;border-radius:10px;padding:11px 13px;font-size:14px;outline:none}',
      '.wb-prechat-form input:focus{border-color:' + COLOR + '}',
      '.wb-pc-btn{background:' + COLOR + ';color:#fff;border:none;border-radius:10px;padding:11px;font-size:14px;font-weight:600;cursor:pointer}',
      '.wb-inputbar{display:flex;align-items:center;gap:8px;padding:12px;border-top:1px solid #eceef2;background:#fff}',
      '.wb-input{flex:1;border:none;outline:none;font-size:14px;padding:8px 4px;background:transparent}',
      '.wb-send{width:38px;height:38px;border-radius:50%;border:none;cursor:pointer;background:' + COLOR + ';color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:opacity .15s}',
      '.wb-send:hover{opacity:.88}',
      '.wb-brand{text-align:center;font-size:11px;color:#9aa1ad;padding:7px;background:#fff;border-top:1px solid #f1f2f4}',
      '.wb-brand b{color:#6b7280}',
      '@media(max-width:420px){.wb-panel{height:calc(100vh - 96px)}}'
    ].join('');
  }
})();

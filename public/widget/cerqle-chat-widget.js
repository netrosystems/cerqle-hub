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
  var SESSION_VERSION = 'v3';
  var LS_VISITOR = 'wb_chat_visitor_' + SESSION_VERSION + '_' + KEY;
  var LS_TOKEN = 'wb_chat_token_' + SESSION_VERSION + '_' + KEY;
  var LS_THREAD = 'wb_chat_thread_' + SESSION_VERSION + '_' + KEY;   // device-cached message history

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
  var unreadCount = 0;
  var audioCtx = null;
  var audioUnlocked = false;
  var mediaRecorder = null;
  var recordingStream = null;
  var recordingChunks = [];
  var pendingAudio = null;
  var pendingImage = null;
  var currentIdentity = {};
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
  function truthy(v) { return v === true || v === 1 || v === '1' || v === 'true' || v === 'yes'; }
  function falsey(v) { return v === false || v === 0 || v === '0' || v === 'false' || v === 'no'; }
  function clean(v) {
    v = v == null ? '' : String(v).trim();
    return v === '' || v === 'null' || v === 'undefined' ? undefined : v;
  }
  function identityPayload(extra) {
    // Pre-chat form submissions are normal anonymous-widget data. They should
    // still create/update the current device's visitor contact.
    if (extra) {
      return {
        identity_kind: 'prechat',
        name: clean(extra.name),
        email: clean(extra.email)
      };
    }

    // Global CerqleSettings is for logged-in visitor passthrough. Do not treat
    // every anonymous page view as the same logged-in customer just because a
    // site included a placeholder object. Hosts can use any of these flags:
    // logged_in / is_logged_in / authenticated.
    var s = getSettings();
    var loggedFlag = s.logged_in !== undefined ? s.logged_in : (s.is_logged_in !== undefined ? s.is_logged_in : s.authenticated);
    if (falsey(loggedFlag)) return {};

    var externalId = clean(s.external_id || s.user_id || s.id);
    var email = clean(s.email);
    var userHash = clean(s.user_hash);
    var explicitlyLoggedIn = truthy(loggedFlag);
    var hasStableIdentity = !!(externalId || userHash || (explicitlyLoggedIn && email));
    if (!hasStableIdentity) return {};

    return {
      identity_kind: 'logged_in',
      name: clean(s.name),
      email: email,
      avatar: clean(s.avatar || s.avatar_url),
      external_id: externalId,
      user_hash: userHash
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
  var imageBtn = root.querySelector('.wb-image-btn');
  var imageInput = root.querySelector('.wb-image-input');
  var imagePreview = root.querySelector('.wb-image-preview');
  var imagePreviewImg = root.querySelector('.wb-image-preview-img');
  var imageSendBtn = root.querySelector('.wb-image-send');
  var imageDiscardBtn = root.querySelector('.wb-image-discard');
  var micBtn = root.querySelector('.wb-mic');
  var audioPreview = root.querySelector('.wb-audio-preview');
  var audioPreviewPlayer = root.querySelector('.wb-audio-player');
  var audioStatus = root.querySelector('.wb-audio-status');
  var audioSendBtn = root.querySelector('.wb-audio-send');
  var audioDiscardBtn = root.querySelector('.wb-audio-discard');
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
  ['click', 'touchstart', 'keydown'].forEach(function (eventName) {
    document.addEventListener(eventName, unlockSound, { once: true, passive: true });
  });
  ['wheel', 'touchmove'].forEach(function (eventName) {
    body.addEventListener(eventName, function (event) {
      event.stopPropagation();
    }, { passive: true });
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text) return;
    input.value = '';
    send(text);
  });
  micBtn.addEventListener('click', toggleRecording);
  audioSendBtn.addEventListener('click', sendPendingAudio);
  audioDiscardBtn.addEventListener('click', discardPendingAudio);
  imageBtn.addEventListener('click', function () { imageInput.click(); });
  imageInput.addEventListener('change', handleImageSelected);
  imageSendBtn.addEventListener('click', sendPendingImage);
  imageDiscardBtn.addEventListener('click', discardPendingImage);

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
      if (Object.keys(identityPayload()).length) {
        started = false;
        ensureSession().then(startPolling);
      }
    }
  };

  // ── Behaviour ──────────────────────────────────────────────────────────────
  function openPanel() {
    open = true;
    if (inviteTimer) { clearTimeout(inviteTimer); inviteTimer = null; }
    wrap.classList.remove('wb-show-invite');
    wrap.classList.add('wb-open');
    launcher.classList.add('wb-active');
    unreadCount = 0;
    updateBadge();
    if (!prechatNeeded && !started) { ensureSession().then(startPolling); }
    else { startPolling(); }
    setTimeout(function () { if (!prechatNeeded) input.focus(); scrollDown(); }, 60);
  }

  function close() {
    open = false;
    wrap.classList.remove('wb-open');
    launcher.classList.remove('wb-active');
    stopRecording(false);
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
    currentIdentity = id || {};
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
      var payload = { key: KEY, message: text };
      for (var k in currentIdentity) { if (currentIdentity[k] !== undefined) payload[k] = currentIdentity[k]; }
      return post('/widget/v1/messages', payload);
    }).then(function (data) {
      updateSessionToken(data);
      // Render from the server echo (carries the real id) so it dedupes cleanly
      // against the next poll — no optimistic double-render.
      if (data && data.message) addMessage(data.message);
    }).catch(function () {});
  }

  function toggleRecording() {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
      stopRecording(true);
      return;
    }
    startRecording();
  }

  function startRecording() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
      setAudioStatus('Audio recording is not supported in this browser.');
      return;
    }
    discardPendingAudio();
    discardPendingImage();
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      recordingStream = stream;
      recordingChunks = [];
      var mimeType = pickRecorderMimeType();
      mediaRecorder = mimeType ? new MediaRecorder(stream, { mimeType: mimeType }) : new MediaRecorder(stream);
      mediaRecorder.addEventListener('dataavailable', function (event) {
        if (event.data && event.data.size > 0) recordingChunks.push(event.data);
      });
      mediaRecorder.addEventListener('stop', function () {
        stopTracks();
        micBtn.classList.remove('wb-recording');
        micBtn.setAttribute('aria-label', 'Record voice message');
        if (!recordingChunks.length) return;
        var blobType = mediaRecorder.mimeType || 'audio/webm';
        var blob = new Blob(recordingChunks, { type: blobType });
        var extension = blobType.indexOf('ogg') !== -1 ? 'ogg' : (blobType.indexOf('mp4') !== -1 ? 'm4a' : 'webm');
        pendingAudio = {
          blob: blob,
          file: new File([blob], 'voice-message.' + extension, { type: blobType }),
          url: URL.createObjectURL(blob)
        };
        audioPreviewPlayer.src = pendingAudio.url;
        audioPreview.style.display = 'flex';
        setAudioStatus('Voice message ready. Send or discard it.');
      });
      mediaRecorder.start();
      micBtn.classList.add('wb-recording');
      micBtn.setAttribute('aria-label', 'Stop recording');
      setAudioStatus('Recording… tap the microphone again to stop.');
    }).catch(function () {
      setAudioStatus('Microphone permission was not granted.');
    });
  }

  function stopRecording(keepRecording) {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
      mediaRecorder.stop();
    } else {
      stopTracks();
    }
    if (!keepRecording) {
      recordingChunks = [];
      micBtn.classList.remove('wb-recording');
    }
  }

  function stopTracks() {
    if (recordingStream) {
      recordingStream.getTracks().forEach(function (track) { track.stop(); });
      recordingStream = null;
    }
  }

  function sendPendingAudio() {
    if (!pendingAudio) return;
    ensureSession().then(function () {
      startPolling();
      audioSendBtn.disabled = true;
      var fd = new FormData();
      fd.append('key', KEY);
      fd.append('type', 'audio');
      fd.append('message', input.value.trim());
      fd.append('attachment', pendingAudio.file);
      appendIdentity(fd);
      return postForm('/widget/v1/messages', fd);
    }).then(function (data) {
      updateSessionToken(data);
      if (data && data.message) addMessage(data.message);
      input.value = '';
      discardPendingAudio();
    }).catch(function () {
      setAudioStatus('Could not send voice message. Please try again.');
    }).then(function () {
      audioSendBtn.disabled = false;
    });
  }

  function handleImageSelected(event) {
    var file = event.target.files && event.target.files[0];
    event.target.value = '';
    if (!file) return;
    if (!/^image\//.test(file.type || '')) {
      setAudioStatus('Please choose an image file.');
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      setAudioStatus('Image is too large. Please choose an image under 10 MB.');
      return;
    }
    discardPendingAudio();
    discardPendingImage();
    pendingImage = { file: file, url: URL.createObjectURL(file) };
    imagePreviewImg.src = pendingImage.url;
    imagePreview.style.display = 'flex';
  }

  function sendPendingImage() {
    if (!pendingImage) return;
    ensureSession().then(function () {
      startPolling();
      imageSendBtn.disabled = true;
      var fd = new FormData();
      fd.append('key', KEY);
      fd.append('type', 'image');
      fd.append('message', input.value.trim());
      fd.append('attachment', pendingImage.file);
      appendIdentity(fd);
      return postForm('/widget/v1/messages', fd);
    }).then(function (data) {
      updateSessionToken(data);
      if (data && data.message) addMessage(data.message);
      input.value = '';
      discardPendingImage();
    }).catch(function () {
      setAudioStatus('Could not send image. Please try again.');
    }).then(function () {
      imageSendBtn.disabled = false;
    });
  }

  function discardPendingImage() {
    if (pendingImage && pendingImage.url) URL.revokeObjectURL(pendingImage.url);
    pendingImage = null;
    imagePreviewImg.removeAttribute('src');
    imagePreview.style.display = 'none';
  }

  function discardPendingAudio() {
    if (pendingAudio && pendingAudio.url) URL.revokeObjectURL(pendingAudio.url);
    pendingAudio = null;
    audioPreviewPlayer.removeAttribute('src');
    audioPreview.style.display = 'none';
    setAudioStatus('');
  }

  function setAudioStatus(text) {
    audioStatus.textContent = text || '';
    audioStatus.style.display = text ? 'block' : 'none';
  }

  function appendIdentity(fd) {
    for (var k in currentIdentity) {
      if (currentIdentity[k] !== undefined) fd.append(k, currentIdentity[k]);
    }
  }

  function updateSessionToken(data) {
    if (data && data.token) {
      token = data.token;
      safeSet(LS_TOKEN, token);
    }
  }

  function pickRecorderMimeType() {
    var types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/ogg', 'audio/mp4'];
    for (var i = 0; i < types.length; i++) {
      if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(types[i])) return types[i];
    }
    return '';
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
        var added = addMessage(m);
        if (added && m.role === 'agent' && (!open || document.hidden)) {
          unreadCount += 1;
          updateBadge();
          playNotificationSound();
        }
      });
    });
  }

  // ── Rendering ──────────────────────────────────────────────────────────────
  function addMessage(m) {
    if (!m || rendered[m.id]) return false;
    rendered[m.id] = true;
    if (m.id > lastId) lastId = m.id;
    thread.push({ id: m.id, role: m.role, body: m.body, agent_name: m.agent_name, attachment_url: m.attachment_url, type: m.type, filename: m.filename });
    saveThread();
    addBubble(m.role, m.body, m.agent_name, m.attachment_url, m.type, m.filename);
    return true;
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
    } else if (attachmentUrl && type === 'audio') {
      attachment = '<audio class="wb-media-audio" src="' + esc(attachmentUrl) + '" controls preload="metadata"></audio>';
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

  function updateBadge() {
    if (!badge) return;
    if (unreadCount > 0 && !open) {
      badge.textContent = unreadCount > 9 ? '9+' : String(unreadCount);
      badge.style.display = 'flex';
      launcher.classList.add('wb-has-unread');
    } else {
      badge.textContent = '';
      badge.style.display = 'none';
      launcher.classList.remove('wb-has-unread');
    }
  }

  function unlockSound() {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      if (audioCtx.state === 'suspended') audioCtx.resume();
      audioUnlocked = true;
    } catch (e) {
      audioUnlocked = false;
    }
  }

  function playNotificationSound() {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      if (!audioUnlocked && audioCtx.state === 'suspended') {
        audioCtx.resume().catch(function () {});
      }
      if (audioCtx.state === 'suspended') return;

      var now = audioCtx.currentTime;
      var gain = audioCtx.createGain();
      gain.gain.setValueAtTime(0.0001, now);
      gain.gain.exponentialRampToValueAtTime(0.08, now + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.42);
      gain.connect(audioCtx.destination);

      [740, 980].forEach(function (freq, index) {
        var osc = audioCtx.createOscillator();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(freq, now + (index * 0.08));
        osc.connect(gain);
        osc.start(now + (index * 0.08));
        osc.stop(now + 0.28 + (index * 0.08));
      });
    } catch (e) {}
  }

  function scrollDown() { setTimeout(function () { body.scrollTop = body.scrollHeight; }, 30); }

  // ── HTTP ───────────────────────────────────────────────────────────────────
  function post(path, payload) {
    return fetch(API + path, {
      method: 'POST',
      headers: headers(),
      body: JSON.stringify(payload)
    }).then(handle);
  }
  function postForm(path, payload) {
    return fetch(API + path, {
      method: 'POST',
      headers: formHeaders(),
      body: payload
    }).then(handle);
  }
  function get(path) { return fetch(API + path, { method: 'GET', headers: headers() }).then(handle); }
  function headers() {
    var h = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
    if (token) h['X-Widget-Token'] = token;
    return h;
  }
  function formHeaders() {
    var h = { 'Accept': 'application/json' };
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
          '<div class="wb-image-preview">' +
            '<img class="wb-image-preview-img" alt="Selected image preview">' +
            '<button class="wb-image-send" type="button">Send</button>' +
            '<button class="wb-image-discard" type="button" aria-label="Discard image">&#x2715;</button>' +
          '</div>' +
          '<div class="wb-audio-preview">' +
            '<audio class="wb-audio-player" controls preload="metadata"></audio>' +
            '<button class="wb-audio-send" type="button">Send</button>' +
            '<button class="wb-audio-discard" type="button" aria-label="Discard voice message">&#x2715;</button>' +
          '</div>' +
          '<div class="wb-audio-status" aria-live="polite"></div>' +
          '<button class="wb-tool-btn wb-image-btn" type="button" aria-label="Attach image" title="Attach image">' +
            '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/></svg>' +
          '</button>' +
          '<input class="wb-image-input" type="file" accept="image/*">' +
          '<button class="wb-mic" type="button" aria-label="Record voice message" title="Record voice message">' +
            '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3"/></svg>' +
          '</button>' +
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
        '<span class="wb-badge" aria-live="polite"></span>' +
        '<span class="wb-launcher-default">' + launcherIcon + '</span>' +
        '<svg class="wb-ic-close" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>' +
      '</button>';
  }

  function css() {
    return [
      ':host{all:initial}',
      '*{box-sizing:border-box;margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}',
      '.wb-wrap{position:fixed;bottom:20px;width:60px;height:60px;z-index:2147483647}',
      '.wb-right{right:20px}.wb-left{left:20px}',
      '.wb-launcher{position:absolute;inset:0;width:60px;height:60px;border-radius:50%;border:none;cursor:pointer;color:#fff;background:' + COLOR + ';box-shadow:0 6px 24px rgba(0,0,0,.24);display:flex;align-items:center;justify-content:center;overflow:visible;transition:opacity .2s,transform .2s}',
      '.wb-launcher:hover{transform:scale(1.06)}.wb-launcher:active{transform:scale(.96)}',
      '.wb-launcher:before{content:"";position:absolute;inset:-7px;border-radius:50%;border:2px solid ' + COLOR + ';opacity:0;transform:scale(.82);pointer-events:none}.wb-has-unread:before{animation:wb-pulse 1.35s ease-out infinite}',
      '.wb-ic-close{display:none}',
      '.wb-launcher-default{display:flex;align-items:center;justify-content:center}.wb-launcher-logo{width:42px;height:42px;object-fit:contain;border-radius:50%}.wb-active .wb-launcher-default{display:none}.wb-active .wb-ic-close{display:block}',
      '.wb-launcher-invite{position:absolute;bottom:3px;width:224px;max-width:calc(100vw - 104px);border:0;background:transparent;padding:0;cursor:pointer;text-align:left;opacity:0;pointer-events:none;transform:translateX(14px) scale(.92);transition:opacity .28s ease,transform .48s cubic-bezier(.18,1.18,.35,1)}',
      '.wb-right .wb-launcher-invite{right:72px;transform-origin:right center}.wb-left .wb-launcher-invite{left:72px;transform:translateX(-12px) scale(.96);transform-origin:left center}.wb-show-invite .wb-launcher-invite{opacity:1;pointer-events:auto;transform:translateX(0) scale(1)}',
      '.wb-invite-card{position:relative;display:block;width:100%;background:#fff;border-radius:11px;padding:11px 15px;box-shadow:0 5px 18px rgba(0,0,0,.16);color:#20242c}.wb-invite-card:after{content:"";position:absolute;top:50%;right:-8px;margin-top:-8px;border-width:8px 0 8px 9px;border-style:solid;border-color:transparent transparent transparent #fff}.wb-left .wb-invite-card:after{right:auto;left:-8px;border-width:8px 9px 8px 0;border-color:transparent #fff transparent transparent}.wb-invite-card strong{display:block;font-size:15px;line-height:1.2;font-weight:700}.wb-invite-card small{display:block;margin-top:3px;font-size:12px;line-height:1.3;color:#737984;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '.wb-badge{display:none;position:absolute;top:-3px;right:-3px;min-width:22px;height:22px;border-radius:999px;background:#ef4444;border:2px solid #fff;color:#fff;align-items:center;justify-content:center;font-size:11px;font-weight:800;line-height:1;box-shadow:0 3px 10px rgba(239,68,68,.42);z-index:2}',
      '.wb-panel{position:absolute;bottom:74px;' + (LEFT ? 'left:0' : 'right:0') + ';width:370px;max-width:calc(100vw - 40px);height:560px;max-height:calc(100vh - 120px);max-height:calc(100dvh - 120px);background:#fff;border-radius:18px;box-shadow:0 16px 50px rgba(0,0,0,.22);display:flex;flex-direction:column;overflow:hidden;opacity:0;transform:translateY(12px) scale(.98);pointer-events:none;transition:opacity .2s,transform .22s cubic-bezier(.34,1.4,.6,1);transform-origin:bottom ' + (LEFT ? 'left' : 'right') + '}',
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
      '.wb-body{flex:1;min-height:0;overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;touch-action:pan-y;padding:16px;background:#f7f8fa;display:flex;flex-direction:column;gap:10px}',
      '.wb-row{display:flex;align-items:flex-end;gap:8px;max-width:85%}',
      '.wb-in{align-self:flex-start}.wb-out{align-self:flex-end;flex-direction:row-reverse}',
      '.wb-row .wb-av{width:26px;height:26px;font-size:11px}',
      '.wb-bubble{padding:9px 13px;border-radius:16px;font-size:14px;line-height:1.45;word-wrap:break-word;white-space:normal}',
      '.wb-in .wb-bubble{background:#fff;color:#1f2430;border:1px solid #eceef2;border-bottom-left-radius:5px}',
      '.wb-out .wb-bubble{background:' + COLOR + ';color:#fff;border-bottom-right-radius:5px}',
      '.wb-media-image{display:block;max-width:100%;max-height:240px;border-radius:10px;object-fit:cover;margin-bottom:6px}.wb-media-audio{display:block;width:220px;max-width:100%;height:38px;margin-bottom:6px}.wb-caption:empty{display:none}.wb-media-file{display:block;color:inherit;font-weight:600;text-decoration:underline;word-break:break-word}',
      '.wb-prechat{display:none;padding:18px;background:#fff}',
      '.wb-pc-intro{font-size:13px;color:#6b7280;margin-bottom:12px}',
      '.wb-prechat-form{display:flex;flex-direction:column;gap:10px}',
      '.wb-prechat-form input{border:1px solid #dfe2e8;border-radius:10px;padding:11px 13px;font-size:14px;outline:none}',
      '.wb-prechat-form input:focus{border-color:' + COLOR + '}',
      '.wb-pc-btn{background:' + COLOR + ';color:#fff;border:none;border-radius:10px;padding:11px;font-size:14px;font-weight:600;cursor:pointer}',
      '.wb-inputbar{display:flex;align-items:center;gap:8px;padding:12px;border-top:1px solid #eceef2;background:#fff;position:relative;flex-wrap:wrap}',
      '.wb-image-preview{display:none;align-items:center;gap:8px;width:100%;padding:8px 9px;border:1px solid #eceef2;border-radius:12px;background:#f8fafc}.wb-image-preview-img{width:48px;height:48px;border-radius:10px;object-fit:cover}.wb-image-send{border:none;border-radius:999px;background:' + COLOR + ';color:#fff;padding:7px 12px;font-size:12px;font-weight:700;cursor:pointer;margin-left:auto}.wb-image-send:disabled{opacity:.6;cursor:wait}.wb-image-discard{width:30px;height:30px;border:none;border-radius:999px;background:#fff;color:#8b93a1;cursor:pointer;border:1px solid #e5e7eb}.wb-image-input{display:none}',
      '.wb-audio-preview{display:none;align-items:center;gap:8px;width:100%;padding:8px 9px;border:1px solid #eceef2;border-radius:12px;background:#f8fafc}.wb-audio-player{flex:1;min-width:160px;height:34px}.wb-audio-send{border:none;border-radius:999px;background:' + COLOR + ';color:#fff;padding:7px 12px;font-size:12px;font-weight:700;cursor:pointer}.wb-audio-send:disabled{opacity:.6;cursor:wait}.wb-audio-discard{width:30px;height:30px;border:none;border-radius:999px;background:#fff;color:#8b93a1;cursor:pointer;border:1px solid #e5e7eb}.wb-audio-status{display:none;width:100%;font-size:11px;color:#6b7280;padding:0 4px 2px}.wb-mic{width:34px;height:34px;border-radius:50%;border:none;cursor:pointer;background:#f1f3f6;color:#687386;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .15s,color .15s,transform .15s}.wb-mic:hover{background:#e7eaf0;color:#303744}.wb-mic.wb-recording{background:#ef4444;color:#fff;animation:wb-record 1s ease-in-out infinite}',
      '.wb-tool-btn{width:34px;height:34px;border-radius:50%;border:none;cursor:pointer;background:#f1f3f6;color:#687386;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .15s,color .15s}.wb-tool-btn:hover{background:#e7eaf0;color:#303744}',
      '.wb-input{flex:1;border:none;outline:none;font-size:14px;padding:8px 4px;background:transparent}',
      '.wb-send{width:38px;height:38px;border-radius:50%;border:none;cursor:pointer;background:' + COLOR + ';color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:opacity .15s}',
      '.wb-send:hover{opacity:.88}',
      '.wb-brand{text-align:center;font-size:11px;color:#9aa1ad;padding:7px;background:#fff;border-top:1px solid #f1f2f4}',
      '.wb-brand b{color:#6b7280}',
      '@keyframes wb-pulse{0%{opacity:.48;transform:scale(.86)}70%{opacity:0;transform:scale(1.28)}100%{opacity:0;transform:scale(1.28)}}',
      '@keyframes wb-record{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}',
      '@media(max-width:420px){.wb-wrap{bottom:10px}.wb-right{right:10px!important;left:auto!important}.wb-left{left:10px!important;right:auto!important}.wb-panel{position:fixed;left:10px!important;right:10px!important;bottom:82px;width:auto;max-width:none;height:auto;max-height:none;top:max(10px,env(safe-area-inset-top));max-height:calc(100vh - 98px);max-height:calc(100dvh - 98px);border-radius:18px;transform-origin:bottom ' + (LEFT ? 'left' : 'right') + '}.wb-body{flex:1 1 auto;min-height:0;overflow-y:auto}.wb-launcher-invite{max-width:calc(100vw - 94px)}}'
    ].join('');
  }
})();

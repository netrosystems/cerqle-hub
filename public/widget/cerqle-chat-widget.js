/*!
 * Cerqle Website Live-Chat Widget
 * Self-contained, dependency-free, rendered inside a Shadow DOM so it never
 * collides with the host site's CSS. Loaded by the per-widget bootstrap served
 * at /widgets/chat/{key}.js, which sets window.__WB_CHAT__ = { key, config }.
 * Realtime uses Pusher when available, with polling as the permanent fallback.
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
  var COLOR = CFG.primary_color || '#3E2A49';
  var LEFT = CFG.position === 'bottom_left';
  var storageScope = identityStorageScope();
  var LS_VISITOR = storageKey('visitor');
  var LS_TOKEN = storageKey('token');
  var LS_THREAD = storageKey('thread');   // identity-scoped cached message history
  var LS_PRECHAT = storageKey('prechat');
  var LS_COMMAND = storageKey('command');

  // ── State ──────────────────────────────────────────────────────────────────
  var visitorId = safeGet(LS_VISITOR);
  var token = safeGet(LS_TOKEN);
  var conversationId = '';
  var open = false;
  var started = false;      // session established
  var starting = false;
  var lastId = 0;
  var rendered = {};        // message id -> true (dedupe)
  var online = true;
  var pollTimer = null;
  var pollInFlight = false;
  var inviteTimer = null;
  var inviteVisibleTimer = null;
  var unreadCount = 0;
  var lastCommandId = safeGet(LS_COMMAND);
  var audioCtx = null;
  var audioUnlocked = false;
  var mediaRecorder = null;
  var recordingStream = null;
  var recordingChunks = [];
  var pendingAudio = null;
  var pendingFile = null;
  var visitorTyping = false;
  var visitorTypingLastSentAt = 0;
  var visitorTypingIdleTimer = null;
  var prechatNeeded = isPrechatNeeded();
  var handoff = { enabled: !!CFG.ai_enabled, eligible: false, status: 'bot' };
  var handoffWatchdog = null;
  var pusherClient = null;
  var pusherChannel = null;
  var realtimeStarting = false;
  var realtimeConnected = false;
  var realtimeDisabled = false;
  var sendingText = false;

  function safeGet(k) { try { return window.localStorage.getItem(k) || ''; } catch (e) { return ''; } }
  function safeSet(k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function escAttr(s) { return esc(s).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
  function initial(s) { return (s || 'S').trim().charAt(0).toUpperCase(); }
  function getSettings() { return window.CerqleSettings || window.cerqleSettings || window.WisperBotSettings || window.wisperBotSettings || {}; }
  function identityStorageScope() {
    var s = getSettings();
    var identity = String(s.external_id || s.externalId || s.user_id || s.userId || s.email || '').trim();
    if (!identity) return 'anonymous';
    var hash = 2166136261;
    for (var i = 0; i < identity.length; i++) {
      hash ^= identity.charCodeAt(i);
      hash = Math.imul(hash, 16777619);
    }
    return 'identity_' + (hash >>> 0).toString(36);
  }
  function storageKey(kind) { return 'wb_chat_' + kind + '_' + KEY + '_' + storageScope; }
  function isPrechatNeeded() {
    if (!CFG.require_prechat) return false;
    var s = getSettings();
    var hasIdentity = !!String(s.email || s.name || s.full_name || '').trim();
    return !hasIdentity && !safeGet(storageKey('prechat'));
  }

  // Device-cached message history: shows instantly on return visits (incl. any
  // agent replies that arrived while the visitor was away) before the network.
  var thread = loadThread();
  function loadThread() { try { return JSON.parse(safeGet(LS_THREAD) || '[]'); } catch (e) { return []; } }
  function saveThread() { safeSet(LS_THREAD, JSON.stringify(thread.slice(-200))); }

  // Identity passed from the client's website (e.g. their logged-in user).
  // Read once here and merged into the session request.
  function identityPayload(extra) {
    var s = getSettings();
    return {
      name: (extra && extra.name) || s.name || s.full_name || undefined,
      email: (extra && extra.email) || s.email || undefined,
      avatar: s.avatar || s.avatar_url || s.avatarUrl || undefined,
      external_id: s.external_id || s.externalId || s.user_id || s.userId || undefined,
      user_hash: s.user_hash || s.userHash || undefined
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
  var sendBtn = root.querySelector('.wb-send');
  var attachBtn = root.querySelector('.wb-attach-btn');
  var fileInput = root.querySelector('.wb-file-input');
  var filePreview = root.querySelector('.wb-file-preview');
  var filePreviewImg = root.querySelector('.wb-file-preview-img');
  var filePreviewDoc = root.querySelector('.wb-file-preview-doc');
  var filePreviewName = root.querySelector('.wb-file-preview-name');
  var filePreviewSize = root.querySelector('.wb-file-preview-size');
  var fileDiscardBtn = root.querySelector('.wb-file-discard');
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
  var handoffEl = root.querySelector('.wb-handoff');
  var agentTypingEl = root.querySelector('.wb-agent-typing');

  // Greeting bubble, then the cached history from this device.
  if (CFG.welcome_message) addBubble('agent', CFG.welcome_message, CFG.agent_name);
  thread.forEach(function (m) {
    rendered[m.id] = true;
    if (m.id > lastId) lastId = m.id;
    addBubble(m.role, m.body, m.agent_name, m.attachment_url, m.type, m.filename, m.mime_type, m.file_size);
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
  ['wheel', 'touchmove'].forEach(function (event) {
    body.addEventListener(event, function (e) {
      e.stopPropagation();
    }, { passive: true });
  });

  function submitCurrent(e) {
    if (e && typeof e.preventDefault === 'function') e.preventDefault();
    if (sendingText) return;

    if (pendingFile) {
      sendPendingFile();
      return;
    }

    if (pendingAudio) {
      sendPendingAudio();
      return;
    }

    var text = input.value.trim();
    if (!text) return;
    stopVisitorTyping();
    input.value = '';
    send(text);
  }

  form.addEventListener('submit', submitCurrent);
  if (sendBtn) {
    sendBtn.addEventListener('click', submitCurrent);
  }
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      submitCurrent(e);
    }
  });

  input.addEventListener('input', noteVisitorTyping);
  input.addEventListener('blur', stopVisitorTyping);
  micBtn.addEventListener('click', toggleRecording);
  audioSendBtn.addEventListener('click', sendPendingAudio);
  audioDiscardBtn.addEventListener('click', discardPendingAudio);
  attachBtn.addEventListener('click', function () { fileInput.click(); });
  fileInput.addEventListener('change', handleFileSelected);
  fileDiscardBtn.addEventListener('click', discardPendingFile);

  // Drag & drop files onto widget panel
  if (panel) {
    panel.addEventListener('dragover', function (e) {
      e.preventDefault();
      e.stopPropagation();
      panel.classList.add('wb-drop-active');
    });
    panel.addEventListener('dragleave', function (e) {
      e.preventDefault();
      e.stopPropagation();
      panel.classList.remove('wb-drop-active');
    });
    panel.addEventListener('drop', function (e) {
      e.preventDefault();
      e.stopPropagation();
      panel.classList.remove('wb-drop-active');
      var dt = e.dataTransfer;
      if (dt && dt.files && dt.files.length > 0) {
        processSelectedFile(dt.files[0]);
      }
    });
  }

  handoffEl.addEventListener('click', function (event) {
    if (event.target.closest('.wb-handoff-btn')) requestHumanAgent();
  });

  if (prechatForm) {
    prechatForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var name = (root.querySelector('.wb-pc-name') || {}).value || '';
      var email = (root.querySelector('.wb-pc-email') || {}).value || '';
      ensureSession({ name: name, email: email }).then(function () {
        safeSet(LS_PRECHAT, '1');
        prechat.style.display = 'none';
        form.style.display = 'flex';
        input.focus();
      });
    });
  }

  // Establish presence before the visitor opens the panel, but only while this
  // page is actually visible. Hidden/background tabs must not inflate the
  // client's Live Users count.
  resumePresence();
  document.addEventListener('visibilitychange', function () {
    if (pageCanReportPresence()) resumePresence();
    else pausePolling();
  });
  window.addEventListener('pageshow', resumePresence);
  window.addEventListener('pagehide', pausePolling);

  // Start discreetly, then make the live-chat invitation visible. Any page
  // scrolling dismisses it and restarts the twenty-second idle timer, so it never
  // distracts a visitor while they are actively reading the host website.
  scheduleInvite();
  document.addEventListener('scroll', function () {
    if (!open) scheduleInvite();
  }, true);

  // Public API for the host site: Cerqle('open' | 'close' | 'identify', data).
  // `identify`/`update` lets a site push identity after login (SPA) — re-runs the
  // session so the agent's contact gets the name/email/avatar.
  window.Cerqle = window.WisperBot = function (action, data) {
    if (action === 'open') { openPanel(); }
    else if (action === 'close') { close(); }
    else if (action === 'identify' || action === 'update') {
      window.CerqleSettings = window.WisperBotSettings = action === 'identify'
        ? Object.assign({}, data || {})
        : Object.assign({}, getSettings(), data || {});
      switchIdentityScope();
      resumePresence();
    } else if (action === 'logout' || action === 'reset') {
      window.CerqleSettings = window.WisperBotSettings = {};
      switchIdentityScope();
    }
  };

  // ── Behaviour ──────────────────────────────────────────────────────────────
  function openPanel() {
    open = true;
    if (inviteTimer) { clearTimeout(inviteTimer); inviteTimer = null; }
    if (inviteVisibleTimer) { clearTimeout(inviteVisibleTimer); inviteVisibleTimer = null; }
    wrap.classList.remove('wb-show-invite');
    wrap.classList.add('wb-open');
    launcher.classList.add('wb-active');
    unreadCount = 0;
    updateBadge();
    if (token) { post('/widget/v1/read', { key: KEY }).catch(function () {}); }
    if (!prechatNeeded && !started) { ensureSession().then(startPolling); }
    else { startPolling(); }
    if (!prechatNeeded) setTimeout(function () { input.focus(); }, 120);
    scrollToBottom();
    connectRealtime();
  }

  function close() {
    open = false;
    wrap.classList.remove('wb-open');
    launcher.classList.remove('wb-active');
    pausePolling();
    scheduleInvite();
  }

  function scheduleInvite() {
    if (inviteTimer) clearTimeout(inviteTimer);
    if (inviteVisibleTimer) clearTimeout(inviteVisibleTimer);
    inviteTimer = null;
    inviteVisibleTimer = null;
    if (open) {
      wrap.classList.remove('wb-show-invite');
      return;
    }
    wrap.classList.remove('wb-show-invite');

    var delay = typeof CFG.launcher_delay === 'number' ? CFG.launcher_delay : 20;
    if (delay <= 0) return;

    inviteTimer = setTimeout(function () {
      if (open) return;
      wrap.classList.add('wb-show-invite');
      inviteVisibleTimer = setTimeout(function () {
        wrap.classList.remove('wb-show-invite');
      }, 10000);
    }, delay * 1000);
  }

  function updateBadge() {
    if (unreadCount > 0) {
      badge.textContent = unreadCount > 99 ? '99+' : unreadCount;
      badge.style.display = 'flex';
      launcher.classList.add('wb-has-unread');
    } else {
      badge.style.display = 'none';
      launcher.classList.remove('wb-has-unread');
    }
  }

  function updateStatus() {
    var teamHtml = renderTeamAvatars();
    if (teamHtml) {
      statusEl.innerHTML = teamHtml;
      return;
    }

    statusEl.innerHTML = online
      ? '<span class="wb-dot"></span> <span class="wb-status-label">' + esc(CFG.online_text || 'Typically replies in a few minutes') + '</span>'
      : '<span class="wb-dot wb-dot-off"></span> <span class="wb-status-label">' + esc(CFG.offline_text || 'We are offline right now') + '</span>';
  }

  function renderTeamAvatars() {
    var members = Array.isArray(CFG.team_members) ? CFG.team_members.filter(function (m) { return m && (m.avatar_url || m.name); }) : [];
    if (!members.length) return '';

    var max = 3;
    var visible = members.slice(0, max);
    var remainder = members.length - visible.length;

    var stack = '<span class="wb-team-stack">';
    for (var i = 0; i < visible.length; i++) {
      var member = visible[i];
      var av = member.avatar_url
        ? '<img src="' + esc(member.avatar_url) + '" alt="' + esc(member.name || '') + '">'
        : '<span>' + esc(initial(member.name)) + '</span>';
      stack += '<span class="wb-team-avatar" title="' + esc(member.name || 'Team member') + '">' + av + '</span>';
    }
    if (remainder > 0) {
      stack += '<span class="wb-team-more" title="' + remainder + ' more team members">+' + remainder + '</span>';
    }
    stack += '</span>';

    var label = CFG.online_text || (members.length === 1 ? (visible[0].name || 'Agent') + ' is online' : 'Team is online');
    return stack + ' <span class="wb-status-label">' + esc(label) + '</span>';
  }

  function resumePresence() {
    if (!pageCanReportPresence()) return;
    ensureSession().then(function () {
      startPolling();
      connectRealtime();
    }).catch(function () {});
  }

  function switchIdentityScope() {
    var newScope = identityStorageScope();
    if (newScope === storageScope) {
      if (started) ensureSession(null, true).catch(function () {});
      return;
    }

    storageScope = newScope;
    LS_VISITOR = storageKey('visitor');
    LS_TOKEN = storageKey('token');
    LS_THREAD = storageKey('thread');
    LS_PRECHAT = storageKey('prechat');
    LS_COMMAND = storageKey('command');

    visitorId = safeGet(LS_VISITOR);
    token = safeGet(LS_TOKEN);
    lastCommandId = safeGet(LS_COMMAND);
    prechatNeeded = isPrechatNeeded();
    started = false;
    starting = false;
    lastId = 0;
    rendered = {};
    thread = loadThread();

    body.innerHTML = '';
    if (CFG.welcome_message) addBubble('agent', CFG.welcome_message, CFG.agent_name);
    thread.forEach(function (m) {
      rendered[m.id] = true;
      if (m.id > lastId) lastId = m.id;
      addBubble(m.role, m.body, m.agent_name, m.attachment_url, m.type, m.filename, m.mime_type, m.file_size);
    });

    if (prechatNeeded) {
      prechat.style.display = 'block';
      form.style.display = 'none';
    } else {
      prechat.style.display = 'none';
      form.style.display = 'flex';
    }

    if (open || pageCanReportPresence()) {
      resumePresence();
    }
  }

  function ensureSession(prechatData, force) {
    if (started && !force && !prechatData) return Promise.resolve();
    if (starting) return starting;
    var body = {
      key: KEY,
      visitor_id: visitorId || undefined,
      active: pageCanReportPresence(),
      page_url: typeof window !== 'undefined' ? window.location.href : undefined,
      page_title: typeof document !== 'undefined' ? document.title : undefined
    };
    var id = identityPayload(prechatData);
    for (var k in id) { if (id[k] !== undefined) body[k] = id[k]; }
    starting = post('/widget/v1/session', body).then(function (data) {
      if (!data || !data.token || !data.visitor_id) throw new Error('session unavailable');
      started = true;
      visitorId = data.visitor_id; token = data.token; conversationId = data.conversation_id || '';
      safeSet(LS_VISITOR, visitorId); safeSet(LS_TOKEN, token);
      if (data.online !== undefined) { online = !!data.online; updateStatus(); }
      if (data.config) applyConfigUpdates(data.config);
      if (data.handover) applyHandover(data.handover);
      if (data.handoff) applyHandoff(data.handoff);
      if (Array.isArray(data.messages)) {
        data.messages.forEach(function (m) { addMessage(m); });
      }
      return registerPushSubscription();
    }).catch(function (err) {
      starting = false;
      throw err;
    }).then(function () {
      starting = false;
    });
    return starting;
  }

  function send(text) {
    if (sendingText) return;
    sendingText = true;
    if (sendBtn) sendBtn.disabled = true;
    var clientMessageId = makeClientMessageId();
    // Render immediately; a slow network must never make a submitted message
    // look lost. Confirm this bubble in-place when the server echo arrives.
    var optimisticRow = addBubble('visitor', text);
    if (optimisticRow) {
      optimisticRow.classList.add('wb-pending');
      optimisticRow.setAttribute('data-wb-pending-body', text);
      optimisticRow.setAttribute('data-wb-client-message-id', clientMessageId);
    }
    if (handoff.status !== 'connected') {
      renderAgentTyping({ is_typing: true, name: CFG.agent_name || 'Support' });
    }

    function doSend(attempt) {
      return ensureSession().then(function () {
        startPolling();
        return post('/widget/v1/messages', { key: KEY, message: text, client_message_id: clientMessageId });
      }).catch(function (err) {
        if (attempt < 1 && err && /401|404/.test(String(err.message || err))) {
          token = '';
          started = false;
          safeSet(LS_TOKEN, '');
          return ensureSession(null, true).then(function () {
            return post('/widget/v1/messages', { key: KEY, message: text, client_message_id: clientMessageId });
          });
        }
        throw err;
      });
    }

    doSend(0).then(function (data) {
      if (optimisticRow && optimisticRow.parentNode) optimisticRow.parentNode.removeChild(optimisticRow);
      if (data && data.message) addMessage(data.message);
      if (data) applyHandoff(data.handoff);

      // Fast check in 1.2s and 2.8s for asynchronous AI replies
      setTimeout(function () { if (open) poll().catch(function () {}); }, 1200);
      setTimeout(function () { if (open) poll().catch(function () {}); }, 2800);
    }).catch(function () {
      renderAgentTyping(null);
      if (!optimisticRow) return;
      optimisticRow.classList.remove('wb-pending');
      optimisticRow.classList.add('wb-failed');
      optimisticRow.title = 'Message was not sent. Click to try again.';
      optimisticRow.addEventListener('click', function retry() {
        if (optimisticRow.parentNode) optimisticRow.parentNode.removeChild(optimisticRow);
        send(text);
      }, { once: true });
    }).then(function () {
      sendingText = false;
      if (sendBtn) sendBtn.disabled = false;
    });
  }

  function noteVisitorTyping() {
    if (!input.value.trim()) {
      stopVisitorTyping();
      return;
    }

    var now = Date.now();
    if (!visitorTyping || now - visitorTypingLastSentAt >= 2500) {
      sendVisitorTyping(true);
    }

    if (visitorTypingIdleTimer) clearTimeout(visitorTypingIdleTimer);
    visitorTypingIdleTimer = setTimeout(stopVisitorTyping, 1600);
  }

  function stopVisitorTyping() {
    if (visitorTypingIdleTimer) clearTimeout(visitorTypingIdleTimer);
    visitorTypingIdleTimer = null;
    if (visitorTyping) sendVisitorTyping(false);
  }

  function sendVisitorTyping(isTyping) {
    visitorTyping = isTyping;
    visitorTypingLastSentAt = Date.now();
    if (!started || !token) {
      if (isTyping) {
        ensureSession().then(function () {
          if (visitorTyping && token) sendVisitorTyping(true);
        });
      }
      return;
    }

    post('/widget/v1/typing', {
      key: KEY,
      is_typing: isTyping
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
    discardPendingFile();
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
    if (!pendingAudio || sendingText) return;
    sendingText = true;
    if (audioSendBtn) audioSendBtn.disabled = true;
    if (sendBtn) sendBtn.disabled = true;
    var caption = input.value.trim();
    var clientMessageId = makeClientMessageId();

    function doSend(attempt) {
      return ensureSession().then(function () {
        startPolling();
        var fd = new FormData();
        fd.append('key', KEY);
        fd.append('type', 'audio');
        fd.append('client_message_id', clientMessageId);
        if (caption) fd.append('message', caption);
        fd.append('attachment', pendingAudio.file);
        return postForm('/widget/v1/messages', fd);
      }).catch(function (err) {
        if (attempt < 1 && err && /401|404/.test(String(err.message || err))) {
          token = '';
          started = false;
          safeSet(LS_TOKEN, '');
          return ensureSession(null, true).then(function () {
            var fd = new FormData();
            fd.append('key', KEY);
            fd.append('type', 'audio');
            fd.append('client_message_id', clientMessageId);
            if (caption) fd.append('message', caption);
            fd.append('attachment', pendingAudio.file);
            return postForm('/widget/v1/messages', fd);
          });
        }
        throw err;
      });
    }

    doSend(0).then(function (data) {
      if (data && data.message) addMessage(data.message);
      if (data) applyHandoff(data.handoff);
      input.value = '';
      discardPendingAudio();
    }).catch(function () {
      setAudioStatus('Could not send voice message. Please try again.');
    }).then(function () {
      sendingText = false;
      if (audioSendBtn) audioSendBtn.disabled = false;
      if (sendBtn) sendBtn.disabled = false;
    });
  }

  function handleFileSelected(event) {
    var file = event.target.files && event.target.files[0];
    event.target.value = '';
    if (!file) return;
    processSelectedFile(file);
  }

  function processSelectedFile(file) {
    if (!file) return;
    if (file.size > 10 * 1024 * 1024) {
      setAudioStatus('File is too large. Please choose a file under 10 MB.');
      return;
    }
    discardPendingAudio();
    discardPendingFile();

    var ext = (file.name || '').split('.').pop().toLowerCase();
    var isImage = /^image\//.test(file.type || '') || ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'].indexOf(ext) !== -1;
    var sizeStr = formatFileSize(file.size);
    var previewUrl = isImage ? URL.createObjectURL(file) : null;

    pendingFile = {
      file: file,
      url: previewUrl,
      isImage: isImage,
      name: file.name || 'Attachment',
      size: sizeStr,
      ext: ext
    };

    if (isImage) {
      filePreviewImg.src = previewUrl;
      filePreviewImg.style.display = 'block';
      if (filePreviewDoc) filePreviewDoc.style.display = 'none';
    } else {
      filePreviewImg.removeAttribute('src');
      filePreviewImg.style.display = 'none';
      if (filePreviewName) filePreviewName.textContent = pendingFile.name;
      if (filePreviewSize) filePreviewSize.textContent = pendingFile.size;
      if (filePreviewDoc) filePreviewDoc.style.display = 'flex';
    }
    if (filePreview) filePreview.style.display = 'flex';
    setAudioStatus('');
  }

  function sendPendingFile() {
    if (!pendingFile || sendingText) return;
    sendingText = true;
    if (sendBtn) sendBtn.disabled = true;
    var caption = input.value.trim();
    var clientMessageId = makeClientMessageId();

    function doSend(attempt) {
      return ensureSession().then(function () {
        startPolling();
        var fd = new FormData();
        fd.append('key', KEY);
        fd.append('type', pendingFile.isImage ? 'image' : 'document');
        fd.append('client_message_id', clientMessageId);
        if (caption) fd.append('message', caption);
        fd.append('attachment', pendingFile.file);
        return postForm('/widget/v1/messages', fd);
      }).catch(function (err) {
        if (attempt < 1 && err && /401|404/.test(String(err.message || err))) {
          token = '';
          started = false;
          safeSet(LS_TOKEN, '');
          return ensureSession(null, true).then(function () {
            var fd = new FormData();
            fd.append('key', KEY);
            fd.append('type', pendingFile.isImage ? 'image' : 'document');
            fd.append('client_message_id', clientMessageId);
            if (caption) fd.append('message', caption);
            fd.append('attachment', pendingFile.file);
            return postForm('/widget/v1/messages', fd);
          });
        }
        throw err;
      });
    }

    doSend(0).then(function (data) {
      if (data && data.message) addMessage(data.message);
      if (data) applyHandoff(data.handoff);
      input.value = '';
      discardPendingFile();
    }).catch(function () {
      setAudioStatus('Could not send attachment. Please try again.');
    }).then(function () {
      sendingText = false;
      if (sendBtn) sendBtn.disabled = false;
    });
  }

  function discardPendingFile() {
    if (pendingFile && pendingFile.url) URL.revokeObjectURL(pendingFile.url);
    pendingFile = null;
    if (filePreviewImg) {
      filePreviewImg.removeAttribute('src');
      filePreviewImg.style.display = 'none';
    }
    if (filePreviewDoc) filePreviewDoc.style.display = 'none';
    if (filePreview) filePreview.style.display = 'none';
  }

  function formatFileSize(bytes) {
    if (!bytes || bytes <= 0) return '0 KB';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
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

  function pickRecorderMimeType() {
    var types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/ogg', 'audio/mp4'];
    for (var i = 0; i < types.length; i++) {
      if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(types[i])) return types[i];
    }
    return '';
  }

  function startPolling() {
    if (pollTimer || pollInFlight || !started || !pageCanReportPresence()) return;
    var tick = function () {
      pollTimer = null;
      if (!pageCanReportPresence()) return;
      pollInFlight = true;
      poll().then(function () {
        pollInFlight = false;
        pollTimer = pageCanReportPresence() ? setTimeout(tick, pollDelay()) : null;
      }).catch(function () {
        pollInFlight = false;
        pollTimer = pageCanReportPresence() ? setTimeout(tick, realtimeConnected ? 30000 : 8000) : null;
      });
    };
    pollTimer = setTimeout(tick, realtimeConnected ? 3000 : (open ? 2000 : 5000));
  }

  function pollDelay() {
    if (realtimeConnected) return open ? 5000 : 8000;
    return open ? 3000 : 5000;
  }

  function pageCanReportPresence() {
    return document.visibilityState !== 'hidden';
  }

  function pausePolling() {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = null;
  }

  function poll() {
    if (!token) return Promise.resolve();
    return get('/widget/v1/messages?key=' + encodeURIComponent(KEY) + '&after=' + lastId + '&active=' + (pageCanReportPresence() ? '1' : '0')).then(function (data) {
      if (!data) return;
      if (data.online !== undefined && data.online !== online) { online = !!data.online; updateStatus(); }
      if (data.config) applyConfigUpdates(data.config);
      if (data.handover) applyHandover(data.handover);
      if (data.handoff) applyHandoff(data.handoff);
      if (data.typing) renderAgentTyping(data.typing);
      if (data.command) applyCommand(data.command);
      if (Array.isArray(data.statuses)) {
        data.statuses.forEach(function (st) {
          if (st && st.id) updateVisitorMessageStatus(st.id, st.status);
        });
      }
      if (Array.isArray(data.messages) && data.messages.length > 0) {
        var agentCount = 0;
        data.messages.forEach(function (m) {
          if (m.role === 'agent' && !rendered[m.id]) agentCount++;
          addMessage(m);
        });
        if (agentCount > 0) {
          notifyAboutAgentMessages(agentCount);
          if (open) post('/widget/v1/read', { key: KEY }).catch(function () {});
        }
      }
    });
  }

  function connectRealtime() {
    if (realtimeDisabled || realtimeConnected || realtimeStarting || !started || !conversationId) return;
    if (typeof window.Pusher !== 'function') {
      loadPusherScript().then(connectRealtime).catch(function () {
        realtimeDisabled = true;
      });
      return;
    }

    realtimeStarting = true;
    var getPusherCfg = (CFG && CFG.realtime && CFG.realtime.key)
      ? Promise.resolve(CFG.realtime)
      : get('/widget/v1/pusher-config?key=' + encodeURIComponent(KEY));

    getPusherCfg.then(function (cfg) {
      if (!cfg || !cfg.key) {
        realtimeDisabled = true;
        realtimeStarting = false;
        return;
      }

      pusherClient = new window.Pusher(cfg.key, {
        cluster: cfg.cluster || 'mt1',
        wsHost: cfg.wsHost || undefined,
        wsPort: cfg.wsPort || undefined,
        wssPort: cfg.wssPort || undefined,
        forceTLS: cfg.forceTLS !== undefined ? !!cfg.forceTLS : true,
        enabledTransports: cfg.wsHost ? ['ws', 'wss'] : ['ws', 'wss', 'xhr-streaming', 'xhr-polling'],
        authEndpoint: cfg.auth_endpoint || cfg.authEndpoint || (API + '/widget/v1/broadcasting/auth'),
        auth: {
          headers: formHeaders(),
          params: { key: KEY }
        }
      });

      var channelName = 'private-widget-conversation.' + conversationId;
      pusherChannel = pusherClient.subscribe(channelName);

      pusherChannel.bind('pusher:subscription_succeeded', function () {
        realtimeConnected = true;
        realtimeStarting = false;
      });

      pusherChannel.bind('pusher:subscription_error', function () {
        disconnectRealtime();
      });

      var handleNewMessage = function (data) {
        var msg = (data && data.message) ? data.message : data;
        if (!msg) return;
        var isNew = addMessage(msg);
        if (isNew && msg.role === 'agent') {
          notifyAboutAgentMessages(1);
          if (open) {
            post('/widget/v1/read', { key: KEY }).catch(function () {});
          } else {
            post('/widget/v1/delivered', { key: KEY, message_id: msg.id }).catch(function () {});
          }
        }
      };

      pusherChannel.bind('WidgetMessageCreated', handleNewMessage);
      pusherChannel.bind('.WidgetMessageCreated', handleNewMessage);
      pusherChannel.bind('MessageReceived', handleNewMessage);
      pusherChannel.bind('.MessageReceived', handleNewMessage);

      var handleStatusUpdate = function (data) {
        if (data && data.id) updateVisitorMessageStatus(data.id, data.status);
      };
      pusherChannel.bind('MessageStatusUpdated', handleStatusUpdate);
      pusherChannel.bind('.MessageStatusUpdated', handleStatusUpdate);

      var handleTyping = function (data) {
        renderAgentTyping(data);
      };
      pusherChannel.bind('TypingChanged', handleTyping);
      pusherChannel.bind('.TypingChanged', handleTyping);
      pusherChannel.bind('WidgetTypingChanged', handleTyping);
      pusherChannel.bind('.WidgetTypingChanged', handleTyping);

      var handleHandoff = function (data) {
        if (data && data.handoff) applyHandoff(data.handoff);
      };
      pusherChannel.bind('ConversationHandoff', handleHandoff);
      pusherChannel.bind('.ConversationHandoff', handleHandoff);
      pusherChannel.bind('WidgetHandoffUpdated', handleHandoff);
      pusherChannel.bind('.WidgetHandoffUpdated', handleHandoff);
    }).catch(function () {
      realtimeStarting = false;
      realtimeConnected = false;
    });
  }

  function loadPusherScript() {
    return new Promise(function (resolve, reject) {
      if (typeof window.Pusher === 'function') { resolve(); return; }
      var script = document.createElement('script');
      script.src = 'https://js.pusher.com/8.2.0/pusher.min.js';
      script.async = true;
      script.onload = resolve;
      script.onerror = reject;
      (document.head || document.documentElement).appendChild(script);
    });
  }

  function disconnectRealtime() {
    realtimeConnected = false;
    realtimeStarting = false;
    if (pusherClient) {
      try {
        if (pusherChannel) pusherClient.unsubscribe(pusherChannel.name);
        pusherClient.disconnect();
      } catch (e) {}
    }
    pusherClient = null;
    pusherChannel = null;
  }

  function applyCommand(command) {
    if (!command || !command.id || command.id === lastCommandId) return;
    lastCommandId = command.id;
    safeSet(LS_COMMAND, lastCommandId);
    if (command.type === 'open_widget') openPanel();
  }

  function addMessage(m) {
    if (!m) return false;
    if (m.role === 'agent') renderAgentTyping(null);
    if (rendered[m.id]) return false;

    var pendingRow = findMatchingPendingVisitorRow(m);
    if (pendingRow) {
      pendingRow.classList.remove('wb-pending');
      pendingRow.removeAttribute('data-wb-pending-body');
      pendingRow.removeAttribute('data-wb-client-message-id');
      var bubble = pendingRow.querySelector('.wb-bubble');
      if (bubble && !bubble.querySelector('.wb-status-glyph')) {
        var glyph = m.status === 'read' ? '✓✓' : (m.status === 'delivered' ? '✓✓' : (m.status === 'sent' ? '✓' : '⋯'));
        var statusClass = 'wb-status-glyph' + (m.status === 'read' ? ' wb-status-read' : '');
        bubble.insertAdjacentHTML('beforeend', '<span class="' + statusClass + '" data-wb-status-id="' + escAttr(m.id || '') + '">' + glyph + '</span>');
      }
      rendered[m.id] = true;
      if (m.id > lastId) lastId = m.id;
      thread.push({ id: m.id, role: m.role, body: m.body, agent_name: m.agent_name, attachment_url: m.attachment_url, type: m.type, filename: m.filename, mime_type: m.mime_type, file_size: m.file_size, status: m.status });
      saveThread();
      return true;
    }

    rendered[m.id] = true;
    if (m.id > lastId) lastId = m.id;
    thread.push({ id: m.id, role: m.role, body: m.body, agent_name: m.agent_name, attachment_url: m.attachment_url, type: m.type, filename: m.filename, mime_type: m.mime_type, file_size: m.file_size, status: m.status });
    saveThread();
    addBubble(m.role, m.body, m.agent_name, m.attachment_url, m.type, m.filename, m.mime_type, m.file_size, m.status, m.id);
    return true;
  }

  function updateVisitorMessageStatus(msgId, status) {
    if (!body || !msgId) return;
    var el = body.querySelector('[data-wb-status-id="' + String(msgId) + '"]');
    if (!el) return;
    var glyph = status === 'read' ? '✓✓' : (status === 'delivered' ? '✓✓' : (status === 'sent' ? '✓' : '⋯'));
    el.textContent = glyph;
    if (status === 'read') el.classList.add('wb-status-read');
    else el.classList.remove('wb-status-read');
  }

  function findMatchingPendingVisitorRow(m) {
    if (!m || m.role !== 'visitor' || !body) return null;

    var pending = body.querySelectorAll('.wb-row.wb-out.wb-pending');
    var expectedBody = String(m.body || '').trim();
    var expectedClientId = m.client_message_id ? String(m.client_message_id) : '';

    for (var i = 0; i < pending.length; i++) {
      var row = pending[i];
      var rowClientId = row.getAttribute('data-wb-client-message-id') || '';
      var rowBody = (row.getAttribute('data-wb-pending-body') || '').trim();

      if ((expectedClientId && rowClientId === expectedClientId) || (!expectedClientId && expectedBody && rowBody === expectedBody)) {
        return row;
      }
    }
    return null;
  }

  function makeClientMessageId() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }

    return 'cm_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 12);
  }

  function renderAgentTyping(presence) {
    if (!agentTypingEl) return;
    var isTyping = !!(presence && presence.is_typing);
    agentTypingEl.style.display = isTyping ? 'flex' : 'none';
    if (!isTyping) return;

    var name = presence.name || 'Team';
    var label = name === 'Team' ? 'Team is typing' : name + ' is typing';
    agentTypingEl.innerHTML =
      '<span class="wb-typing-dots" aria-hidden="true"><i></i><i></i><i></i></span>' +
      '<span>' + esc(label) + '</span>';
  }

  function notifyAboutAgentMessages(count) {
    if (!count) return;

    // The launcher badge is for chats the visitor is not currently viewing.
    // A short sound also helps while the panel is open, unless the visitor is
    // actively composing a reply. Browsers may require one prior interaction
    // before allowing audio; unlockSound handles that transparently.
    if (!open || document.hidden) {
      unreadCount += count;
      updateBadge();
    }

    var activelyComposing = open && !document.hidden && visitorTyping && !!input.value.trim();
    if (!activelyComposing) playNotificationSound();
  }

  function addBubble(role, text, name, attachmentUrl, type, filename, mimeType, fileSize, status, id) {
    var row = document.createElement('div');
    row.className = 'wb-row wb-' + (role === 'visitor' ? 'out' : 'in');
    var av = '';
    if (role !== 'visitor') {
      av = '<span class="wb-av-shell"><img class="wb-av" src="' + esc(resolveLogoUrl(CFG.avatar_url)) + '" alt=""></span>';
    }
    var attachment = '';
    if (attachmentUrl && type === 'image') {
      attachment = '<img class="wb-media-image" src="' + esc(attachmentUrl) + '" alt="' + esc(filename || text || 'Image attachment') + '">';
    } else if (attachmentUrl && type === 'audio') {
      // Firefox is much more reliable with an explicit type for recorded
      // WebM/Opus and OGG voice messages; without it it can show 0:00.
      var audioType = mimeType || inferAudioMimeType(filename);
      attachment = '<audio class="wb-media-audio" controls preload="metadata"><source src="' + esc(attachmentUrl) + '"' + (audioType ? ' type="' + escAttr(audioType) + '"' : '') + '>Your browser cannot play this audio.</audio>';
    } else if (attachmentUrl) {
      var ext = (filename || '').split('.').pop().toUpperCase();
      var sizeText = typeof fileSize === 'number' ? formatFileSize(fileSize) : '';
      var metaText = ext ? (ext + (sizeText ? ' · ' + sizeText : '')) : (sizeText || 'Document');
      attachment = '<a class="wb-media-doc-card" href="' + esc(attachmentUrl) + '" target="_blank" rel="noopener noreferrer" title="Click to open ' + escAttr(filename || 'document') + '">' +
        '<span class="wb-doc-icon">' +
          '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>' +
        '</span>' +
        '<span class="wb-doc-info">' +
          '<strong class="wb-doc-name">' + esc(filename || 'Document') + '</strong>' +
          '<small class="wb-doc-meta">' + esc(metaText) + '</small>' +
        '</span>' +
        '<span class="wb-doc-dl">' +
          '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>' +
        '</span>' +
      '</a>';
    }
    var genericAudioLabel = type === 'audio' && (
      !text ||
      text === filename ||
      text.toLowerCase() === 'voice message' ||
      /^voice-message\.[a-z0-9]+$/i.test(text)
    );
    var genericDocLabel = type === 'document' && (!text || text === filename);
    var textBody = (!genericAudioLabel && !genericDocLabel && text)
      ? '<span class="wb-caption">' + linkify(esc(text)) + '</span>'
      : '';
    var glyph = '';
    if (role === 'visitor') {
      var glyphChar = status === 'read' ? '✓✓' : (status === 'delivered' ? '✓✓' : (status === 'sent' ? '✓' : '⋯'));
      var statusClass = 'wb-status-glyph' + (status === 'read' ? ' wb-status-read' : '');
      glyph = '<span class="' + statusClass + '"' + (id ? ' data-wb-status-id="' + escAttr(id) + '"' : '') + '>' + glyphChar + '</span>';
    }
    row.innerHTML = av + '<div class="wb-bubble">' + attachment + textBody + glyph + '</div>';
    body.appendChild(row);
    scrollToBottom();
    return row;
  }

  function inferAudioMimeType(filename) {
    if (!filename) return '';
    var ext = String(filename).split('.').pop().toLowerCase();
    if (ext === 'ogg' || ext === 'oga') return 'audio/ogg';
    if (ext === 'mp3') return 'audio/mpeg';
    if (ext === 'm4a' || ext === 'aac') return 'audio/mp4';
    if (ext === 'wav') return 'audio/wav';
    if (ext === 'webm') return 'audio/webm';
    return '';
  }

  function linkify(html) {
    // Escaped HTML passed in: safe to regex-replace URLs into anchors.
    var urlRe = /(https?:\/\/[^\s<]+[^<.,:;"')\]\s])/g;
    return html.replace(urlRe, function (url) {
      return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + '</a>';
    });
  }

  function applyConfigUpdates(config) {
    if (!config) return;
    if (config.title !== undefined) {
      var t = root.querySelector('.wb-title');
      if (t) t.textContent = config.title;
    }
    if (config.avatar_url !== undefined) {
      var av = root.querySelector('.wb-head-av');
      if (av) av.src = resolveLogoUrl(config.avatar_url);
    }
    if (config.primary_color && config.primary_color !== COLOR) {
      COLOR = config.primary_color;
      style.textContent = css();
    }
  }

  function applyHandover(state) {
    if (!state) return;
    if (state.requested) {
      handoffEl.innerHTML = '<span class="wb-handoff-dot wb-handoff-pulse"></span><span>Connecting you with a team member…</span>';
      handoffEl.style.display = 'flex';
    } else if (state.available && (state.visitor_message_count || 0) >= 2) {
      handoffEl.innerHTML = '<span>Need more help?</span><button class="wb-handoff-btn" type="button">Chat with human</button>';
      handoffEl.style.display = 'flex';
    } else {
      handoffEl.style.display = 'none';
      handoffEl.innerHTML = '';
    }
  }

  function applyHandoff(nextHandoff) {
    if (!nextHandoff) return;
    handoff = nextHandoff;
    if (handoffWatchdog) {
      clearTimeout(handoffWatchdog);
      handoffWatchdog = null;
    }

    if (!handoff.enabled || handoff.status === 'connected') {
      handoffEl.style.display = 'none';
      handoffEl.innerHTML = '';
      return;
    }

    if (handoff.status === 'queued') {
      handoffEl.innerHTML = '<span class="wb-handoff-dot wb-handoff-pulse"></span><span>Connecting you with a team member…</span>';
      handoffEl.style.display = 'flex';
      handoffWatchdog = setTimeout(function () {
        if (!open) return;
        ensureSession(null, true).catch(function () {});
      }, 5000);
      return;
    }

    if (handoff.eligible) {
      handoffEl.innerHTML = '<span>Need more help?</span><button class="wb-handoff-btn" type="button">Chat with human</button>';
      handoffEl.style.display = 'flex';
      return;
    }

    handoffEl.style.display = 'none';
    handoffEl.innerHTML = '';
  }

  function requestHumanAgent() {
    if (!token) return;
    handoffEl.innerHTML = '<span class="wb-handoff-dot wb-handoff-pulse"></span><span>Requesting team member…</span>';
    handoffEl.style.display = 'flex';
    post('/widget/v1/handover', { key: KEY }).then(function (data) {
      if (data && data.handoff) applyHandoff(data.handoff);
      else if (data && data.handover) applyHandover(data.handover);
    }).catch(function () {
      handoffEl.innerHTML = '<span>Could not request human agent.</span><button class="wb-handoff-btn" type="button">Retry</button>';
    });
  }

  function registerPushSubscription() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
      return Promise.resolve();
    }
    if (Notification.permission !== 'granted') return Promise.resolve();

    return navigator.serviceWorker.ready.then(function (reg) {
      return reg.pushManager.getSubscription().then(function (sub) {
        if (!sub) return;
        return post('/widget/v1/push-subscriptions', {
          key: KEY,
          token: JSON.stringify(sub)
        }).catch(function () {});
      });
    }).catch(function () {});
  }

  function scrollToBottom() {
    setTimeout(function () {
      body.scrollTop = body.scrollHeight;
    }, 20);
  }

  function unlockSound() {
    if (audioUnlocked) return;
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return;
      if (!audioCtx) audioCtx = new AC();
      if (audioCtx.state === 'suspended') {
        audioCtx.resume().then(function () { audioUnlocked = true; }).catch(function () {});
      } else {
        audioUnlocked = true;
      }
    } catch (e) {}
  }

  function playNotificationSound() {
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return;
      if (!audioCtx) audioCtx = new AC();
      if (audioCtx.state === 'suspended') {
        audioCtx.resume().catch(function () {});
        return;
      }
      var now = audioCtx.currentTime;
      var osc1 = audioCtx.createOscillator();
      var osc2 = audioCtx.createOscillator();
      var gain = audioCtx.createGain();

      osc1.type = 'sine';
      osc1.frequency.setValueAtTime(587.33, now); // D5
      osc1.frequency.setValueAtTime(880.00, now + 0.08); // A5

      osc2.type = 'triangle';
      osc2.frequency.setValueAtTime(1174.66, now); // D6
      osc2.frequency.setValueAtTime(1760.00, now + 0.08); // A6

      gain.gain.setValueAtTime(0.001, now);
      gain.gain.exponentialRampToValueAtTime(0.18, now + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.28);

      osc1.connect(gain);
      osc2.connect(gain);
      gain.connect(audioCtx.destination);

      osc1.start(now);
      osc2.start(now);
      osc1.stop(now + 0.3);
      osc2.stop(now + 0.3);
    } catch (e) {}
  }

  // ── Network helpers ────────────────────────────────────────────────────────
  function post(path, payload) {
    return fetch(API + path, {
      method: 'POST',
      headers: headers(),
      body: JSON.stringify(payload)
    }).then(handle);
  }
  function postForm(path, formData) {
    return fetch(API + path, {
      method: 'POST',
      headers: formHeaders(),
      body: formData
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

  function resolveLogoUrl(url) {
    if (!url || String(url).indexOf('cerqle-icon-white-bg') !== -1 || String(url).indexOf('wisperbot') !== -1) {
      return API + '/cerqle-logo-transparent.svg';
    }
    return url;
  }

  // ── Markup + styles ──────────────────────────────────────────────────────────
  function template() {
    var s = getSettings();
    var defaultName = escAttr((s.name || s.full_name || '').trim());
    var defaultEmail = escAttr((s.email || '').trim());
    var av = '<span class="wb-head-av-shell"><img class="wb-head-av" src="' + esc(resolveLogoUrl(CFG.avatar_url)) + '" alt=""></span>';
    var pcName = (CFG.prechat_fields || []).indexOf('name') !== -1
      ? '<input class="wb-pc-name" type="text" placeholder="Your name" value="' + defaultName + '" required>' : '';
    var pcEmail = (CFG.prechat_fields || []).indexOf('email') !== -1
      ? '<input class="wb-pc-email" type="email" placeholder="Email address" value="' + defaultEmail + '" required>' : '';
    var launcherIcon = '<img class="wb-launcher-logo" src="' + esc(resolveLogoUrl(CFG.launcher_logo_url)) + '" alt="">';
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
        '<div class="wb-agent-typing" role="status" aria-live="polite"></div>' +
        '<div class="wb-handoff" aria-live="polite"></div>' +
        '<div class="wb-prechat">' +
          '<p class="wb-pc-intro">Tell us who you are and we\'ll get right back to you.</p>' +
          '<form class="wb-prechat-form">' + pcName + pcEmail +
            '<button class="wb-pc-btn" type="submit">Start chat</button>' +
          '</form>' +
        '</div>' +
        '<form class="wb-inputbar">' +
          '<div class="wb-file-preview">' +
            '<img class="wb-file-preview-img" alt="Attachment preview">' +
            '<div class="wb-file-preview-doc">' +
              '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>' +
              '<div class="wb-file-preview-info">' +
                '<span class="wb-file-preview-name"></span>' +
                '<span class="wb-file-preview-size"></span>' +
              '</div>' +
            '</div>' +
            '<button class="wb-file-discard" type="button" aria-label="Discard attachment">&#x2715;</button>' +
          '</div>' +
          '<div class="wb-audio-preview">' +
            '<audio class="wb-audio-player" controls preload="metadata"></audio>' +
            '<button class="wb-audio-send" type="button">Send</button>' +
            '<button class="wb-audio-discard" type="button" aria-label="Discard voice message">&#x2715;</button>' +
          '</div>' +
          '<div class="wb-audio-status" aria-live="polite"></div>' +
          '<button class="wb-tool-btn wb-attach-btn" type="button" aria-label="Attach file or photo" title="Attach file or photo">' +
            '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 17.97 8.8l-8.58 8.57a2 2 0 0 1-2.83-2.83l7.87-7.87"/></svg>' +
          '</button>' +
          '<input class="wb-file-input" type="file" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.heic,.heif">' +
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
        '<span class="wb-launcher-online" aria-label="Team online" title="Team online"></span>' +
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
      '.wb-launcher{position:relative;width:43.2px;height:43.2px;border-radius:50%;border:none;cursor:pointer;color:#fff;background:' + COLOR + ';box-shadow:0 4px 16px rgba(0,0,0,.22);display:flex;align-items:center;justify-content:center;overflow:visible;transition:opacity .2s,transform .2s}',
      '.wb-launcher:hover{transform:scale(1.06)}.wb-launcher:active{transform:scale(.96)}',
      '.wb-launcher:before{content:"";position:absolute;inset:-4.8px;border-radius:50%;border:1.8px solid ' + COLOR + ';opacity:0;transform:scale(.82);pointer-events:none}.wb-has-unread:before{animation:wb-pulse 1.35s ease-out infinite}',
      '.wb-launcher-online{position:absolute;right:-1px;bottom:1px;width:10.8px;height:10.8px;border-radius:50%;background:#22c55e;border:2px solid #fff;z-index:3;box-shadow:0 1px 4px rgba(0,0,0,.18)}',
      '.wb-ic-close{display:none}',
      '.wb-launcher-default{display:flex;align-items:center;justify-content:center;width:100%;height:100%}.wb-launcher-logo{display:block;width:24px;height:24px;max-width:24px;max-height:24px;object-fit:contain}.wb-active .wb-launcher-default{display:none}.wb-active .wb-ic-close{display:block;width:19.2px;height:19.2px}',
      '.wb-launcher-invite{position:absolute;bottom:0;width:224px;max-width:calc(100vw - 82px);border:0;background:transparent;padding:0;cursor:pointer;text-align:left;opacity:0;pointer-events:none;transform:translateX(14px) scale(.92);transition:opacity .28s ease,transform .48s cubic-bezier(.18,1.18,.35,1)}',
      '.wb-right .wb-launcher-invite{right:53px;transform-origin:right center}.wb-left .wb-launcher-invite{left:53px;transform:translateX(-12px) scale(.96);transform-origin:left center}.wb-show-invite .wb-launcher-invite{opacity:1;pointer-events:auto;transform:translateX(0) scale(1)}',
      '.wb-invite-card{position:relative;display:block;width:100%;background:#fff;border-radius:11px;padding:11px 15px;box-shadow:0 5px 18px rgba(0,0,0,.16);color:#20242c}.wb-invite-card:after{content:"";position:absolute;top:50%;right:-8px;margin-top:-8px;border-width:8px 0 8px 9px;border-style:solid;border-color:transparent transparent transparent #fff}.wb-left .wb-invite-card:after{right:auto;left:-8px;border-width:8px 9px 8px 0;border-color:transparent #fff transparent transparent}.wb-invite-card strong{display:block;font-size:15px;line-height:1.2;font-weight:700}.wb-invite-card small{display:block;margin-top:3px;font-size:12px;line-height:1.3;color:#737984;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '.wb-badge{display:none;position:absolute;top:-3px;right:-3px;min-width:22px;height:22px;border-radius:999px;background:#ef4444;border:2px solid #fff;color:#fff;align-items:center;justify-content:center;font-size:11px;font-weight:800;line-height:1;box-shadow:0 3px 10px rgba(239,68,68,.42);z-index:2}',
      '.wb-panel{position:absolute;bottom:57px;' + (LEFT ? 'left:0' : 'right:0') + ';width:370px;max-width:calc(100vw - 40px);height:560px;max-height:calc(100vh - 103px);background:#fff;border-radius:18px;box-shadow:0 16px 50px rgba(0,0,0,.22);display:flex;flex-direction:column;overflow:hidden;opacity:0;transform:translateY(12px) scale(.98);pointer-events:none;transition:opacity .2s,transform .22s cubic-bezier(.34,1.4,.6,1);transform-origin:bottom ' + (LEFT ? 'left' : 'right') + '}',
      '.wb-panel.wb-drop-active{outline:2px dashed ' + COLOR + ';outline-offset:-4px;background:#faf5ff}',
      '.wb-open .wb-panel{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}',
      '.wb-header{background:' + COLOR + ';color:#fff;padding:10px 12px;display:flex;align-items:center;gap:8px}',
      '.wb-head-av-shell{width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.18);border:1.5px solid rgba(255,255,255,.62);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0}',
      '.wb-head-av{width:22px;height:22px;object-fit:contain;flex-shrink:0}',
      '.wb-av-shell{width:28px;height:28px;border-radius:50%;background:' + COLOR + ';border:1px solid rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0}',
      '.wb-av{width:18px;height:18px;object-fit:contain;flex-shrink:0}',
      '.wb-av-ini{display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;background:rgba(255,255,255,.25);color:#fff}',
      '.wb-head-info{flex:1;min-width:0}',
      '.wb-title{font-weight:700;font-size:15px;line-height:1.3}',
      '.wb-status{font-size:11px;opacity:.96;display:flex;align-items:center;gap:5px;margin-top:1px;min-width:0}.wb-status-label{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '.wb-team-stack{display:flex;align-items:center;margin-right:1px}.wb-team-avatar,.wb-team-more{width:18px;height:18px;margin-left:-5px;border-radius:50%;border:1.5px solid rgba(255,255,255,.9);background:rgba(255,255,255,.24);display:flex;align-items:center;justify-content:center;overflow:hidden;color:#fff;font-size:7px;font-weight:800}.wb-team-avatar:first-child{margin-left:0}.wb-team-avatar img{width:100%;height:100%;object-fit:cover}.wb-team-more{background:rgba(17,24,39,.4);font-size:7px}',
      '.wb-dot{width:8px;height:8px;border-radius:50%;background:#4ade80;display:inline-block}',
      '.wb-dot-off{background:#d1d5db}',
      '.wb-close{background:transparent;border:none;color:#fff;font-size:16px;cursor:pointer;opacity:.85;padding:4px;line-height:1}',
      '.wb-close:hover{opacity:1}',
      '.wb-body{flex:1;min-height:0;overflow-x:hidden;overflow-y:scroll;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;touch-action:pan-y;padding:16px;background:#f7f8fa;display:flex;flex-direction:column;gap:10px;scrollbar-width:thin}',
      '.wb-row{display:flex;align-items:flex-end;gap:8px;max-width:85%}',
      '.wb-row.wb-pending{opacity:.65}',
      '.wb-row.wb-failed{cursor:pointer;opacity:.8}',
      '.wb-row.wb-failed .wb-bubble{outline:1px solid #ef4444}',
      '.wb-in{align-self:flex-start}.wb-out{align-self:flex-end;flex-direction:row-reverse}',
      '.wb-row .wb-av{width:14px;height:14px;font-size:9px}',
      '.wb-bubble{padding:9px 13px;border-radius:16px;font-size:14px;line-height:1.45;word-wrap:break-word;white-space:normal}',
      '.wb-in .wb-bubble{background:#fff;color:#1f2430;border:1px solid #eceef2;border-bottom-left-radius:5px}',
      '.wb-out .wb-bubble{background:' + COLOR + ';color:#fff;border-bottom-right-radius:5px}',
      '.wb-status-glyph{font-size:10px;margin-left:5px;opacity:.75;display:inline-block;vertical-align:bottom;letter-spacing:-1px}',
      '.wb-status-glyph.wb-status-read{opacity:1;color:#67e8f9}',
      '.wb-media-image{display:block;max-width:100%;max-height:240px;border-radius:10px;object-fit:cover;margin-bottom:6px}.wb-media-audio{display:block;width:220px;max-width:100%;height:38px;margin-bottom:6px}.wb-caption:empty{display:none}',
      '.wb-media-doc-card{display:flex;align-items:center;gap:9px;padding:8px 11px;border-radius:12px;text-decoration:none;transition:background .15s;max-width:100%}',
      '.wb-in .wb-media-doc-card{background:#f1f5f9;color:#0f172a}',
      '.wb-out .wb-media-doc-card{background:rgba(255,255,255,.2);color:#fff}',
      '.wb-doc-icon{display:flex;align-items:center;justify-content:center;flex-shrink:0;opacity:.85}',
      '.wb-doc-info{display:flex;flex-direction:column;min-width:0;flex:1}',
      '.wb-doc-name{font-size:13px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block}',
      '.wb-doc-meta{font-size:11px;opacity:.75;display:block;margin-top:1px}',
      '.wb-doc-dl{display:flex;align-items:center;justify-content:center;padding:4px;border-radius:50%;opacity:.85;flex-shrink:0}',
      '.wb-caption a{color:inherit;font-weight:650;text-decoration:underline;text-underline-offset:2px;word-break:break-word}',
      '.wb-agent-typing{display:none;align-items:center;gap:7px;min-height:30px;padding:5px 16px;border-top:1px solid #f0f1f4;background:#f7f8fa;color:#737984;font-size:11px;font-weight:600}',
      '.wb-typing-dots{display:inline-flex;align-items:center;gap:3px}.wb-typing-dots i{display:block;width:5px;height:5px;border-radius:50%;background:' + COLOR + ';animation:wb-typing 1.05s ease-in-out infinite}.wb-typing-dots i:nth-child(2){animation-delay:.14s}.wb-typing-dots i:nth-child(3){animation-delay:.28s}',
      '.wb-handoff{display:none;align-items:center;justify-content:center;gap:6px;min-height:38px;padding:8px 12px;border-top:1px solid #eceef2;background:#fff;color:#667085;font-size:12px}.wb-handoff strong{color:#1f2937}.wb-handoff-btn{border:1px solid ' + COLOR + ';border-radius:999px;background:#fff;color:' + COLOR + ';padding:5px 10px;font-size:12px;font-weight:700;cursor:pointer;transition:background .15s,color .15s}.wb-handoff-btn:hover{background:' + COLOR + ';color:#fff}.wb-handoff-dot{width:8px;height:8px;border-radius:50%;background:#22c55e;flex-shrink:0}.wb-handoff-pulse{background:#f59e0b;animation:wb-handoff-pulse 1s ease-in-out infinite}',
      '.wb-prechat{display:none;padding:18px;background:#fff}',
      '.wb-pc-intro{font-size:13px;color:#6b7280;margin-bottom:12px}',
      '.wb-prechat-form{display:flex;flex-direction:column;gap:10px}',
      '.wb-prechat-form input{border:1px solid #dfe2e8;border-radius:10px;padding:11px 13px;font-size:14px;outline:none}',
      '.wb-prechat-form input:focus{border-color:' + COLOR + '}',
      '.wb-pc-btn{background:' + COLOR + ';color:#fff;border:none;border-radius:10px;padding:11px;font-size:14px;font-weight:600;cursor:pointer}',
      '.wb-inputbar{display:flex;align-items:center;gap:8px;padding:12px;border-top:1px solid #eceef2;background:#fff;position:relative;flex-wrap:wrap}',
      '.wb-file-preview{display:none;align-items:center;gap:8px;width:100%;padding:8px 9px;border:1px solid #eceef2;border-radius:12px;background:#f8fafc}',
      '.wb-file-preview-img{width:48px;height:48px;border-radius:10px;object-fit:cover;display:none}',
      '.wb-file-preview-doc{display:none;align-items:center;gap:8px;flex:1;min-width:0}',
      '.wb-file-preview-doc svg{flex-shrink:0;color:#64748b}',
      '.wb-file-preview-info{display:flex;flex-direction:column;min-width:0;flex:1}',
      '.wb-file-preview-name{font-size:13px;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '.wb-file-preview-size{font-size:11px;color:#64748b;margin-top:1px}',
      '.wb-file-discard{width:30px;height:30px;border:none;border-radius:999px;background:#fff;color:#8b93a1;cursor:pointer;border:1px solid #e5e7eb;display:flex;align-items:center;justify-content:center;margin-left:auto;flex-shrink:0}',
      '.wb-file-input{display:none}',
      '.wb-audio-preview{display:none;align-items:center;gap:8px;width:100%;padding:8px 9px;border:1px solid #eceef2;border-radius:12px;background:#f8fafc}.wb-audio-player{flex:1;min-width:160px;height:34px}.wb-audio-send{border:none;border-radius:999px;background:' + COLOR + ';color:#fff;padding:7px 12px;font-size:12px;font-weight:700;cursor:pointer}.wb-audio-send:disabled{opacity:.6;cursor:wait}.wb-audio-discard{width:30px;height:30px;border:none;border-radius:999px;background:#fff;color:#8b93a1;cursor:pointer;border:1px solid #e5e7eb}.wb-audio-status{display:none;width:100%;font-size:11px;color:#6b7280;padding:0 4px 2px}.wb-mic{width:34px;height:34px;border-radius:50%;border:none;cursor:pointer;background:#f1f3f6;color:#687386;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .15s,color .15s,transform .15s}.wb-mic:hover{background:#e7eaf0;color:#303744}.wb-mic.wb-recording{background:#ef4444;color:#fff;animation:wb-record 1s ease-in-out infinite}',
      '.wb-tool-btn{width:34px;height:34px;border-radius:50%;border:none;cursor:pointer;background:#f1f3f6;color:#687386;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .15s,color .15s}.wb-tool-btn:hover{background:#e7eaf0;color:#303744}',
      '.wb-input{flex:1;border:none;outline:none;font-size:14px;padding:8px 4px;background:transparent}',
      '.wb-send{width:38px;height:38px;border-radius:50%;border:none;cursor:pointer;background:' + COLOR + ';color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:opacity .15s;pointer-events:auto}',
      '.wb-send:disabled{opacity:.5;cursor:not-allowed}',
      '.wb-send:hover:not(:disabled){opacity:.88}',
      '.wb-send svg{pointer-events:none}',
      '.wb-brand{text-align:center;font-size:11px;color:#9aa1ad;padding:7px;background:#fff;border-top:1px solid #f1f2f4}',
      '.wb-brand b{color:#6b7280}',
      '@keyframes wb-pulse{0%{opacity:.48;transform:scale(.86)}70%{opacity:0;transform:scale(1.28)}100%{opacity:0;transform:scale(1.28)}}',
      '@keyframes wb-record{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}',
      '@keyframes wb-handoff-pulse{0%,100%{opacity:.45;transform:scale(.86)}50%{opacity:1;transform:scale(1.08)}}',
      '@keyframes wb-typing{0%,60%,100%{opacity:.35;transform:translateY(0)}30%{opacity:1;transform:translateY(-2px)}}',
      '@media(max-width:600px){.wb-wrap{bottom:max(12px,env(safe-area-inset-bottom))}.wb-right{right:12px}.wb-left{left:12px}.wb-open .wb-launcher{display:none}.wb-panel{position:fixed;left:8px;right:8px;top:max(8px,env(safe-area-inset-top));bottom:84px;width:auto;max-width:none;height:auto;max-height:none;border-radius:16px}.wb-header{padding:9px 11px}.wb-body{padding:12px}.wb-inputbar{padding:9px;gap:6px}.wb-brand{padding-bottom:max(7px,env(safe-area-inset-bottom))}}'
    ].join('');
  }
})();

/**
 * Abu Anas Meetings Standalone Room Controller
 */
(function (global) {
  'use strict';

  const cfg = global.AAM_ROOM_CONFIG;
  if (!cfg) return;

  function emitEmbed(event, extra) {
    if (cfg.isEmbed && global.parent && global.parent !== global) {
      global.parent.postMessage(Object.assign({ source: 'abuanas-meetings', event: event, code: cfg.roomCode }, extra || {}), '*');
    }
  }

  let roomState = { participants: [], self: { role: cfg.isHost ? 'host' : 'student', canSpeak: cfg.isHost, handRaised: false } };
  let hostMicMuted = false;
  let studentMicMuted = true;
  let allowAllMic = false;
  let recPaused = false;
  let camOn = cfg.mediaMode === 'video';
  let screenOn = false;
  let elapsedSeconds = Number(cfg.initialElapsed || 0);
  let lastMsgId = 0;
  let ending = false;

  const audio = new global.VoxAudioEngine({
    onPacket: (buf) => transport.sendBinary(buf),
    onSpeakingIdle: () => transport.sendJson({ type: 'speaker_idle' }),
    onLocalSpeaking: (spk) => {
      if (cfg.isHost) {
        document.getElementById('hostAuraHalo')?.classList.toggle('speaking', spk);
      }
    },
    onMp3Chunk: async (blob) => {
      const fd = new FormData();
      fd.append('action', 'append');
      fd.append('code', cfg.roomCode);
      fd.append('csrf_token', cfg.csrfToken);
      fd.append('chunk', blob, 'chunk.mp3');
      await fetch(`${cfg.siteUrl}/api/recording_upload.php`, { method: 'POST', body: fd });
    },
  });

  const whiteboard = new global.VoxWhiteboard({
    canvas: document.getElementById('wbCanvas'),
    slideImg: document.getElementById('wbSlideImg'),
    laserEl: document.getElementById('wbLaserDot'),
    canDraw: cfg.isHost,
    onStroke: (stroke) => transport.sendJson({ type: 'wb_stroke', stroke }),
    onCursor: (x, y, active) => transport.sendJson({ type: 'wb_cursor', x, y, active }),
    onClear: () => transport.sendJson({ type: 'wb_clear' }),
  });

  const transport = new global.RoomTransportAdapter({
    isHost: cfg.isHost,
    userName: cfg.userName,
    csrfToken: cfg.csrfToken,
    opusSupported: audio.opusSupported,
    videoGridEl: document.getElementById('videoGridWrap'),
    onAudioPacket: (buf) => audio.decodePacket(buf),
    onFallbackNotice: (txt) => {
      if (global.Swal) Swal.fire({ toast: true, position: 'top-end', icon: 'info', title: txt, timer: 4500, showConfirmButton: false });
    },
    onMessage: handleWsMessage,
  });

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function handleWsMessage(msg) {
    if (msg.type === 'welcome') {
      if (msg.self) {
        roomState.self = msg.self;
        allowAllMic = !!msg.allowAllMic;
        updateControlsUI();
      }
      if (msg.whiteboard) {
        if (cfg.isHost && cfg.initialWhiteboard && !msg.whiteboard.enabled) {
          setWhiteboardVisible(true);
          transport.sendJson({ type: 'wb_toggle', enabled: true });
        } else {
          setWhiteboardVisible(!!msg.whiteboard.enabled);
        }
        whiteboard.syncState(msg.whiteboard);
      }
    } else if (msg.type === 'room_state') {
      applyRoomState(msg);
    } else if (msg.type === 'speaking') {
      const p = roomState.participants.find((x) => x.userId === msg.userId);
      if (p) p.isSpeaking = !!msg.speaking;
      if (p && p.role === 'host') document.getElementById('hostAuraHalo')?.classList.toggle('speaking', !!msg.speaking);
      renderSpeakersStrip();
    } else if (msg.type === 'wb_state') {
      setWhiteboardVisible(!!msg.enabled);
    } else if (msg.type === 'wb_stroke') {
      whiteboard.addRemoteStroke(msg.stroke);
    } else if (msg.type === 'wb_cursor') {
      whiteboard.showRemoteCursor(msg.x, msg.y, msg.active);
    } else if (msg.type === 'wb_clear') {
      whiteboard.clearLocal();
    } else if (msg.type === 'wb_slide') {
      whiteboard.setSlides(msg.slides, msg.slideIndex);
    } else if (msg.type === 'chat_notify' || msg.type === 'poll_notify' || msg.type === 'pin_notify') {
      pollRoomState();
    } else if (msg.type === 'permission_granted' && !cfg.isHost) {
      roomState.self.canSpeak = true;
      roomState.self.handRaised = false;
      studentMicMuted = false;
      updateControlsUI();
      audio.startMic(false).catch(() => {});
    } else if (msg.type === 'permission_revoked' && !cfg.isHost) {
      roomState.self.canSpeak = false;
      roomState.self.handRaised = false;
      studentMicMuted = true;
      audio.stopMic();
      updateControlsUI();
    } else if (msg.type === 'hand_dismissed' && !cfg.isHost) {
      roomState.self.handRaised = false;
      updateControlsUI();
    } else if (msg.type === 'allow_all_mic_changed' && !cfg.isHost) {
      allowAllMic = !!msg.enabled;
      roomState.self.canSpeak = allowAllMic;
      if (!allowAllMic) {
        studentMicMuted = true;
        audio.stopMic();
      }
      updateControlsUI();
    } else if (msg.type === 'session_ended' && !cfg.isHost && !ending) {
      ending = true;
      transport.disconnect();
      audio.stopMic();
      Swal.fire({ icon: 'info', title: 'انتهى الاجتماع', text: 'تم إنهاء الاجتماع من قِبل المضيف.', timer: 2500, showConfirmButton: false })
        .then(() => {
          emitEmbed('ended');
          if (cfg.isEmbed) {
            document.body.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100dvh;font-family:inherit;"><h3>تم إنهاء الاجتماع</h3></div>';
          } else {
            window.location.href = cfg.siteUrl + '/index.php';
          }
        });
    }
  }

  function applyRoomState(msg) {
    roomState.participants = Array.isArray(msg.participants) ? msg.participants : [];
    allowAllMic = !!msg.allowAllMic;
    if (msg.recommendedBitrate) audio.setBitrate(msg.recommendedBitrate);
    const me = roomState.participants.find((p) => p.userId === cfg.wsUserId);
    if (me) {
      roomState.self = me;
      whiteboard.setCanDraw(cfg.isHost || !!me.canSpeak);
    }
    const cnt = Number(msg.count || roomState.participants.length || 1);
    const c1 = document.getElementById('meetingOnlineCount');
    const c2 = document.getElementById('rosterOnlineBadge');
    if (c1) c1.textContent = String(cnt);
    if (c2) c2.textContent = String(cnt);
    emitEmbed('count', { count: cnt });
    renderSpeakersStrip();
    renderParticipantsList();
    renderRaisedHands();
    updateControlsUI();
  }

  function renderSpeakersStrip() {
    const box = document.getElementById('permittedSpeakersContainer');
    if (!box) return;
    const spks = roomState.participants.filter((p) => p.role !== 'host' && p.canSpeak);
    box.innerHTML = spks.map((p) => `
      <div class="vs-speaker-card">
        <div class="vs-speaker-av">${esc(p.name.charAt(0))}</div>
        <div class="vs-member-name" style="width:100%;">${esc(p.name)}</div>
        <div class="vs-member-sub" style="color:var(--vs-emerald);">${p.isSpeaking ? 'يتحدث الآن' : 'مداخلة مفعّلة'}</div>
        ${cfg.isHost ? `<button type="button" onclick="vsRevokeSpeaker('${esc(p.userId)}')" class="vs-act-btn mute" style="width:100%;margin-top:0.2rem;">كتم / إنهاء</button>` : ''}
      </div>
    `).join('');
  }

  function renderRaisedHands() {
    const box = document.getElementById('raisedHandsQueueBox');
    const list = document.getElementById('raisedHandsList');
    const cnt = document.getElementById('raisedHandsCount');
    const badge = document.getElementById('headerHandBadge');
    const hCnt = document.getElementById('headerHandCount');
    if (!box || !list) return;
    const hands = roomState.participants.filter((p) => p.role !== 'host' && !p.canSpeak && p.handRaised);
    if (cnt) cnt.textContent = String(hands.length);
    if (hCnt) hCnt.textContent = String(hands.length);
    if (!cfg.isHost || hands.length === 0) {
      box.classList.add('hidden');
      if (badge) badge.classList.add('hidden');
      list.innerHTML = '';
      return;
    }
    box.classList.remove('hidden');
    if (badge) badge.classList.remove('hidden');
    list.innerHTML = hands.map((h) => `
      <div class="vs-hand-row">
        <div class="vs-member-info">
          <div class="vs-av-sm raised">${esc(h.name.charAt(0))}</div>
          <div>
            <p class="vs-member-name">${esc(h.name)}</p>
            <p class="vs-member-sub" style="color:#B45309;">يرفع يده لطلب المداخلة</p>
          </div>
        </div>
        <div style="display:flex;gap:0.4rem;">
          <button type="button" onclick="vsApproveSpeaker('${esc(h.userId)}')" class="vs-act-btn allow">فتح المايك</button>
          <button type="button" onclick="vsDismissHand('${esc(h.userId)}')" class="vs-act-btn neutral">إنزال</button>
        </div>
      </div>
    `).join('');
  }

  global.renderParticipantsList = function () {
    const grid = document.getElementById('participantsGrid');
    if (!grid) return;
    const q = (document.getElementById('participantSearchInput')?.value || '').trim().toLowerCase();
    const rows = roomState.participants.filter((p) => !q || p.name.toLowerCase().includes(q));
    if (!rows.length) {
      grid.innerHTML = '<div style="padding:1rem;text-align:center;color:var(--vs-muted);font-size:0.84rem;">لا توجد نتائج مطابقة.</div>';
      return;
    }
    grid.innerHTML = rows.map((p) => {
      const avCls = p.role === 'host' ? 'is-host' : (p.canSpeak ? 'speaking' : (p.handRaised ? 'raised' : ''));
      const sub = p.role === 'host' ? 'المضيف الرئيسي' : (p.canSpeak ? 'مسموح له بالحديث' : (p.handRaised ? '✋ يطلب المداخلة' : 'مستمع'));
      const btn = (cfg.isHost && p.role !== 'host')
        ? (p.canSpeak
          ? `<button type="button" onclick="vsRevokeSpeaker('${esc(p.userId)}')" class="vs-act-btn mute">كتم</button>`
          : `<button type="button" onclick="vsApproveSpeaker('${esc(p.userId)}')" class="vs-act-btn allow">فتح المايك</button>`)
        : '';
      return `
        <div class="vs-member-row">
          <div class="vs-member-info">
            <div class="vs-av-sm ${avCls}">${esc(p.name.charAt(0))}</div>
            <div style="min-width:0;">
              <p class="vs-member-name">${esc(p.name)}</p>
              <p class="vs-member-sub">${sub}</p>
            </div>
          </div>
          ${btn}
        </div>
      `;
    }).join('');
  };

  function updateControlsUI() {
    if (cfg.isHost) {
      const mb = document.getElementById('hostMuteBtn');
      const mi = document.getElementById('hostMuteIcon');
      const ml = document.getElementById('hostMuteText');
      const hb = document.getElementById('hostMicBadge');
      if (mb && mi && ml) {
        mb.classList.toggle('gm-mic-on', !hostMicMuted);
        mb.classList.toggle('gm-muted', hostMicMuted);
        mi.className = hostMicMuted ? 'fas fa-microphone-slash' : 'fas fa-microphone';
        ml.textContent = hostMicMuted ? 'تشغيل المايك' : 'كتم المايك';
      }
      if (hb) {
        hb.classList.toggle('is-muted', hostMicMuted);
        hb.innerHTML = `<i class="fas ${hostMicMuted ? 'fa-microphone-slash' : 'fa-microphone'}"></i>`;
      }
      const ab = document.getElementById('hostAllowAllBtn');
      if (ab) ab.classList.toggle('gm-amber', allowAllMic);
    } else {
      const rhBtn = document.getElementById('studentRaiseHandBtn');
      const rhTxt = document.getElementById('studentRaiseHandText');
      if (rhBtn && rhTxt) {
        const raised = !!roomState.self.handRaised;
        rhBtn.classList.toggle('gm-amber', raised);
        rhTxt.textContent = raised ? 'إنزال اليد' : 'رفع اليد';
      }
      const smBtn = document.getElementById('studentMicBtn');
      const smTxt = document.getElementById('studentMicText');
      if (smBtn && smTxt) {
        const canS = !!roomState.self.canSpeak || allowAllMic;
        smBtn.classList.toggle('hidden', !canS);
        smBtn.classList.toggle('gm-mic-on', !studentMicMuted);
        smBtn.classList.toggle('gm-muted', studentMicMuted);
        smTxt.textContent = studentMicMuted ? 'تشغيل المايك' : 'المايك مفتوح';
      }
    }
  }

  function setWhiteboardVisible(vis) {
    const p = document.getElementById('whiteboardPanel');
    const b = document.getElementById('wbToggleBtn');
    if (p) p.classList.toggle('hidden', !vis);
    if (b) b.classList.toggle('is-active', vis);
    if (vis) setTimeout(() => whiteboard.resize(), 60);
  }

  async function postRoomApi(payload) {
    return fetch(`${cfg.siteUrl}/api/room_state.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrfToken },
      body: JSON.stringify(Object.assign({ code: cfg.roomCode, csrf_token: cfg.csrfToken }, payload)),
    }).then((r) => r.json());
  }

  async function pollRoomState() {
    try {
      const res = await fetch(`${cfg.siteUrl}/api/room_state.php?action=poll_state&code=${encodeURIComponent(cfg.roomCode)}&last_msg_id=${lastMsgId}`, { cache: 'no-store' });
      const d = await res.json();
      if (!d || !d.success) return;
      if (d.room_title) {
        const h = document.getElementById('roomTitleHeading');
        if (h) h.textContent = d.room_title;
      }
      if (Array.isArray(d.messages) && d.messages.length) {
        const chatBox = document.getElementById('chatMessagesBox');
        document.getElementById('chatEmptyNotice')?.remove();
        for (const m of d.messages) {
          lastMsgId = Math.max(lastMsgId, Number(m.id));
          if (!chatBox || document.getElementById('msg-' + m.id)) continue;
          const cls = m.sender_role === 'host' ? 'host' : (Number(m.is_question) ? 'q' : '');
          const pinBtn = cfg.isHost ? `<button type="button" onclick="vsPinMessage(${m.id})" style="border:none;background:transparent;color:var(--vs-muted);cursor:pointer;font-size:0.75rem;" title="تثبيت"><i class="fas fa-thumbtack"></i></button>` : '';
          chatBox.insertAdjacentHTML('beforeend', `
            <div class="vs-msg-card ${cls}" id="msg-${m.id}">
              <div style="display:flex;justify-content:space-between;align-items:center;font-size:0.76rem;font-weight:800;margin-bottom:0.2rem;">
                <span>${esc(m.sender_name)} ${Number(m.is_question) ? '<span class="vs-badge vs-badge-amber">سؤال</span>' : ''}</span>
                ${pinBtn}
              </div>
              <div style="font-size:0.84rem;">${esc(m.body)}</div>
            </div>
          `);
        }
        if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;
      }
      // Pinned banner
      const pb = document.getElementById('pinnedBanner');
      if (pb) {
        if (d.pinned && d.pinned.body) {
          pb.classList.remove('hidden');
          document.getElementById('pinnedSender').textContent = d.pinned.sender_name;
          document.getElementById('pinnedBody').textContent = d.pinned.body;
        } else {
          pb.classList.add('hidden');
        }
      }
      // Polls
      renderPolls(d.polls || []);
      if (Array.isArray(d.slides) && d.slides.length && whiteboard.slides.length === 0) {
        whiteboard.setSlides(d.slides, 0);
      }
    } catch {}
  }

  function renderPolls(polls) {
    const wrap = document.getElementById('pollsListBox');
    if (!wrap) return;
    if (!polls.length) {
      wrap.innerHTML = '<div style="text-align:center;color:var(--vs-muted);font-size:0.82rem;padding:1rem;">لا توجد استطلاعات حالياً.</div>';
      return;
    }
    wrap.innerHTML = polls.map((p) => `
      <div class="vs-msg-card" style="background:var(--vs-surface);">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:0.4rem;margin-bottom:0.5rem;">
          <strong style="font-size:0.85rem;">${esc(p.question)}</strong>
          <span style="font-size:0.72rem;color:var(--vs-muted);">${p.total_votes} صوت</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:0.35rem;">
          ${p.options.map((o) => `
            <button type="button" onclick="vsVotePoll(${p.id}, ${o.index})" ${p.is_closed ? 'disabled' : ''}
              style="position:relative;overflow:hidden;border:1px solid ${p.my_vote === o.index ? 'var(--vs-accent)' : 'var(--vs-border)'};background:var(--vs-subtle);border-radius:9px;padding:0.42rem 0.65rem;cursor:pointer;display:flex;justify-content:space-between;font-size:0.8rem;font-weight:700;color:var(--vs-text);">
              <span style="position:absolute;inset:0 auto 0 0;width:${o.pct}%;background:var(--vs-accent-soft);pointer-events:none;"></span>
              <span style="position:relative;">${esc(o.label)}</span>
              <span style="position:relative;" class="mono">${o.pct}%</span>
            </button>
          `).join('')}
        </div>
      </div>
    `).join('');
  }

  // Global UI actions
  global.vsStartHostMic = async function () {
    await audio.startMic(true);
    hostMicMuted = false;
    document.getElementById('hostMicBanner')?.classList.add('hidden');
    updateControlsUI();
  };
  global.vsUnlockAudio = async function () {
    await audio.ensureContext();
    document.getElementById('studentAudioBanner')?.classList.add('hidden');
  };
  global.vsToggleHostMic = async function () {
    if (!audio.micStream) await audio.startMic(true);
    hostMicMuted = !hostMicMuted;
    audio.setMuted(hostMicMuted);
    updateControlsUI();
  };
  global.vsToggleRecPause = function () {
    recPaused = !recPaused;
    audio.mp3Paused = recPaused;
    const btn = document.getElementById('hostPauseRecBtn');
    const txt = document.getElementById('hostPauseRecText');
    if (btn) btn.classList.toggle('gm-amber', recPaused);
    if (txt) txt.textContent = recPaused ? 'استئناف التسجيل' : 'إيقاف مؤقت';
  };
  global.vsToggleAllowAllMic = function () {
    allowAllMic = !allowAllMic;
    transport.sendJson({ type: 'set_allow_all_mic', enabled: allowAllMic });
    updateControlsUI();
  };
  global.vsMuteAll = function () {
    allowAllMic = false;
    transport.sendJson({ type: 'mute_all' });
    updateControlsUI();
  };
  global.vsApproveSpeaker = (uid) => transport.sendJson({ type: 'approve_speaker', targetUserId: uid });
  global.vsRevokeSpeaker = (uid) => transport.sendJson({ type: 'revoke_speaker', targetUserId: uid });
  global.vsDismissHand = (uid) => transport.sendJson({ type: 'dismiss_hand', targetUserId: uid });
  global.vsDismissAllHands = () => {
    roomState.participants.filter((p) => p.handRaised).forEach((p) => transport.sendJson({ type: 'dismiss_hand', targetUserId: p.userId }));
  };
  global.vsToggleRaiseHand = () => {
    const next = !roomState.self.handRaised;
    roomState.self.handRaised = next;
    transport.sendJson({ type: next ? 'raise_hand' : 'lower_hand' });
    updateControlsUI();
  };
  global.vsToggleStudentMic = async () => {
    studentMicMuted = !studentMicMuted;
    if (!studentMicMuted) await audio.startMic(false);
    else audio.setMuted(true);
    updateControlsUI();
  };
  global.vsSetVolume = (v) => {
    audio.setVolume(v);
    const pct = Math.round(Number(v) * 100);
    const el = document.getElementById('volumePctTxt');
    if (el) el.textContent = pct + '%';
  };
  global.vsToggleWhiteboard = () => {
    const p = document.getElementById('whiteboardPanel');
    const next = p ? p.classList.contains('hidden') : true;
    setWhiteboardVisible(next);
    if (cfg.isHost) transport.sendJson({ type: 'wb_toggle', enabled: next });
  };
  global.vsWbTool = (t, btn) => {
    whiteboard.setTool(t);
    document.querySelectorAll('.vs-wb-tool-btn').forEach((b) => b.classList.remove('active'));
    btn?.classList.add('active');
  };
  global.vsWbColor = (c) => whiteboard.setColor(c);
  global.vsWbClear = () => { whiteboard.clearLocal(); transport.sendJson({ type: 'wb_clear' }); };
  global.vsUploadSlide = async (input) => {
    if (!input.files || !input.files[0]) return;
    const fd = new FormData();
    fd.append('action', 'upload_slide');
    fd.append('code', cfg.roomCode);
    fd.append('csrf_token', cfg.csrfToken);
    fd.append('slide', input.files[0]);
    const r = await fetch(`${cfg.siteUrl}/api/room_state.php`, { method: 'POST', body: fd }).then((x) => x.json());
    if (r.success) {
      whiteboard.setSlides(r.slides, r.slideIndex);
      transport.sendJson({ type: 'wb_slide', slides: r.slides, slideIndex: r.slideIndex });
    }
    input.value = '';
  };
  global.vsSlideStep = (delta) => {
    const next = Math.max(0, Math.min(Math.max(0, whiteboard.slides.length - 1), whiteboard.slideIndex + delta));
    whiteboard.setSlides(whiteboard.slides, next);
    transport.sendJson({ type: 'wb_slide', slides: whiteboard.slides, slideIndex: next });
  };
  global.vsFocusHands = () => document.getElementById('raisedHandsQueueBox')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  global.vsToggleChat = () => {
    const root = document.getElementById('voxRoomRoot');
    const body = document.getElementById('roomBodyWrap');
    const btn = document.getElementById('chatToggleBtn');
    if (window.innerWidth < 1024) {
      root?.classList.toggle('mobile-chat-open');
      btn?.classList.toggle('is-active', root?.classList.contains('mobile-chat-open'));
    } else {
      body?.classList.toggle('chat-collapsed');
      btn?.classList.toggle('is-active', !body?.classList.contains('chat-collapsed'));
    }
    setTimeout(() => whiteboard.resize(), 120);
  };
  global.vsSwitchSideTab = (tab) => {
    document.getElementById('sideChatPane')?.classList.toggle('hidden', tab !== 'chat');
    document.getElementById('sidePollsPane')?.classList.toggle('hidden', tab !== 'polls');
    document.getElementById('tabChatBtn')?.classList.toggle('active', tab === 'chat');
    document.getElementById('tabPollsBtn')?.classList.toggle('active', tab === 'polls');
  };
  global.vsSendChat = async (e) => {
    e.preventDefault();
    const inp = document.getElementById('chatInput');
    const qChk = document.getElementById('chatIsQuestion');
    const txt = (inp?.value || '').trim();
    if (!txt) return;
    inp.value = '';
    await postRoomApi({ action: 'send_message', body: txt, is_question: qChk?.checked ? 1 : 0 });
    if (qChk) qChk.checked = false;
    transport.sendJson({ type: 'chat_notify' });
    await pollRoomState();
  };
  global.vsPinMessage = async (id) => {
    await postRoomApi({ action: 'pin_message', message_id: id });
    transport.sendJson({ type: 'pin_notify' });
    await pollRoomState();
  };
  global.vsUnpinMessage = async () => {
    await postRoomApi({ action: 'unpin_message' });
    transport.sendJson({ type: 'pin_notify' });
    await pollRoomState();
  };
  global.vsCreatePoll = async (e) => {
    e.preventDefault();
    const q = document.getElementById('pollQInput')?.value.trim();
    const opts = (document.getElementById('pollOptsInput')?.value || '').split('\n').map((s) => s.trim()).filter(Boolean);
    const r = await postRoomApi({ action: 'create_poll', question: q, options: opts });
    if (r.success) {
      e.target.reset();
      transport.sendJson({ type: 'poll_notify' });
      await pollRoomState();
    }
  };
  global.vsVotePoll = async (pollId, optIdx) => {
    const r = await postRoomApi({ action: 'vote_poll', poll_id: pollId, option_index: optIdx });
    if (r.success) {
      renderPolls(r.polls || []);
      transport.sendJson({ type: 'poll_notify' });
    }
  };
  global.vsEditTitle = async () => {
    const cur = document.getElementById('roomTitleHeading')?.textContent || '';
    const r = await Swal.fire({ title: 'تعديل عنوان الغرفة', input: 'text', inputValue: cur, showCancelButton: true, confirmButtonText: 'حفظ', cancelButtonText: 'إلغاء' });
    if (r.isConfirmed && r.value) {
      await postRoomApi({ action: 'update_title', title: r.value });
      await pollRoomState();
    }
  };
  global.vsToggleShare = (ev) => {
    ev.stopPropagation();
    document.getElementById('shareMenuDropdown')?.classList.toggle('hidden');
  };
  document.addEventListener('click', () => document.getElementById('shareMenuDropdown')?.classList.add('hidden'));
  global.vsShare = async (kind) => {
    const u = cfg.shareUrl;
    if (kind === 'copy') {
      await navigator.clipboard.writeText(u);
      Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'تم نسخ الرابط', timer: 1800, showConfirmButton: false });
    } else if (kind === 'whatsapp') {
      window.open(`https://wa.me/?text=${encodeURIComponent(cfg.roomTitle + '\n' + u)}`, '_blank');
    } else if (kind === 'telegram') {
      window.open(`https://t.me/share/url?url=${encodeURIComponent(u)}&text=${encodeURIComponent(cfg.roomTitle)}`, '_blank');
    }
  };
  global.vsToggleCam = async () => {
    camOn = !camOn;
    await transport.toggleCamera(camOn);
    document.getElementById('hostCamBtn')?.classList.toggle('gm-active-blue', camOn);
  };
  global.vsToggleScreen = async () => {
    screenOn = !screenOn;
    await transport.toggleScreenShare(screenOn);
    document.getElementById('hostScreenBtn')?.classList.toggle('gm-active-blue', screenOn);
  };

  const videoRec = global.AbuAnasVideoRecorder ? new global.AbuAnasVideoRecorder({
    onChunk: async (blob, ext) => {
      const fd = new FormData();
      fd.append('action', 'append');
      fd.append('media_type', 'video');
      fd.append('ext', ext || 'webm');
      fd.append('code', cfg.roomCode);
      fd.append('csrf_token', cfg.csrfToken);
      fd.append('chunk', blob, 'chunk.' + (ext || 'webm'));
      await fetch(`${cfg.siteUrl}/api/recording_upload.php`, { method: 'POST', body: fd });
    },
    onUserStopped: () => {
      if (videoRec && videoRec.isRecording()) global.vsToggleVideoRec();
    },
  }) : null;

  async function finalizeVideoRecording() {
    if (!videoRec || !videoRec.isRecording()) return null;
    const stopRes = await videoRec.stop();
    document.getElementById('hostVideoRecBtn')?.classList.remove('gm-muted');
    document.getElementById('videoRecPill')?.classList.add('hidden');
    const lbl = document.getElementById('hostVideoRecText');
    if (lbl) lbl.textContent = 'تسجيل فيديو';

    const fd = new FormData();
    fd.append('action', 'finalize');
    fd.append('media_type', 'video');
    fd.append('ext', stopRes.ext || 'webm');
    fd.append('code', cfg.roomCode);
    fd.append('csrf_token', cfg.csrfToken);
    fd.append('duration_seconds', String(stopRes.duration || 1));
    const res = await fetch(`${cfg.siteUrl}/api/recording_upload.php`, { method: 'POST', body: fd }).then((r) => r.json()).catch(() => ({}));
    return Object.assign({}, res, { localBlob: stopRes.blob, ext: stopRes.ext });
  }

  global.vsToggleVideoRec = async () => {
    if (!videoRec || !videoRec.isSupported()) {
      Swal.fire({ icon: 'info', title: 'غير مدعوم', text: 'تسجيل الفيديو الكامل يتطلب متصفح سطح مكتب يدعم مشاركة التبويب.' });
      return;
    }
    if (!videoRec.isRecording()) {
      try {
        await videoRec.start();
        document.getElementById('hostVideoRecBtn')?.classList.add('gm-muted');
        document.getElementById('videoRecPill')?.classList.remove('hidden');
        const lbl = document.getElementById('hostVideoRecText');
        if (lbl) lbl.textContent = 'إيقاف الفيديو';
        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'بدأ تسجيل الفيديو للاجتماع', timer: 2500, showConfirmButton: false });
      } catch (err) {
        Swal.fire({ icon: 'info', title: 'لم يبدأ تسجيل الفيديو', text: err.message || 'تم إلغاء اختيار نافذة الاجتماع.' });
      }
    } else {
      const fin = await finalizeVideoRecording();
      if (fin && fin.saved) {
        Swal.fire({
          icon: 'success',
          title: 'تم حفظ تسجيل الفيديو!',
          text: 'يمكنك تشغيله وتحميله من لوحة اجتماعاتك الآن.',
          showCancelButton: true,
          confirmButtonText: 'تنزيل النسخة الآن',
          cancelButtonText: 'حسناً',
        }).then((ch) => {
          if (ch.isConfirmed && fin.localBlob) {
            videoRec.downloadLocal(fin.localBlob, `abuanas-${cfg.roomCode}.${fin.ext || 'webm'}`);
          }
        });
      }
    }
  };
  global.vsLeaveRoom = () => {
    ending = true;
    transport.disconnect();
    audio.stopMic();
    emitEmbed('left');
    if (cfg.isEmbed) {
      document.body.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100dvh;font-family:inherit;"><h3>تمت مغادرة الاجتماع</h3></div>';
      return;
    }
    window.location.href = cfg.siteUrl + '/index.php';
  };
  global.vsEndRoom = async () => {
    const ok = await Swal.fire({ title: 'إنهاء وحفظ الجلسة؟', text: 'سيتم إغلاق الغرفة وحفظ التسجيل الصوتي والفيديو إن وُجد.', icon: 'warning', showCancelButton: true, confirmButtonText: 'إنهاء وحفظ', cancelButtonText: 'تراجع', confirmButtonColor: '#E11D48' });
    if (!ok.isConfirmed) return;
    ending = true;
    document.getElementById('saveOverlay')?.classList.remove('hidden');
    transport.sendJson({ type: 'session_ended' });
    await finalizeVideoRecording().catch(() => {});
    audio.stopMic();
    await audio.flushMp3(true);
    const fd = new FormData();
    fd.append('action', 'finalize');
    fd.append('code', cfg.roomCode);
    fd.append('csrf_token', cfg.csrfToken);
    fd.append('duration_seconds', String(elapsedSeconds));
    await fetch(`${cfg.siteUrl}/api/recording_upload.php`, { method: 'POST', body: fd }).catch(() => {});
    if (cfg.mediaMode === 'video' && elapsedSeconds > 10) {
      await postRoomApi({ action: 'record_video_minutes', minutes: +(elapsedSeconds / 60).toFixed(2) }).catch(() => {});
    }
    await postRoomApi({ action: 'end_room' }).catch(() => {});
    transport.disconnect();
    window.location.href = cfg.siteUrl + '/dashboard.php';
  };

  // Boot sequence
  setInterval(() => {
    elapsedSeconds++;
    const h = String(Math.floor(elapsedSeconds / 3600)).padStart(2, '0');
    const m = String(Math.floor((elapsedSeconds % 3600) / 60)).padStart(2, '0');
    const s = String(elapsedSeconds % 60).padStart(2, '0');
    const t = document.getElementById('meetingElapsedTimer');
    if (t) t.textContent = `${h}:${m}:${s}`;
  }, 1000);

  (async function init() {
    try {
      try {
        const prev = JSON.parse(localStorage.getItem('aam_recent_rooms') || '[]');
        const next = [{ code: cfg.roomCode, title: cfg.roomTitle }].concat((Array.isArray(prev) ? prev : []).filter((x) => x && x.code !== cfg.roomCode)).slice(0, 6);
        localStorage.setItem('aam_recent_rooms', JSON.stringify(next));
      } catch {}
      const tokRes = await fetch(`${cfg.siteUrl}/api/room_token.php?code=${encodeURIComponent(cfg.roomCode)}`, { cache: 'no-store' }).then((r) => r.json());
      if (!tokRes || !tokRes.success) return;
      if (tokRes.fallbackReason) {
        Swal.fire({ toast: true, position: 'top-end', icon: 'info', title: tokRes.fallbackReason, timer: 5000, showConfirmButton: false });
      }
      await transport.connect(tokRes);
      emitEmbed('joined');
      if (cfg.isHost) {
        audio.startMic(true).then(() => {
          hostMicMuted = false;
          document.getElementById('hostMicBanner')?.classList.add('hidden');
          updateControlsUI();
        }).catch(() => {
          document.getElementById('hostMicBanner')?.classList.remove('hidden');
        });
      }
    } catch (e) { console.error(e); }
    await pollRoomState();
    setInterval(() => { if (!document.hidden && !ending) pollRoomState(); }, 6000);
  })();
})(window);

import { DurableObject } from 'cloudflare:workers';

const BITRATE_MAX = 64000;
const BITRATE_STEPS = [16000, 24000, 32000, 48000, 64000];
const MAX_WB_STROKES = 300;

function clampBitrate(bps) {
  const n = Number(bps);
  if (!Number.isFinite(n)) return 32000;
  if (n <= 18000) return 16000;
  if (n <= 26000) return 24000;
  if (n <= 38000) return 32000;
  if (n <= 54000) return 48000;
  return BITRATE_MAX;
}

function base64UrlToUint8(str) {
  const padded = str.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((str.length + 3) % 4);
  const bin = atob(padded);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}

async function verifyJwt(token, secret) {
  if (!secret || !token || typeof token !== 'string') return null;
  const parts = token.split('.');
  if (parts.length !== 3) return null;
  const [headerB64, payloadB64, sigB64] = parts;

  let header;
  try {
    header = JSON.parse(new TextDecoder().decode(base64UrlToUint8(headerB64)));
  } catch {
    return null;
  }
  if (!header || header.alg !== 'HS256') return null;

  const key = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['verify']
  );

  const ok = await crypto.subtle.verify(
    'HMAC',
    key,
    base64UrlToUint8(sigB64),
    new TextEncoder().encode(`${headerB64}.${payloadB64}`)
  );
  if (!ok) return null;

  let payload;
  try {
    payload = JSON.parse(new TextDecoder().decode(base64UrlToUint8(payloadB64)));
  } catch {
    return null;
  }

  const now = Math.floor(Date.now() / 1000);
  if (typeof payload.exp !== 'number' || now >= payload.exp) return null;
  if (typeof payload.nbf === 'number' && now < payload.nbf - 10) return null;

  const roomId = String(payload.roomId || '').trim();
  const userId = String(payload.userId || '').trim();
  const name = String(payload.name || '').trim().slice(0, 60) || 'مشارك';
  const role = payload.role === 'host' ? 'host' : 'student';
  const probe = !!payload.probe;
  if (!/^[a-zA-Z0-9_-]{2,64}$/.test(roomId) || !userId) return null;

  return { roomId, userId, name, role, probe };
}

function emptyUsageStats(nowMs = Date.now()) {
  const iso = new Date(nowMs).toISOString();
  return {
    monthKey: iso.slice(0, 7),
    dayKey: iso.slice(0, 10),
    monthIngressBytes: 0,
    monthEgressBytes: 0,
    monthPackets: 0,
    monthHttpRequests: 0,
    monthWsMessages: 0,
    monthRequests: 0,
    dayIngressBytes: 0,
    dayEgressBytes: 0,
    dayPackets: 0,
    dayHttpRequests: 0,
    dayWsMessages: 0,
    dayRequests: 0,
    totalIngressBytes: 0,
    totalEgressBytes: 0,
    totalPackets: 0,
    totalRequests: 0,
    updatedAt: nowMs,
  };
}

function rollUsageStats(st, nowMs = Date.now()) {
  const s = st && typeof st === 'object' ? { ...emptyUsageStats(nowMs), ...st } : emptyUsageStats(nowMs);
  const iso = new Date(nowMs).toISOString();
  const mKey = iso.slice(0, 7);
  const dKey = iso.slice(0, 10);
  if (s.monthKey !== mKey) {
    s.monthKey = mKey;
    s.monthIngressBytes = 0;
    s.monthEgressBytes = 0;
    s.monthPackets = 0;
    s.monthHttpRequests = 0;
    s.monthWsMessages = 0;
    s.monthRequests = 0;
  }
  if (s.dayKey !== dKey) {
    s.dayKey = dKey;
    s.dayIngressBytes = 0;
    s.dayEgressBytes = 0;
    s.dayPackets = 0;
    s.dayHttpRequests = 0;
    s.dayWsMessages = 0;
    s.dayRequests = 0;
  }
  return s;
}

export class VoiceRoom extends DurableObject {
  constructor(ctx, env) {
    super(ctx, env);
    this.roomId = '';
    this.bitrateMode = 'auto';
    this.targetBitrate = 32000;
    this.allowAllMic = false;
    this.stableCycles = 0;
    this.nextSpeakerId = 1;
    this.startedAt = Date.now();
    this.audioPacketsRelayed = 0;
    this.audioBytesRelayed = 0;
    this.audioIngressBytes = 0;
    this.audioEgressBytes = 0;
    this.audioEgressPackets = 0;
    this.droppedFrames = 0;
    this.totalWsConnects = 0;
    this.totalWsDisconnects = 0;
    this.wbEnabled = false;
    this.wbStrokes = [];
    this.wbSlides = [];
    this.wbSlideIndex = 0;
    this._unflushedIngress = 0;
    this._unflushedEgress = 0;
    this._unflushedPackets = 0;
    this._unflushedHttpReqs = 0;
    this._unflushedWsMsgs = 0;
    this._lastUsageFlushAt = Date.now();
    this._metaCache = new WeakMap();
    this._lastRoomStateAt = 0;
    this._roomStateTimer = null;
    this._pendingRoomState = false;
    this._lastBitrateCheckAt = 0;
    this.ctx.blockConcurrencyWhile(async () => {
      const saved = await this.ctx.storage.get(['roomId', 'bitrateMode', 'targetBitrate', 'nextSpeakerId', 'allowAllMic', 'wbEnabled']);
      if (saved.get('roomId')) this.roomId = saved.get('roomId');
      if (saved.get('bitrateMode')) this.bitrateMode = saved.get('bitrateMode');
      if (saved.get('targetBitrate')) this.targetBitrate = clampBitrate(saved.get('targetBitrate'));
      if (saved.get('nextSpeakerId')) this.nextSpeakerId = Number(saved.get('nextSpeakerId')) || 1;
      if (typeof saved.get('allowAllMic') === 'boolean') this.allowAllMic = saved.get('allowAllMic');
      if (typeof saved.get('wbEnabled') === 'boolean') this.wbEnabled = saved.get('wbEnabled');
    });
  }

  async flushUsageToGlobal(force = false) {
    const now = Date.now();
    const hasDelta = (this._unflushedIngress + this._unflushedEgress + this._unflushedPackets + this._unflushedHttpReqs + this._unflushedWsMsgs) > 0;
    if (!hasDelta) return;
    if (!force && (now - this._lastUsageFlushAt) < 60000) return;
    if (!this.env || !this.env.VOICE_ROOM || this.roomId === '__global_usage__') return;
    const payload = {
      ingressBytes: this._unflushedIngress,
      egressBytes: this._unflushedEgress,
      packets: this._unflushedPackets,
      httpRequests: this._unflushedHttpReqs,
      wsMessages: this._unflushedWsMsgs,
    };
    this._unflushedIngress = 0;
    this._unflushedEgress = 0;
    this._unflushedPackets = 0;
    this._unflushedHttpReqs = 0;
    this._unflushedWsMsgs = 0;
    this._lastUsageFlushAt = now;
    try {
      const gid = this.env.VOICE_ROOM.idFromName('__global_usage__');
      const gstub = this.env.VOICE_ROOM.get(gid);
      await gstub.fetch('https://internal/usage-add', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
    } catch {}
  }

  getMeta(ws) {
    let meta = this._metaCache.get(ws);
    if (meta === undefined) {
      meta = ws.deserializeAttachment() || null;
      if (meta) this._metaCache.set(ws, meta);
    }
    return meta;
  }

  saveMeta(ws, meta, persistToStorage = true) {
    if (!meta) {
      this._metaCache.delete(ws);
      if (persistToStorage) {
        try { ws.serializeAttachment(null); } catch {}
      }
      return;
    }
    this._metaCache.set(ws, meta);
    if (persistToStorage) {
      try { ws.serializeAttachment(meta); } catch {}
    }
  }

  getSocketsWithMeta() {
    const out = [];
    const sockets = this.ctx.getWebSockets();
    for (let i = 0, len = sockets.length; i < len; i++) {
      const ws = sockets[i];
      const meta = this.getMeta(ws);
      if (meta && meta.userId && !meta.isProbe) out.push({ ws, meta });
    }
    return out;
  }

  allocateSpeakerId(entries) {
    for (let tries = 0; tries < 65535; tries++) {
      const cand = this.nextSpeakerId;
      this.nextSpeakerId = this.nextSpeakerId >= 65534 ? 1 : this.nextSpeakerId + 1;
      if (!entries.some((e) => e.meta.speakerId === cand)) {
        this.ctx.storage.put('nextSpeakerId', this.nextSpeakerId).catch(() => {});
        return cand;
      }
    }
    return 1;
  }

  serializeRoomState(entries) {
    const participants = [];
    let hostPresent = false;
    let allOpus = entries.length > 0;
    for (const { meta } of entries) {
      if (meta.role === 'host') hostPresent = true;
      if (!meta.opusSupported) allOpus = false;
      participants.push({
        userId: meta.userId,
        speakerId: meta.speakerId,
        name: meta.name,
        role: meta.role,
        canSpeak: !!meta.canSpeak,
        handRaised: !!meta.handRaised,
        isSpeaking: !!meta.isSpeaking,
      });
    }
    participants.sort((a, b) => {
      if (a.role !== b.role) return a.role === 'host' ? -1 : 1;
      if (a.canSpeak !== b.canSpeak) return a.canSpeak ? -1 : 1;
      if (a.handRaised !== b.handRaised) return a.handRaised ? -1 : 1;
      return a.speakerId - b.speakerId;
    });
    return {
      type: 'room_state',
      roomId: this.roomId || 'lobby',
      hostPresent,
      allowAllMic: !!this.allowAllMic,
      codecMode: allOpus ? 'opus' : 'adpcm16',
      bitrateMode: this.bitrateMode || 'auto',
      recommendedBitrate: clampBitrate(this.targetBitrate),
      count: participants.length,
      participants,
    };
  }

  buildMetricsSnapshot() {
    const entries = this.getSocketsWithMeta();
    let hosts = 0, speakers = 0, listeners = 0, handRaised = 0, speaking = 0, opusCount = 0;
    const rtts = [];
    const clientList = [];
    for (const { meta } of entries) {
      if (meta.role === 'host') hosts++;
      else {
        listeners++;
        if (meta.canSpeak) speakers++;
      }
      if (meta.handRaised) handRaised++;
      if (meta.isSpeaking) speaking++;
      if (meta.opusSupported) opusCount++;
      const rtt = Number(meta.lastRtt || 0);
      if (rtt > 0) rtts.push(rtt);
      clientList.push({
        userId: meta.userId,
        name: meta.name,
        role: meta.role,
        canSpeak: !!meta.canSpeak,
        handRaised: !!meta.handRaised,
        isSpeaking: !!meta.isSpeaking,
        opusSupported: !!meta.opusSupported,
        lastRttMs: rtt,
      });
    }
    const avgRttMs = rtts.length ? Math.round(rtts.reduce((a, b) => a + b, 0) / rtts.length) : 0;
    const maxRttMs = rtts.length ? Math.max(...rtts) : 0;
    const minRttMs = rtts.length ? Math.min(...rtts) : 0;
    return {
      roomId: this.roomId || 'lobby',
      allowAllMic: !!this.allowAllMic,
      codecMode: (entries.length > 0 && opusCount === entries.length) ? 'opus' : 'adpcm16',
      bitrateMode: this.bitrateMode || 'auto',
      targetBitrate: clampBitrate(this.targetBitrate),
      uptimeSeconds: Math.max(0, Math.floor((Date.now() - this.startedAt) / 1000)),
      audioPacketsRelayed: this.audioPacketsRelayed,
      audioBytesRelayed: this.audioBytesRelayed,
      audioIngressBytes: this.audioIngressBytes,
      audioEgressBytes: this.audioEgressBytes,
      audioEgressPackets: this.audioEgressPackets,
      droppedFrames: this.droppedFrames,
      totalWsConnects: this.totalWsConnects,
      totalWsDisconnects: this.totalWsDisconnects,
      counts: {
        total: entries.length,
        hosts,
        speakers,
        listeners,
        handRaised,
        speaking,
        opusClients: opusCount,
      },
      avgRttMs,
      maxRttMs,
      minRttMs,
      participants: clientList,
    };
  }

  broadcastRoomState() {
    if (this._roomStateTimer) {
      clearTimeout(this._roomStateTimer);
      this._roomStateTimer = null;
    }
    this._pendingRoomState = false;
    this._lastRoomStateAt = Date.now();
    const entries = this.getSocketsWithMeta();
    if (!entries.length) return;
    const payload = JSON.stringify(this.serializeRoomState(entries));
    for (let i = 0, len = entries.length; i < len; i++) {
      try { entries[i].ws.send(payload); } catch {}
    }
  }

  scheduleRoomStateBroadcast(immediate = false) {
    if (immediate) {
      this.broadcastRoomState();
      return;
    }
    const now = Date.now();
    const elapsed = now - this._lastRoomStateAt;
    const minIntervalMs = 750;
    if (elapsed >= minIntervalMs && !this._roomStateTimer) {
      this.broadcastRoomState();
      return;
    }
    this._pendingRoomState = true;
    if (!this._roomStateTimer) {
      const waitMs = Math.max(80, minIntervalMs - elapsed);
      this._roomStateTimer = setTimeout(() => {
        this._roomStateTimer = null;
        if (this._pendingRoomState) {
          this.broadcastRoomState();
        }
      }, waitMs);
      this.ctx.storage.setAlarm(now + waitMs + 250).catch(() => {});
    }
  }

  broadcastJson(obj, excludeWs = null) {
    const payload = JSON.stringify(obj);
    const sockets = this.ctx.getWebSockets();
    for (let i = 0, len = sockets.length; i < len; i++) {
      const ws = sockets[i];
      if (excludeWs && ws === excludeWs) continue;
      const m = this.getMeta(ws);
      if (m && m.isProbe) continue;
      try { ws.send(payload); } catch {}
    }
  }

  findTarget(entries, msg) {
    if (msg.targetUserId) {
      const uid = String(msg.targetUserId);
      const hit = entries.find((e) => e.meta.userId === uid);
      if (hit) return hit;
    }
    if (msg.targetSpeakerId !== undefined) {
      const sid = Number(msg.targetSpeakerId);
      return entries.find((e) => e.meta.speakerId === sid) || null;
    }
    return null;
  }

  async fetch(request) {
    const url = new URL(request.url);
    if (url.pathname === '/usage-add') {
      let body = {};
      try { body = await request.json(); } catch {}
      const cur = await this.ctx.storage.get('usage_stats');
      const st = rollUsageStats(cur, Date.now());
      const addHttp = Number(body.httpRequests || 0);
      const addWs = Number(body.wsMessages || 0);
      st.monthIngressBytes += Number(body.ingressBytes || 0);
      st.monthEgressBytes += Number(body.egressBytes || 0);
      st.monthPackets += Number(body.packets || 0);
      st.monthHttpRequests += addHttp;
      st.monthWsMessages += addWs;
      st.monthRequests = st.monthHttpRequests + Math.ceil(st.monthWsMessages / 20);
      st.dayIngressBytes += Number(body.ingressBytes || 0);
      st.dayEgressBytes += Number(body.egressBytes || 0);
      st.dayPackets += Number(body.packets || 0);
      st.dayHttpRequests += addHttp;
      st.dayWsMessages += addWs;
      st.dayRequests = st.dayHttpRequests + Math.ceil(st.dayWsMessages / 20);
      st.totalIngressBytes += Number(body.ingressBytes || 0);
      st.totalEgressBytes += Number(body.egressBytes || 0);
      st.totalPackets += Number(body.packets || 0);
      st.totalRequests += addHttp + Math.ceil(addWs / 20);
      st.updatedAt = Date.now();
      await this.ctx.storage.put('usage_stats', st);
      return Response.json(st);
    }
    if (url.pathname === '/usage-get') {
      const cur = await this.ctx.storage.get('usage_stats');
      const st = rollUsageStats(cur, Date.now());
      return Response.json(st);
    }
    if (url.pathname === '/room-metrics') {
      const rParam = url.searchParams.get('_room');
      if (rParam && !this.roomId) this.roomId = rParam;
      if ((Date.now() - this._lastUsageFlushAt) >= 15000) {
        await this.flushUsageToGlobal(true);
      }
      return Response.json(this.buildMetricsSnapshot());
    }
    if (request.headers.get('Upgrade') !== 'websocket') {
      return new Response('Expected WebSocket', { status: 426 });
    }
    const roomId = url.searchParams.get('_room') || 'lobby';
    const userId = url.searchParams.get('_user') || '';
    const name = url.searchParams.get('_name') || 'مشارك';
    const role = url.searchParams.get('_role') === 'host' ? 'host' : 'student';
    const isProbe = url.searchParams.get('_probe') === '1';

    if (isProbe) {
      const pair = new WebSocketPair();
      const [client, server] = [pair[0], pair[1]];
      this.ctx.acceptWebSocket(server);
      this.saveMeta(server, { userId, name, role: 'host', isProbe: true }, true);
      try { server.send(JSON.stringify({ type: 'welcome', roomId, isProbe: true })); } catch {}
      return new Response(null, { status: 101, webSocket: client });
    }

    if (this.roomId !== roomId) {
      this.roomId = roomId;
      await this.ctx.storage.put('roomId', roomId);
    }

    this._unflushedHttpReqs++;

    let inheritedCanSpeak = (role === 'host') || !!this.allowAllMic;
    let inheritedHandRaised = false;
    const activeEntries = [];
    for (const entry of this.getSocketsWithMeta()) {
      if (entry.meta.userId === userId) {
        if (role !== 'host') {
          if (entry.meta.canSpeak) inheritedCanSpeak = true;
          if (entry.meta.handRaised && !this.allowAllMic) inheritedHandRaised = true;
        }
        this.saveMeta(entry.ws, null, true);
        try { entry.ws.close(4004, 'Replaced by new connection'); } catch {}
      } else {
        activeEntries.push(entry);
      }
    }

    const speakerId = this.allocateSpeakerId(activeEntries);

    const pair = new WebSocketPair();
    const [client, server] = [pair[0], pair[1]];
    const meta = {
      userId,
      speakerId,
      name,
      role,
      canSpeak: inheritedCanSpeak,
      handRaised: inheritedHandRaised,
      isSpeaking: false,
      opusSupported: true,
      lastAudioAt: 0,
      lastRtt: 0,
      _attAt: 0,
    };

    this.totalWsConnects++;
    this.ctx.acceptWebSocket(server);
    this.saveMeta(server, meta, true);

    try {
      server.send(JSON.stringify({
        type: 'welcome',
        roomId: this.roomId,
        allowAllMic: !!this.allowAllMic,
        self: {
          userId: meta.userId,
          speakerId: meta.speakerId,
          name: meta.name,
          role: meta.role,
          canSpeak: meta.canSpeak,
          handRaised: meta.handRaised,
        },
        whiteboard: {
          enabled: this.wbEnabled,
          strokes: this.wbStrokes,
          slides: this.wbSlides,
          slideIndex: this.wbSlideIndex,
        },
      }));
    } catch {}

    this.scheduleRoomStateBroadcast(role === 'host');

    return new Response(null, { status: 101, webSocket: client });
  }

  async webSocketMessage(ws, message) {
    const meta = this.getMeta(ws);
    if (!meta) return;

    if (meta.isProbe) {
      if (typeof message === 'string') {
        try {
          const pm = JSON.parse(message);
          if (pm && pm.type === 'ping') {
            try { ws.send(JSON.stringify({ type: 'pong', t: pm.t || Date.now(), isProbe: true })); } catch {}
          }
        } catch {}
      }
      return;
    }

    this._unflushedWsMsgs++;

    if (message instanceof ArrayBuffer || ArrayBuffer.isView(message)) {
      if (meta.role !== 'host' && !meta.canSpeak) return;
      const raw = message instanceof ArrayBuffer
        ? new Uint8Array(message)
        : new Uint8Array(message.buffer, message.byteOffset, message.byteLength);
      if (raw.byteLength < 10) return;

      const out = new Uint8Array(raw.byteLength);
      out.set(raw);
      out[0] = (meta.speakerId >> 8) & 0xFF;
      out[1] = meta.speakerId & 0xFF;

      this.audioPacketsRelayed++;
      this.audioBytesRelayed += raw.byteLength;
      this.audioIngressBytes += raw.byteLength;
      this._unflushedIngress += raw.byteLength;
      this._unflushedPackets++;

      const now = Date.now();
      meta.lastAudioAt = now;
      const startedSpeaking = !meta.isSpeaking;
      if (startedSpeaking) meta.isSpeaking = true;

      if (startedSpeaking || (now - (meta._attAt || 0)) >= 700) {
        meta._attAt = now;
        this.saveMeta(ws, meta, true);
        this.ctx.storage.setAlarm(now + 1200).catch(() => {});
      }

      if (startedSpeaking) {
        this.broadcastJson({ type: 'speaking', userId: meta.userId, speakerId: meta.speakerId, speaking: true });
      }

      const sockets = this.ctx.getWebSockets();
      let sentBytes = 0;
      let sentPkts = 0;
      for (let i = 0, len = sockets.length; i < len; i++) {
        const other = sockets[i];
        if (other === ws) continue;
        const oMeta = this.getMeta(other);
        if (oMeta && oMeta.isProbe) continue;
        try {
          other.send(out);
          sentBytes += out.byteLength;
          sentPkts++;
        } catch {
          this.droppedFrames++;
        }
      }
      if (sentBytes > 0) {
        this.audioEgressBytes += sentBytes;
        this.audioEgressPackets += sentPkts;
        this._unflushedEgress += sentBytes;
      }
      this.flushUsageToGlobal(false).catch(() => {});
      return;
    }

    if (typeof message !== 'string') return;
    let msg;
    try { msg = JSON.parse(message); } catch { return; }
    if (!msg || typeof msg.type !== 'string') return;

    if (msg.type === 'ping') {
      try { ws.send(JSON.stringify({ type: 'pong', t: msg.t || Date.now() })); } catch {}
      if (typeof msg.lastRtt === 'number' && msg.lastRtt > 0 && msg.lastRtt < 30000) {
        meta.lastRtt = msg.lastRtt;
        const now = Date.now();
        if (now - (meta._rttSavedAt || 0) >= 10000) {
          meta._rttSavedAt = now;
          this.saveMeta(ws, meta, true);
        }
        if (this.bitrateMode === 'auto' && (now - this._lastBitrateCheckAt) >= 4000) {
          this._lastBitrateCheckAt = now;
          const entries = this.getSocketsWithMeta();
          if (entries.length >= 2) {
            let maxRtt = 0;
            for (let i = 0; i < entries.length; i++) {
              const r = entries[i].meta.lastRtt || 0;
              if (r > maxRtt) maxRtt = r;
            }
            const idx = BITRATE_STEPS.indexOf(clampBitrate(this.targetBitrate));
            if (maxRtt > 420 && idx > 0) {
              this.stableCycles = 0;
              this.targetBitrate = BITRATE_STEPS[idx - 1];
              this.ctx.storage.put('targetBitrate', this.targetBitrate).catch(() => {});
              this.scheduleRoomStateBroadcast(false);
            } else if (maxRtt > 0 && maxRtt < 240 && idx < BITRATE_STEPS.length - 1) {
              this.stableCycles++;
              if (this.stableCycles >= 4) {
                this.stableCycles = 0;
                this.targetBitrate = BITRATE_STEPS[idx + 1];
                this.ctx.storage.put('targetBitrate', this.targetBitrate).catch(() => {});
                this.scheduleRoomStateBroadcast(false);
              }
            }
          }
        }
      }
      return;
    }

    if (msg.type === 'hello') {
      const nextOpus = !!msg.opus;
      if (meta.opusSupported !== nextOpus) {
        meta.opusSupported = nextOpus;
        this.saveMeta(ws, meta, true);
        this.scheduleRoomStateBroadcast(false);
      }
      return;
    }

    // Whiteboard & Slides Sync
    if (msg.type === 'wb_toggle') {
      if (meta.role !== 'host') return;
      this.wbEnabled = !!msg.enabled;
      await this.ctx.storage.put('wbEnabled', this.wbEnabled);
      this.broadcastJson({ type: 'wb_state', enabled: this.wbEnabled });
      return;
    }
    if (msg.type === 'wb_stroke') {
      if (meta.role !== 'host' && !meta.canSpeak) return;
      if (msg.stroke && Array.isArray(msg.stroke.points)) {
        this.wbStrokes.push(msg.stroke);
        if (this.wbStrokes.length > MAX_WB_STROKES) {
          this.wbStrokes.splice(0, this.wbStrokes.length - MAX_WB_STROKES);
        }
        this.broadcastJson({ type: 'wb_stroke', stroke: msg.stroke, by: meta.userId }, ws);
      }
      return;
    }
    if (msg.type === 'wb_cursor') {
      if (meta.role !== 'host' && !meta.canSpeak) return;
      this.broadcastJson({ type: 'wb_cursor', x: Number(msg.x || 0), y: Number(msg.y || 0), active: !!msg.active }, ws);
      return;
    }
    if (msg.type === 'wb_clear') {
      if (meta.role !== 'host') return;
      this.wbStrokes = [];
      this.broadcastJson({ type: 'wb_clear' });
      return;
    }
    if (msg.type === 'wb_slide') {
      if (meta.role !== 'host') return;
      if (Array.isArray(msg.slides)) this.wbSlides = msg.slides.slice(0, 40);
      this.wbSlideIndex = Math.max(0, Number(msg.slideIndex || 0));
      this.broadcastJson({ type: 'wb_slide', slides: this.wbSlides, slideIndex: this.wbSlideIndex });
      return;
    }
    if (msg.type === 'chat_notify' || msg.type === 'poll_notify' || msg.type === 'pin_notify') {
      this.broadcastJson({ type: msg.type, by: meta.userId }, ws);
      return;
    }

    const entries = this.getSocketsWithMeta();

    switch (msg.type) {
      case 'set_quality':
        if (meta.role !== 'host') return;
        this.bitrateMode = msg.mode === 'manual' ? 'manual' : 'auto';
        this.targetBitrate = this.bitrateMode === 'auto' ? BITRATE_MAX : clampBitrate(msg.bitrate);
        this.stableCycles = 0;
        await this.ctx.storage.put({ bitrateMode: this.bitrateMode, targetBitrate: this.targetBitrate });
        this.scheduleRoomStateBroadcast(true);
        break;

      case 'raise_hand':
        if (meta.role === 'student' && !meta.canSpeak && !this.allowAllMic && !meta.handRaised) {
          meta.handRaised = true;
          this.saveMeta(ws, meta, true);
          this.scheduleRoomStateBroadcast(false);
        }
        break;

      case 'lower_hand':
        if (meta.handRaised) {
          meta.handRaised = false;
          this.saveMeta(ws, meta, true);
          this.scheduleRoomStateBroadcast(false);
        }
        break;

      case 'speaker_idle':
        if (meta.isSpeaking) {
          meta.isSpeaking = false;
          this.saveMeta(ws, meta, true);
          this.broadcastJson({ type: 'speaking', userId: meta.userId, speakerId: meta.speakerId, speaking: false });
        }
        break;

      case 'approve_speaker': {
        if (meta.role !== 'host') return;
        const target = this.findTarget(entries, msg);
        if (!target || target.meta.role === 'host') return;
        target.meta.canSpeak = true;
        target.meta.handRaised = false;
        this.saveMeta(target.ws, target.meta, true);
        try { target.ws.send(JSON.stringify({ type: 'permission_granted', by: meta.name })); } catch {}
        this.scheduleRoomStateBroadcast(true);
        break;
      }

      case 'revoke_speaker': {
        if (meta.role !== 'host') return;
        const target = this.findTarget(entries, msg);
        if (!target || target.meta.role === 'host') return;
        const hadSpeak = !!target.meta.canSpeak;
        target.meta.canSpeak = false;
        target.meta.handRaised = false;
        target.meta.isSpeaking = false;
        this.saveMeta(target.ws, target.meta, true);
        try { target.ws.send(JSON.stringify({ type: hadSpeak ? 'permission_revoked' : 'hand_dismissed', by: meta.name })); } catch {}
        this.scheduleRoomStateBroadcast(true);
        break;
      }

      case 'dismiss_hand': {
        if (meta.role !== 'host') return;
        const target = this.findTarget(entries, msg);
        if (!target || target.meta.role === 'host') return;
        target.meta.handRaised = false;
        this.saveMeta(target.ws, target.meta, true);
        try { target.ws.send(JSON.stringify({ type: 'hand_dismissed', by: meta.name })); } catch {}
        this.scheduleRoomStateBroadcast(false);
        break;
      }

      case 'session_ended': {
        if (meta.role !== 'host') return;
        this.broadcastJson({ type: 'session_ended', by: meta.name }, ws);
        break;
      }

      case 'set_allow_all_mic': {
        if (meta.role !== 'host') return;
        const nextOpen = !!msg.enabled;
        this.allowAllMic = nextOpen;
        await this.ctx.storage.put('allowAllMic', this.allowAllMic);
        for (const other of entries) {
          if (other.meta.role === 'host') continue;
          other.meta.canSpeak = nextOpen;
          other.meta.handRaised = false;
          if (!nextOpen) other.meta.isSpeaking = false;
          this.saveMeta(other.ws, other.meta, true);
          try { other.ws.send(JSON.stringify({ type: 'allow_all_mic_changed', enabled: nextOpen, by: meta.name })); } catch {}
        }
        this.scheduleRoomStateBroadcast(true);
        break;
      }

      case 'mute_all': {
        if (meta.role !== 'host') return;
        this.allowAllMic = false;
        await this.ctx.storage.put('allowAllMic', false);
        for (const other of entries) {
          if (other.meta.role === 'host') continue;
          const had = other.meta.canSpeak || other.meta.handRaised;
          other.meta.canSpeak = false;
          other.meta.handRaised = false;
          other.meta.isSpeaking = false;
          this.saveMeta(other.ws, other.meta, true);
          if (had) {
            try { other.ws.send(JSON.stringify({ type: 'permission_revoked', reason: 'mute_all', by: meta.name })); } catch {}
          }
        }
        this.scheduleRoomStateBroadcast(true);
        break;
      }

      default:
        break;
    }
  }

  async alarm() {
    if (this._pendingRoomState) {
      this.broadcastRoomState();
    }
    const now = Date.now();
    let anyActive = false;
    for (const { ws, meta } of this.getSocketsWithMeta()) {
      if (meta.isSpeaking) {
        if (now - (meta.lastAudioAt || 0) > 750) {
          meta.isSpeaking = false;
          this.saveMeta(ws, meta, true);
          this.broadcastJson({ type: 'speaking', userId: meta.userId, speakerId: meta.speakerId, speaking: false });
        } else {
          anyActive = true;
        }
      }
    }
    if (anyActive) {
      await this.ctx.storage.setAlarm(Date.now() + 750);
    }
  }

  async webSocketClose(ws) {
    const m = this.getMeta(ws);
    this.saveMeta(ws, null, false);
    try { ws.close(); } catch {}
    if (m && m.isProbe) return;
    this.totalWsDisconnects++;
    await this.flushUsageToGlobal(true);
    this.scheduleRoomStateBroadcast(false);
  }

  async webSocketError(ws) {
    const m = this.getMeta(ws);
    this.saveMeta(ws, null, false);
    try { ws.close(); } catch {}
    if (m && m.isProbe) return;
    this.totalWsDisconnects++;
    await this.flushUsageToGlobal(true);
    this.scheduleRoomStateBroadcast(false);
  }
}

function corsHeaders() {
  return {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET, HEAD, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type, Authorization',
  };
}

export default {
  async fetch(request, env) {
    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: corsHeaders() });
    }
    const url = new URL(request.url);
    const cfInfo = request.cf || {};

    if (url.pathname === '/health') {
      return Response.json(
        {
          status: 'ok',
          service: 'abuanas-meetings-cf-worker',
          runtime: 'Cloudflare Workers + Durable Objects',
          jwtConfigured: !!(env.JWT_SECRET && env.JWT_SECRET.trim()),
          edge: { colo: cfInfo.colo || '', country: cfInfo.country || '', city: cfInfo.city || '', httpProtocol: cfInfo.httpProtocol || '' },
          timestamp: Date.now(),
        },
        { headers: { ...corsHeaders(), 'Cache-Control': 'no-store' } }
      );
    }

    if (url.pathname === '/metrics') {
      let token = url.searchParams.get('token') || '';
      if (!token) {
        const auth = request.headers.get('Authorization') || '';
        const m = auth.match(/^Bearer\s+(.+)$/i);
        if (m) token = m[1].trim();
      }
      const claims = await verifyJwt(token, (env.JWT_SECRET || '').trim());
      if (!claims || claims.role !== 'host') {
        return new Response('Unauthorized', { status: 401, headers: corsHeaders() });
      }
      const targetRoom = (url.searchParams.get('room') || claims.roomId || 'lobby').trim();
      const stub = env.VOICE_ROOM.get(env.VOICE_ROOM.idFromName(targetRoom));
      const gstub = env.VOICE_ROOM.get(env.VOICE_ROOM.idFromName('__global_usage__'));
      const mUrl = new URL(request.url);
      mUrl.pathname = '/room-metrics';
      mUrl.searchParams.set('_room', targetRoom);
      const [roomRes, usageRes] = await Promise.all([stub.fetch(mUrl.toString()), gstub.fetch('https://internal/usage-get')]);
      const [roomData, globalUsage] = await Promise.all([roomRes.json(), usageRes.json()]);
      return Response.json(
        {
          status: 'ok',
          service: 'abuanas-meetings-cf-worker',
          runtime: 'Cloudflare Workers + Durable Objects',
          jwtConfigured: !!(env.JWT_SECRET && env.JWT_SECRET.trim()),
          edge: { colo: cfInfo.colo || '', country: cfInfo.country || '', city: cfInfo.city || '', httpProtocol: cfInfo.httpProtocol || '' },
          room: roomData,
          globalUsage,
          timestamp: Date.now(),
        },
        { headers: { ...corsHeaders(), 'Cache-Control': 'no-store' } }
      );
    }

    if (request.headers.get('Upgrade') === 'websocket') {
      let token = url.searchParams.get('token') || '';
      if (!token) {
        const auth = request.headers.get('Authorization') || '';
        const m = auth.match(/^Bearer\s+(.+)$/i);
        if (m) token = m[1].trim();
      }
      const claims = await verifyJwt(token, (env.JWT_SECRET || '').trim());
      if (!claims) return new Response('Unauthorized', { status: 401 });

      const stub = env.VOICE_ROOM.get(env.VOICE_ROOM.idFromName(claims.roomId));
      const internalUrl = new URL(request.url);
      internalUrl.searchParams.set('_room', claims.roomId);
      internalUrl.searchParams.set('_user', claims.userId);
      internalUrl.searchParams.set('_name', claims.name);
      internalUrl.searchParams.set('_role', claims.role);
      if (claims.probe) internalUrl.searchParams.set('_probe', '1');
      return stub.fetch(internalUrl.toString(), request);
    }

    if (url.pathname === '/') {
      return Response.json({ status: 'ok', service: 'abuanas-meetings-cf-worker', health: '/health' }, { headers: corsHeaders() });
    }
    return new Response('Not found', { status: 404 });
  },
};

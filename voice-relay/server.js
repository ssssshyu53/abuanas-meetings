'use strict';

const http = require('http');
const os = require('os');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { URL } = require('url');
const { WebSocketServer, WebSocket } = require('ws');

function loadFallbackSecret() {
  if (process.env.JWT_SECRET && process.env.JWT_SECRET.trim()) {
    return process.env.JWT_SECRET.trim();
  }
  const envFile = path.resolve(__dirname, '..', '.env');
  if (fs.existsSync(envFile)) {
    const lines = fs.readFileSync(envFile, 'utf8').split(/\r?\n/);
    for (const line of lines) {
      const m = line.match(/^\s*JWT_SECRET\s*=\s*(.+)\s*$/);
      if (m) return m[1].trim().replace(/^['"]|['"]$/g, '');
    }
  }
  return '';
}

const PORT = parseInt(process.env.PORT || '8788', 10);
const JWT_SECRET = loadFallbackSecret();
const MAX_BUFFERED_BYTES = 16384;
const BITRATE_MAX = 64000;
const BITRATE_STEPS = [16000, 24000, 32000, 48000, 64000];
const MAX_WB_STROKES = 300;

const rooms = new Map();
const usage = {
  monthKey: new Date().toISOString().slice(0, 7),
  dayKey: new Date().toISOString().slice(0, 10),
  monthIngressBytes: 0,
  monthEgressBytes: 0,
  monthPackets: 0,
  monthHttpRequests: 0,
  monthWsMessages: 0,
  dayIngressBytes: 0,
  dayEgressBytes: 0,
  dayPackets: 0,
  dayHttpRequests: 0,
  dayWsMessages: 0,
};

function rollUsage() {
  const iso = new Date().toISOString();
  const mk = iso.slice(0, 7);
  const dk = iso.slice(0, 10);
  if (usage.monthKey !== mk) {
    usage.monthKey = mk;
    usage.monthIngressBytes = 0;
    usage.monthEgressBytes = 0;
    usage.monthPackets = 0;
    usage.monthHttpRequests = 0;
    usage.monthWsMessages = 0;
  }
  if (usage.dayKey !== dk) {
    usage.dayKey = dk;
    usage.dayIngressBytes = 0;
    usage.dayEgressBytes = 0;
    usage.dayPackets = 0;
    usage.dayHttpRequests = 0;
    usage.dayWsMessages = 0;
  }
  return {
    ...usage,
    monthRequests: usage.monthHttpRequests + Math.ceil(usage.monthWsMessages / 20),
    dayRequests: usage.dayHttpRequests + Math.ceil(usage.dayWsMessages / 20),
    updatedAt: Date.now(),
  };
}

function base64UrlDecode(str) {
  const padded = str.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((str.length + 3) % 4);
  return Buffer.from(padded, 'base64');
}
function base64UrlEncode(buf) {
  return buf.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function verifyJwt(token) {
  if (!JWT_SECRET || !token || typeof token !== 'string') return null;
  const parts = token.split('.');
  if (parts.length !== 3) return null;
  const [headerB64, payloadB64, sigB64] = parts;
  let header;
  try { header = JSON.parse(base64UrlDecode(headerB64).toString('utf8')); } catch { return null; }
  if (!header || header.alg !== 'HS256') return null;

  const expected = base64UrlEncode(crypto.createHmac('sha256', JWT_SECRET).update(`${headerB64}.${payloadB64}`).digest());
  const a = Buffer.from(sigB64, 'utf8');
  const b = Buffer.from(expected, 'utf8');
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return null;

  let payload;
  try { payload = JSON.parse(base64UrlDecode(payloadB64).toString('utf8')); } catch { return null; }
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

function clampBitrate(bps) {
  const n = Number(bps);
  if (!Number.isFinite(n)) return 32000;
  if (n <= 18000) return 16000;
  if (n <= 26000) return 24000;
  if (n <= 38000) return 32000;
  if (n <= 54000) return 48000;
  return BITRATE_MAX;
}

function getOrCreateRoom(roomId) {
  let room = rooms.get(roomId);
  if (!room) {
    room = {
      clients: new Map(),
      nextSpeakerId: 1,
      bitrateMode: 'auto',
      targetBitrate: 32000,
      allowAllMic: false,
      startedAt: Date.now(),
      audioPacketsRelayed: 0,
      audioBytesRelayed: 0,
      audioIngressBytes: 0,
      audioEgressBytes: 0,
      audioEgressPackets: 0,
      droppedFrames: 0,
      stableCycles: 0,
      lastDropsTotal: 0,
      wbEnabled: false,
      wbStrokes: [],
      wbSlides: [],
      wbSlideIndex: 0,
    };
    rooms.set(roomId, room);
  }
  return room;
}

function allocateSpeakerId(room) {
  for (let i = 0; i < 65535; i++) {
    const c = room.nextSpeakerId;
    room.nextSpeakerId = room.nextSpeakerId >= 65534 ? 1 : room.nextSpeakerId + 1;
    let used = false;
    for (const cl of room.clients.values()) {
      if (cl.speakerId === c) { used = true; break; }
    }
    if (!used) return c;
  }
  return 1;
}

function sendJson(ws, obj) {
  if (ws && ws.readyState === WebSocket.OPEN) {
    try { ws.send(JSON.stringify(obj)); } catch {}
  }
}

function broadcastJson(room, obj, excludeUserId = null) {
  const raw = JSON.stringify(obj);
  for (const c of room.clients.values()) {
    if (excludeUserId && c.userId === excludeUserId) continue;
    if (c.ws && c.ws.readyState === WebSocket.OPEN) {
      try { c.ws.send(raw); } catch {}
    }
  }
}

function broadcastRoomState(roomId, room) {
  const participants = [];
  let hostPresent = false;
  let allOpus = room.clients.size > 0;
  for (const c of room.clients.values()) {
    if (c.role === 'host') hostPresent = true;
    if (!c.opusSupported) allOpus = false;
    participants.push({
      userId: c.userId,
      speakerId: c.speakerId,
      name: c.name,
      role: c.role,
      canSpeak: !!c.canSpeak,
      handRaised: !!c.handRaised,
      isSpeaking: !!c.isSpeaking,
    });
  }
  participants.sort((a, b) => {
    if (a.role !== b.role) return a.role === 'host' ? -1 : 1;
    if (a.canSpeak !== b.canSpeak) return a.canSpeak ? -1 : 1;
    if (a.handRaised !== b.handRaised) return a.handRaised ? -1 : 1;
    return a.speakerId - b.speakerId;
  });
  broadcastJson(room, {
    type: 'room_state',
    roomId,
    hostPresent,
    allowAllMic: !!room.allowAllMic,
    codecMode: allOpus ? 'opus' : 'adpcm16',
    bitrateMode: room.bitrateMode,
    recommendedBitrate: clampBitrate(room.targetBitrate),
    count: participants.length,
    participants,
  });
}

function buildRoomMetrics(roomId, room) {
  let hosts = 0, speakers = 0, listeners = 0, handRaised = 0, speaking = 0, opusCount = 0;
  const rtts = [];
  const participants = [];
  for (const c of room.clients.values()) {
    if (c.role === 'host') hosts++;
    else { listeners++; if (c.canSpeak) speakers++; }
    if (c.handRaised) handRaised++;
    if (c.isSpeaking) speaking++;
    if (c.opusSupported) opusCount++;
    if (c.lastRtt > 0) rtts.push(c.lastRtt);
    participants.push({
      userId: c.userId, name: c.name, role: c.role, canSpeak: !!c.canSpeak,
      handRaised: !!c.handRaised, isSpeaking: !!c.isSpeaking, opusSupported: !!c.opusSupported, lastRttMs: c.lastRtt || 0,
    });
  }
  return {
    roomId,
    allowAllMic: !!room.allowAllMic,
    codecMode: (room.clients.size > 0 && opusCount === room.clients.size) ? 'opus' : 'adpcm16',
    bitrateMode: room.bitrateMode,
    targetBitrate: clampBitrate(room.targetBitrate),
    uptimeSeconds: Math.floor((Date.now() - room.startedAt) / 1000),
    audioPacketsRelayed: room.audioPacketsRelayed,
    audioBytesRelayed: room.audioBytesRelayed,
    audioIngressBytes: room.audioIngressBytes,
    audioEgressBytes: room.audioEgressBytes,
    audioEgressPackets: room.audioEgressPackets,
    droppedFrames: room.droppedFrames,
    counts: { total: room.clients.size, hosts, speakers, listeners, handRaised, speaking, opusClients: opusCount },
    avgRttMs: rtts.length ? Math.round(rtts.reduce((a, b) => a + b, 0) / rtts.length) : 0,
    maxRttMs: rtts.length ? Math.max(...rtts) : 0,
    minRttMs: rtts.length ? Math.min(...rtts) : 0,
    participants,
  };
}

const server = http.createServer((req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
  if (req.method === 'OPTIONS') { res.writeHead(204); res.end(); return; }

  const url = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);
  if (url.pathname === '/health' || url.pathname === '/') {
    const mem = process.memoryUsage();
    res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
    res.end(JSON.stringify({
      status: 'ok',
      service: 'abuanas-meetings-node-relay',
      runtime: `Node.js ${process.version}`,
      uptime: Math.floor(process.uptime()),
      rooms: rooms.size,
      jwtConfigured: !!JWT_SECRET,
      memory: { rssMb: Math.round(mem.rss / 1048576), heapUsedMb: Math.round(mem.heapUsed / 1048576) },
      loadAvg: os.loadavg().map((v) => Number(v.toFixed(2))),
      timestamp: Date.now(),
    }));
    return;
  }

  if (url.pathname === '/metrics') {
    let tok = url.searchParams.get('token') || '';
    if (!tok && req.headers.authorization) {
      const m = req.headers.authorization.match(/^Bearer\s+(.+)$/i);
      if (m) tok = m[1].trim();
    }
    const claims = verifyJwt(tok);
    if (!claims || claims.role !== 'host') {
      res.writeHead(401, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'Unauthorized' }));
      return;
    }
    const targetRoom = url.searchParams.get('room') || claims.roomId || 'lobby';
    const room = rooms.get(targetRoom);
    const mem = process.memoryUsage();
    res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
    res.end(JSON.stringify({
      status: 'ok',
      service: 'abuanas-meetings-node-relay',
      runtime: `Node.js ${process.version}`,
      uptime: Math.floor(process.uptime()),
      jwtConfigured: !!JWT_SECRET,
      memory: { rssMb: Math.round(mem.rss / 1048576), heapUsedMb: Math.round(mem.heapUsed / 1048576) },
      loadAvg: os.loadavg().map((v) => Number(v.toFixed(2))),
      room: room ? buildRoomMetrics(targetRoom, room) : {
        roomId: targetRoom, codecMode: 'opus', targetBitrate: 32000, uptimeSeconds: 0,
        audioPacketsRelayed: 0, audioBytesRelayed: 0, audioIngressBytes: 0, audioEgressBytes: 0, droppedFrames: 0,
        counts: { total: 0, hosts: 0, speakers: 0, listeners: 0, handRaised: 0, speaking: 0 }, participants: [],
      },
      globalUsage: rollUsage(),
      timestamp: Date.now(),
    }));
    return;
  }

  res.writeHead(404);
  res.end('Not found');
});

const wss = new WebSocketServer({ server });

wss.on('connection', (ws, req) => {
  const url = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);
  let token = url.searchParams.get('token') || '';
  const claims = verifyJwt(token);
  if (!claims) {
    try { ws.close(4001, 'Unauthorized'); } catch {}
    return;
  }

  if (claims.probe) {
    sendJson(ws, { type: 'welcome', roomId: claims.roomId, isProbe: true });
    ws.on('message', (data, isBinary) => {
      if (isBinary) return;
      try {
        const msg = JSON.parse(data.toString('utf8'));
        if (msg && msg.type === 'ping') sendJson(ws, { type: 'pong', t: msg.t || Date.now(), isProbe: true });
      } catch {}
    });
    return;
  }

  rollUsage();
  usage.monthHttpRequests++;
  usage.dayHttpRequests++;

  const room = getOrCreateRoom(claims.roomId);
  const existing = room.clients.get(claims.userId);
  let canSpeak = (claims.role === 'host') || !!room.allowAllMic;
  let handRaised = false;
  if (existing) {
    if (claims.role !== 'host') {
      if (existing.canSpeak) canSpeak = true;
      if (existing.handRaised && !room.allowAllMic) handRaised = true;
    }
    try { existing.ws.close(4004, 'Replaced by new connection'); } catch {}
    room.clients.delete(claims.userId);
  }

  const client = {
    ws,
    userId: claims.userId,
    speakerId: allocateSpeakerId(room),
    name: claims.name,
    role: claims.role,
    canSpeak,
    handRaised,
    isSpeaking: false,
    opusSupported: true,
    lastRtt: 0,
    lastAudioAt: 0,
  };
  room.clients.set(client.userId, client);

  sendJson(ws, {
    type: 'welcome',
    roomId: claims.roomId,
    allowAllMic: !!room.allowAllMic,
    self: { userId: client.userId, speakerId: client.speakerId, name: client.name, role: client.role, canSpeak: client.canSpeak, handRaised: client.handRaised },
    whiteboard: { enabled: room.wbEnabled, strokes: room.wbStrokes, slides: room.wbSlides, slideIndex: room.wbSlideIndex },
  });
  broadcastRoomState(claims.roomId, room);

  ws.on('message', (data, isBinary) => {
    if (room.clients.get(client.userId) !== client) return;
    rollUsage();
    usage.monthWsMessages++;
    usage.dayWsMessages++;

    if (isBinary) {
      if (client.role !== 'host' && !client.canSpeak) return;
      const buf = Buffer.isBuffer(data) ? data : Buffer.from(data);
      if (buf.length < 10) return;

      const out = Buffer.from(buf);
      out.writeUInt16BE(client.speakerId & 0xFFFF, 0);

      room.audioPacketsRelayed++;
      room.audioBytesRelayed += out.length;
      room.audioIngressBytes += out.length;
      usage.monthIngressBytes += out.length;
      usage.dayIngressBytes += out.length;
      usage.monthPackets++;
      usage.dayPackets++;

      client.lastAudioAt = Date.now();
      if (!client.isSpeaking) {
        client.isSpeaking = true;
        broadcastJson(room, { type: 'speaking', userId: client.userId, speakerId: client.speakerId, speaking: true });
      }

      let sentBytes = 0;
      let sentPkts = 0;
      for (const other of room.clients.values()) {
        if (other === client || !other.ws || other.ws.readyState !== WebSocket.OPEN) continue;
        if (other.ws.bufferedAmount > MAX_BUFFERED_BYTES) {
          room.droppedFrames++;
          continue;
        }
        try {
          other.ws.send(out, { binary: true });
          sentBytes += out.length;
          sentPkts++;
        } catch {
          room.droppedFrames++;
        }
      }
      if (sentBytes > 0) {
        room.audioEgressBytes += sentBytes;
        room.audioEgressPackets += sentPkts;
        usage.monthEgressBytes += sentBytes;
        usage.dayEgressBytes += sentBytes;
      }
      return;
    }

    let msg;
    try { msg = JSON.parse(data.toString('utf8')); } catch { return; }
    if (!msg || typeof msg.type !== 'string') return;

    if (msg.type === 'ping') {
      if (typeof msg.lastRtt === 'number' && msg.lastRtt > 0 && msg.lastRtt < 30000) client.lastRtt = msg.lastRtt;
      sendJson(ws, { type: 'pong', t: msg.t || Date.now() });
      return;
    }
    if (msg.type === 'hello') {
      client.opusSupported = !!msg.opus;
      broadcastRoomState(claims.roomId, room);
      return;
    }

    // Whiteboard & Slides Sync
    if (msg.type === 'wb_toggle' && client.role === 'host') {
      room.wbEnabled = !!msg.enabled;
      broadcastJson(room, { type: 'wb_state', enabled: room.wbEnabled });
      return;
    }
    if (msg.type === 'wb_stroke' && (client.role === 'host' || client.canSpeak)) {
      if (msg.stroke && Array.isArray(msg.stroke.points)) {
        room.wbStrokes.push(msg.stroke);
        if (room.wbStrokes.length > MAX_WB_STROKES) room.wbStrokes.splice(0, room.wbStrokes.length - MAX_WB_STROKES);
        broadcastJson(room, { type: 'wb_stroke', stroke: msg.stroke, by: client.userId }, client.userId);
      }
      return;
    }
    if (msg.type === 'wb_cursor' && (client.role === 'host' || client.canSpeak)) {
      broadcastJson(room, { type: 'wb_cursor', x: Number(msg.x || 0), y: Number(msg.y || 0), active: !!msg.active }, client.userId);
      return;
    }
    if (msg.type === 'wb_clear' && client.role === 'host') {
      room.wbStrokes = [];
      broadcastJson(room, { type: 'wb_clear' });
      return;
    }
    if (msg.type === 'wb_slide' && client.role === 'host') {
      if (Array.isArray(msg.slides)) room.wbSlides = msg.slides.slice(0, 40);
      room.wbSlideIndex = Math.max(0, Number(msg.slideIndex || 0));
      broadcastJson(room, { type: 'wb_slide', slides: room.wbSlides, slideIndex: room.wbSlideIndex });
      return;
    }
    if (msg.type === 'chat_notify' || msg.type === 'poll_notify' || msg.type === 'pin_notify') {
      broadcastJson(room, { type: msg.type, by: client.userId }, client.userId);
      return;
    }

    if (msg.type === 'set_quality' && client.role === 'host') {
      room.bitrateMode = msg.mode === 'manual' ? 'manual' : 'auto';
      room.targetBitrate = room.bitrateMode === 'auto' ? BITRATE_MAX : clampBitrate(msg.bitrate);
      room.stableCycles = 0;
      broadcastRoomState(claims.roomId, room);
      return;
    }
    if (msg.type === 'raise_hand' && client.role === 'student' && !client.canSpeak && !room.allowAllMic && !client.handRaised) {
      client.handRaised = true;
      broadcastRoomState(claims.roomId, room);
      return;
    }
    if (msg.type === 'lower_hand' && client.handRaised) {
      client.handRaised = false;
      broadcastRoomState(claims.roomId, room);
      return;
    }
    if (msg.type === 'speaker_idle' && client.isSpeaking) {
      client.isSpeaking = false;
      broadcastJson(room, { type: 'speaking', userId: client.userId, speakerId: client.speakerId, speaking: false });
      return;
    }
    if (msg.type === 'approve_speaker' && client.role === 'host') {
      const target = room.clients.get(String(msg.targetUserId || ''));
      if (target && target.role !== 'host') {
        target.canSpeak = true;
        target.handRaised = false;
        sendJson(target.ws, { type: 'permission_granted', by: client.name });
        broadcastRoomState(claims.roomId, room);
      }
      return;
    }
    if (msg.type === 'revoke_speaker' && client.role === 'host') {
      const target = room.clients.get(String(msg.targetUserId || ''));
      if (target && target.role !== 'host') {
        const had = !!target.canSpeak;
        target.canSpeak = false;
        target.handRaised = false;
        target.isSpeaking = false;
        sendJson(target.ws, { type: had ? 'permission_revoked' : 'hand_dismissed', by: client.name });
        broadcastRoomState(claims.roomId, room);
      }
      return;
    }
    if (msg.type === 'dismiss_hand' && client.role === 'host') {
      const target = room.clients.get(String(msg.targetUserId || ''));
      if (target && target.role !== 'host') {
        target.handRaised = false;
        sendJson(target.ws, { type: 'hand_dismissed', by: client.name });
        broadcastRoomState(claims.roomId, room);
      }
      return;
    }
    if (msg.type === 'set_allow_all_mic' && client.role === 'host') {
      const nextOpen = !!msg.enabled;
      room.allowAllMic = nextOpen;
      for (const other of room.clients.values()) {
        if (other.role === 'host') continue;
        other.canSpeak = nextOpen;
        other.handRaised = false;
        if (!nextOpen) other.isSpeaking = false;
        sendJson(other.ws, { type: 'allow_all_mic_changed', enabled: nextOpen, by: client.name });
      }
      broadcastRoomState(claims.roomId, room);
      return;
    }
    if (msg.type === 'mute_all' && client.role === 'host') {
      room.allowAllMic = false;
      for (const other of room.clients.values()) {
        if (other.role === 'host') continue;
        const had = other.canSpeak || other.handRaised;
        other.canSpeak = false;
        other.handRaised = false;
        other.isSpeaking = false;
        if (had) sendJson(other.ws, { type: 'permission_revoked', reason: 'mute_all', by: client.name });
      }
      broadcastRoomState(claims.roomId, room);
      return;
    }
    if (msg.type === 'session_ended' && client.role === 'host') {
      broadcastJson(room, { type: 'session_ended', by: client.name }, client.userId);
    }
  });

  ws.on('close', () => {
    if (room.clients.get(client.userId) === client) {
      room.clients.delete(client.userId);
      if (room.clients.size === 0) rooms.delete(claims.roomId);
      else broadcastRoomState(claims.roomId, room);
    }
  });
});

// Sweep speaking state & adapt auto-bitrate smoothly
setInterval(() => {
  const now = Date.now();
  for (const [roomId, room] of rooms.entries()) {
    for (const c of room.clients.values()) {
      if (c.isSpeaking && (now - (c.lastAudioAt || 0)) > 750) {
        c.isSpeaking = false;
        broadcastJson(room, { type: 'speaking', userId: c.userId, speakerId: c.speakerId, speaking: false });
      }
    }
    if (room.bitrateMode === 'auto' && room.clients.size > 0) {
      const dropsDelta = room.droppedFrames - room.lastDropsTotal;
      room.lastDropsTotal = room.droppedFrames;
      const idx = BITRATE_STEPS.indexOf(clampBitrate(room.targetBitrate));
      if (dropsDelta > 3 && idx > 0) {
        room.stableCycles = 0;
        room.targetBitrate = BITRATE_STEPS[idx - 1];
        broadcastRoomState(roomId, room);
      } else if (dropsDelta === 0 && idx < BITRATE_STEPS.length - 1) {
        room.stableCycles++;
        if (room.stableCycles >= 4) {
          room.stableCycles = 0;
          room.targetBitrate = BITRATE_STEPS[idx + 1];
          broadcastRoomState(roomId, room);
        }
      }
    }
  }
}, 2500);

server.listen(PORT, '0.0.0.0', () => {
  console.log(`[abuanas-meetings-node-relay] listening on :${PORT} | jwtConfigured=${!!JWT_SECRET}`);
});

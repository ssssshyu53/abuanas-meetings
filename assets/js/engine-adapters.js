/**
 * Abu Anas Meetings Multi-Engine Transport Adapter
 * Supports: Native WS (Cloudflare DO + Node Relay), LiveKit SDK, 100ms SDK, Daily.co, Cloudflare Realtime SFU
 */
(function (global) {
  'use strict';

  function loadScriptOnce(src) {
    return new Promise((resolve, reject) => {
      if (document.querySelector(`script[src="${src}"]`)) return resolve();
      const s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = () => resolve();
      s.onerror = () => reject(new Error('Failed to load ' + src));
      document.head.appendChild(s);
    });
  }

  class RoomTransportAdapter {
    constructor(opts) {
      this.opts = opts;
      this.ws = null;
      this.wsUrls = [];
      this.wsIndex = 0;
      this.pingTimer = null;
      this.reconnectTimer = null;
      this.intentionalClose = false;
      this.videoClient = null;
      this.lastRtt = 0;
    }

    async connect(auth) {
      this.wsUrls = Array.isArray(auth.ws_urls) ? auth.ws_urls.slice() : [];
      this._connectWs(auth);

      if (auth.media_mode === 'video' && auth.video) {
        try {
          await this._connectVideo(auth);
        } catch (err) {
          if (this.opts.onFallbackNotice) {
            this.opts.onFallbackNotice('تعذر تشغيل محرك الفيديو (' + err.message + ')؛ الغرفة مستمرة بالصوت والسبورة.');
          }
        }
      }
    }

    _connectWs(auth) {
      if (!this.wsUrls.length) return;
      const base = this.wsUrls[this.wsIndex % this.wsUrls.length];
      const sep = base.includes('?') ? '&' : '?';
      const sock = new WebSocket(`${base}${sep}token=${encodeURIComponent(auth.ws_token)}`);
      sock.binaryType = 'arraybuffer';
      this.ws = sock;
      let opened = false;

      sock.onopen = () => {
        opened = true;
        sock.send(JSON.stringify({ type: 'hello', opus: !!this.opts.opusSupported }));
        clearInterval(this.pingTimer);
        const pingIntervalMs = this.opts.isHost ? 15000 : 45000;
        this.pingTimer = setInterval(() => {
          if (this.ws === sock && sock.readyState === WebSocket.OPEN) {
            sock.send(JSON.stringify({ type: 'ping', t: Date.now(), lastRtt: this.lastRtt }));
          }
        }, pingIntervalMs);
        if (this.opts.onWsOpen) this.opts.onWsOpen(base);
      };

      sock.onmessage = (ev) => {
        if (ev.data instanceof ArrayBuffer) {
          if (this.opts.onAudioPacket) this.opts.onAudioPacket(ev.data);
          return;
        }
        let msg;
        try { msg = JSON.parse(ev.data); } catch { return; }
        if (msg.type === 'pong') {
          this.lastRtt = Math.max(1, Date.now() - Number(msg.t || Date.now()));
          return;
        }
        if (this.opts.onMessage) this.opts.onMessage(msg);
      };

      sock.onclose = () => {
        clearInterval(this.pingTimer);
        if (this.intentionalClose) return;
        if (!opened && this.wsUrls.length > 1) {
          this.wsIndex = (this.wsIndex + 1) % this.wsUrls.length;
        }
        this.reconnectTimer = setTimeout(() => this._connectWs(auth), 1500);
      };
    }

    sendBinary(buf) {
      if (this.ws && this.ws.readyState === WebSocket.OPEN) {
        this.ws.send(buf);
      }
    }

    sendJson(obj) {
      if (this.ws && this.ws.readyState === WebSocket.OPEN) {
        this.ws.send(JSON.stringify(obj));
      }
    }

    async _connectVideo(auth) {
      const v = auth.video;
      const grid = this.opts.videoGridEl;
      if (!grid || !v) return;
      grid.classList.remove('hidden');

      if (v.provider === 'livekit') {
        await loadScriptOnce('https://cdn.jsdelivr.net/npm/livekit-client@2/dist/livekit-client.umd.min.js');
        const room = new global.LivekitClient.Room({ adaptiveStream: true, dynacast: true });
        this.videoClient = { type: 'livekit', room };
        const attachTrack = (track, participant) => {
          if (!track || !participant) return;
          if (track.kind === 'audio') {
            const audioEl = track.attach();
            audioEl.id = 'lk-aud-' + participant.identity;
            audioEl.style.display = 'none';
            grid.appendChild(audioEl);
            return;
          }
          if (track.kind !== 'video') return;
          const id = 'lk-' + participant.identity;
          let tile = document.getElementById(id);
          if (!tile) {
            tile = document.createElement('div');
            tile.id = id;
            tile.className = 'vs-video-tile';
            const lbl = document.createElement('div');
            lbl.className = 'vs-video-label';
            lbl.textContent = participant.name || participant.identity || 'مشارك';
            tile.appendChild(lbl);
            grid.appendChild(tile);
          }
          const el = track.attach();
          tile.prepend(el);
        };
        room.on(global.LivekitClient.RoomEvent.TrackSubscribed, attachTrack);
        room.on(global.LivekitClient.RoomEvent.LocalTrackPublished, (pub) => attachTrack(pub.track, room.localParticipant));
        room.on(global.LivekitClient.RoomEvent.TrackUnsubscribed, (t) => t.detach().forEach((el) => {
          const parent = el.parentElement;
          el.remove();
          if (parent && parent.classList.contains('vs-video-tile') && !parent.querySelector('video')) {
            parent.remove();
          }
        }));
        room.on(global.LivekitClient.RoomEvent.ParticipantDisconnected, (p) => {
          document.getElementById('lk-' + p.identity)?.remove();
          document.getElementById('lk-aud-' + p.identity)?.remove();
        });
        await room.connect(v.wsUrl, v.token);
        if (auth.role === 'host') {
          try {
            await room.localParticipant.setCameraEnabled(true);
          } catch (camErr) {
            if (this.opts.onFallbackNotice) {
              this.opts.onFallbackNotice('تم الانضمام للفيديو بدون كاميرا نشطة (يمكنك تفعيلها أو مشاركة الشاشة من الأسفل).');
            }
          }
        }
      } else if (v.provider === 'daily') {
        await loadScriptOnce('https://unpkg.com/@daily-co/daily-js');
        grid.innerHTML = '';
        const frame = global.DailyIframe.createFrame(grid, {
          iframeStyle: { width: '100%', height: '380px', border: '0', borderRadius: '14px' },
          showLeaveButton: false,
        });
        this.videoClient = { type: 'daily', frame };
        await frame.join({ url: v.roomUrl, token: v.token || undefined });
      } else if (v.provider === 'hms') {
        await loadScriptOnce('https://cdn.jsdelivr.net/npm/@100mslive/hms-video-store@0.12/dist/index.umd.min.js');
        const HmsSDK = global.HMSReactiveStore;
        const hms = new HmsSDK();
        const actions = hms.getActions();
        const store = hms.getStore();
        const selectPeers = global.selectPeers || hms.selectPeers;
        this.videoClient = { type: 'hms', actions, store, unsub: null };

        if (typeof selectPeers === 'function') {
          this.videoClient.unsub = store.subscribe((peers) => {
            if (!Array.isArray(peers)) return;
            const activeIds = new Set();
            peers.forEach((p) => {
              const id = 'hms-' + p.id;
              activeIds.add(id);
              let tile = document.getElementById(id);
              if (p.videoTrack) {
                if (!tile) {
                  tile = document.createElement('div');
                  tile.id = id;
                  tile.className = 'vs-video-tile';
                  const vid = document.createElement('video');
                  vid.autoplay = true;
                  vid.playsInline = true;
                  vid.muted = !!p.isLocal;
                  const lbl = document.createElement('div');
                  lbl.className = 'vs-video-label';
                  lbl.textContent = String(p.name || p.id || 'مشارك');
                  tile.appendChild(vid);
                  tile.appendChild(lbl);
                  grid.appendChild(tile);
                  try { actions.attachVideo(p.videoTrack, vid); } catch {}
                }
              } else if (tile) {
                tile.remove();
              }
            });
            Array.from(grid.querySelectorAll('.vs-video-tile')).forEach((el) => {
              if (el.id && el.id.startsWith('hms-') && !activeIds.has(el.id)) {
                el.remove();
              }
            });
          }, selectPeers);
        }

        try {
          await actions.join({
            authToken: v.authToken,
            userName: this.opts.userName,
            settings: { isAudioMuted: true, isVideoMuted: auth.role !== 'host' },
          });
        } catch (joinErr) {
          await actions.join({
            authToken: v.authToken,
            userName: this.opts.userName,
            settings: { isAudioMuted: true, isVideoMuted: true },
          });
          if (this.opts.onFallbackNotice) {
            this.opts.onFallbackNotice('تم الانضمام للفيديو بدون كاميرا نشطة.');
          }
        }
      } else if (v.provider === 'cf_sfu') {
        // Native RTCPeerConnection brokered via server proxy (zero secret exposure)
        const pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.cloudflare.com:3478' }] });
        this.videoClient = { type: 'cf_sfu', pc, sessionId: null };
        if (auth.role === 'host') {
          const camStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
          const track = camStream.getVideoTracks()[0];
          const trans = pc.addTransceiver(track, { direction: 'sendonly' });
          const offer = await pc.createOffer();
          await pc.setLocalDescription(offer);
          const sRes = await fetch(v.proxyUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: this.opts.csrfToken, code: auth.room_code, op: 'sessions_new', payload: { sessionDescription: { sdp: offer.sdp, type: 'offer' } } }),
          }).then((r) => r.json());
          if (sRes.success && sRes.data?.sessionId) {
            this.videoClient.sessionId = sRes.data.sessionId;
            await pc.setRemoteDescription(sRes.data.sessionDescription);
            await fetch(v.proxyUrl, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                csrf_token: this.opts.csrfToken, code: auth.room_code, op: 'tracks_new', sessionId: sRes.data.sessionId,
                payload: { tracks: [{ location: 'local', mid: trans.mid, trackName: 'host-cam' }] },
              }),
            });
            const tile = document.createElement('div');
            tile.className = 'vs-video-tile';
            const vid = document.createElement('video');
            vid.autoplay = true; vid.muted = true; vid.playsInline = true; vid.srcObject = camStream;
            const lbl = document.createElement('div');
            lbl.className = 'vs-video-label';
            lbl.textContent = String(this.opts.userName || 'المضيف');
            tile.appendChild(vid);
            tile.appendChild(lbl);
            grid.appendChild(tile);
          }
        }
      }
    }

    async toggleCamera(enabled) {
      if (!this.videoClient) return;
      if (this.videoClient.type === 'livekit') {
        await this.videoClient.room.localParticipant.setCameraEnabled(!!enabled);
      } else if (this.videoClient.type === 'hms') {
        await this.videoClient.actions.setLocalVideoEnabled(!!enabled);
      } else if (this.videoClient.type === 'daily') {
        this.videoClient.frame.setLocalVideo(!!enabled);
      }
    }

    async toggleScreenShare(enabled) {
      if (!this.videoClient) return;
      if (this.videoClient.type === 'livekit') {
        await this.videoClient.room.localParticipant.setScreenShareEnabled(!!enabled);
      } else if (this.videoClient.type === 'hms') {
        await this.videoClient.actions.setScreenShareEnabled(!!enabled);
      }
    }

    disconnect() {
      this.intentionalClose = true;
      clearInterval(this.pingTimer);
      clearTimeout(this.reconnectTimer);
      if (this.ws) { try { this.ws.close(1000, 'leave'); } catch {} }
      if (this.videoClient) {
        try {
          if (this.videoClient.type === 'livekit') this.videoClient.room.disconnect();
          if (this.videoClient.type === 'daily') this.videoClient.frame.destroy();
          if (this.videoClient.type === 'hms') this.videoClient.actions.leave();
          if (this.videoClient.type === 'cf_sfu') this.videoClient.pc.close();
        } catch {}
      }
    }
  }

  global.RoomTransportAdapter = RoomTransportAdapter;
})(window);

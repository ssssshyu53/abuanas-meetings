/**
 * Abu Anas Meetings Audio Engine: WebCodecs Opus (48kHz / 20ms) + ADPCM16 Fallback + DSP + MP3 Recorder
 */
(function (global) {
  'use strict';

  const OPUS_RATE = 48000;
  const OPUS_FRAME = 960;
  const ADPCM_RATE = 16000;
  const ADPCM_FRAME = 640;
  const STEP_TAB = [7,8,9,10,11,12,13,14,16,17,19,21,23,25,28,31,34,37,41,45,50,55,60,66,73,80,88,97,107,118,130,143,157,173,190,209,230,253,279,307,337,371,408,449,494,544,598,658,724,796,876,963,1060,1166,1282,1411,1552,1707,1878,2066,2272,2499,2749,3024,3327,3660,4026,4428,4871,5358,5894,6484,7132,7845,8630,9493,10442,11487,12635,13899,15289,16818,18500,20350,22385,24623,27086,29794,32767];
  const IDX_TAB = [-1,-1,-1,-1,2,4,6,8,-1,-1,-1,-1,2,4,6,8];

  class Resampler {
    constructor(target) { this.target = target; this.pos = 0; }
    push(input, srcRate) {
      if (srcRate === this.target) return input;
      const ratio = srcRate / this.target;
      const out = [];
      let idx = this.pos;
      while (idx + 1 < input.length) {
        const i = idx | 0;
        const f = idx - i;
        out.push(input[i] * (1 - f) + input[i + 1] * f);
        idx += ratio;
      }
      this.pos = idx - input.length;
      if (this.pos < 0) this.pos = 0;
      return new Float32Array(out);
    }
  }

  function encodeAdpcm(pcm, seq) {
    const pay = new Uint8Array(6 + (pcm.length >> 1));
    pay[2] = 0x02;
    pay[3] = (seq >> 8) & 0xFF;
    pay[4] = seq & 0xFF;
    let prev = pcm[0] | 0, stepIdx = 0;
    for (let i = 0; i < pcm.length; i++) {
      let diff = (pcm[i] | 0) - prev;
      let sign = diff < 0 ? 8 : 0;
      if (diff < 0) diff = -diff;
      let step = STEP_TAB[stepIdx], delta = 0, vp = step >> 3;
      if (diff >= step) { delta |= 4; diff -= step; vp += step; }
      step >>= 1;
      if (diff >= step) { delta |= 2; diff -= step; vp += step; }
      step >>= 1;
      if (diff >= step) { delta |= 1; vp += step; }
      if (sign) prev -= vp; else prev += vp;
      prev = Math.max(-32768, Math.min(32767, prev));
      const nib = (sign | delta) & 0x0F;
      stepIdx = Math.max(0, Math.min(88, stepIdx + IDX_TAB[nib]));
      const b = 6 + (i >> 1);
      if ((i & 1) === 0) pay[b] = nib << 4; else pay[b] |= nib;
    }
    return pay.buffer;
  }

  function decodeAdpcm(u8) {
    const n = (u8.length - 6) * 2;
    const out = new Float32Array(n);
    let prev = 0, stepIdx = 0;
    for (let i = 0; i < n; i++) {
      const b = u8[6 + (i >> 1)];
      const nib = (i & 1) === 0 ? ((b >> 4) & 0x0F) : (b & 0x0F);
      const step = STEP_TAB[stepIdx];
      let vp = step >> 3;
      if (nib & 4) vp += step;
      if (nib & 2) vp += step >> 1;
      if (nib & 1) vp += step >> 2;
      if (nib & 8) prev -= vp; else prev += vp;
      prev = Math.max(-32768, Math.min(32767, prev));
      stepIdx = Math.max(0, Math.min(88, stepIdx + IDX_TAB[nib]));
      out[i] = prev / 32768;
    }
    return out;
  }

  class VoxAudioEngine {
    constructor(opts) {
      this.opts = opts || {};
      this.audioCtx = null;
      this.masterGain = null;
      this.playbackWorkletNode = null;
      this.micStream = null;
      this.micProc = null;
      this.opusEncoder = null;
      this.opusSupported = typeof AudioEncoder === 'function' && typeof AudioDecoder === 'function';
      this.decoders = new Map();
      this.opusQ = [];
      this.adpcmQ = [];
      this.resOpus = new Resampler(OPUS_RATE);
      this.resAdpcm = new Resampler(ADPCM_RATE);
      this.encTs = 0;
      this.seq = 0;
      this.bitrate = 32000;
      this.vadHang = 0;
      this.noiseFloor = 0.002;
      this.wasSpeaking = false;
      this.muted = true;
      this.volume = 1.0;
      this.mp3Rec = null;
      this.mp3Paused = false;
      this.mp3Buf = [];
      this.mp3LastFlush = Date.now();
      this.mp3Queue = Promise.resolve();
    }

    async ensureContext() {
      if (!this.audioCtx) {
        const AC = global.AudioContext || global.webkitAudioContext;
        this.audioCtx = new AC({ sampleRate: OPUS_RATE, latencyHint: 'interactive' });
        this.masterGain = this.audioCtx.createGain();
        this.masterGain.gain.value = this.volume;
        this.masterGain.connect(this.audioCtx.destination);
        await this._initPlaybackQueue();
      }
      if (this.audioCtx.state === 'suspended') {
        await this.audioCtx.resume();
      }
    }

    async _initPlaybackQueue() {
      if (!this.audioCtx.audioWorklet) return;
      const code = `
        class PlayProc extends AudioWorkletProcessor {
          constructor() {
            super();
            this.cap = 48000;
            this.ring = new Float32Array(this.cap);
            this.r = 0; this.w = 0; this.cnt = 0;
            this.port.onmessage = (e) => {
              const s = e.data;
              for (let i = 0; i < s.length; i++) {
                if (this.cnt >= this.cap) { this.r = (this.r + 1) % this.cap; this.cnt--; }
                this.ring[this.w] = s[i];
                this.w = (this.w + 1) % this.cap;
                this.cnt++;
              }
            };
          }
          process(_, outs) {
            const ch = outs[0][0];
            for (let i = 0; i < ch.length; i++) {
              if (this.cnt > 0) {
                ch[i] = this.ring[this.r];
                this.r = (this.r + 1) % this.cap;
                this.cnt--;
              } else {
                ch[i] = 0;
              }
            }
            return true;
          }
        }
        registerProcessor('vs-play-proc', PlayProc);
      `;
      const blobUrl = URL.createObjectURL(new Blob([code], { type: 'application/javascript' }));
      await this.audioCtx.audioWorklet.addModule(blobUrl);
      this.playbackWorkletNode = new AudioWorkletNode(this.audioCtx, 'vs-play-proc');
      this.playbackWorkletNode.connect(this.masterGain);
      URL.revokeObjectURL(blobUrl);
    }

    setVolume(v) {
      this.volume = Math.max(0, Math.min(1, Number(v)));
      if (this.masterGain && this.audioCtx) {
        this.masterGain.gain.setTargetAtTime(this.volume, this.audioCtx.currentTime, 0.03);
      }
    }

    setBitrate(bps) {
      this.bitrate = Math.max(16000, Math.min(64000, Number(bps) || 32000));
      if (this.opusEncoder && this.opusEncoder.state === 'configured') {
        try { this.opusEncoder.configure({ codec: 'opus', sampleRate: OPUS_RATE, numberOfChannels: 1, bitrate: this.bitrate }); } catch {}
      }
    }

    _ensureEncoder() {
      if (!this.opusSupported) return null;
      if (this.opusEncoder && this.opusEncoder.state === 'configured') return this.opusEncoder;
      this.opusEncoder = new AudioEncoder({
        output: (chunk) => {
          const buf = new Uint8Array(6 + chunk.byteLength);
          buf[2] = 0x01;
          buf[3] = (this.seq >> 8) & 0xFF;
          buf[4] = this.seq & 0xFF;
          buf[5] = chunk.type === 'key' ? 1 : 0;
          this.seq = (this.seq + 1) & 0xFFFF;
          chunk.copyTo(buf.subarray(6));
          if (this.opts.onPacket) this.opts.onPacket(buf.buffer);
        },
        error: () => {},
      });
      this.opusEncoder.configure({ codec: 'opus', sampleRate: OPUS_RATE, numberOfChannels: 1, bitrate: this.bitrate });
      return this.opusEncoder;
    }

    async startMic(enableMp3 = false) {
      await this.ensureContext();
      if (!this.micStream) {
        this.micStream = await navigator.mediaDevices.getUserMedia({
          audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true },
        });
        const src = this.audioCtx.createMediaStreamSource(this.micStream);
        const hp = this.audioCtx.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 95;
        const pk = this.audioCtx.createBiquadFilter(); pk.type = 'peaking'; pk.frequency.value = 2850; pk.gain.value = 2.5;
        const lp = this.audioCtx.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = 7800;
        const cmp = this.audioCtx.createDynamicsCompressor();
        cmp.threshold.value = -24; cmp.ratio.value = 3.2;

        const proc = this.audioCtx.createScriptProcessor(1024, 1, 1);
        proc.onaudioprocess = (ev) => {
          if (this.muted) return;
          const inp = ev.inputBuffer.getChannelData(0);
          if (enableMp3 && !this.mp3Paused && this.mp3Rec) this._pushMp3Samples(inp);
          this._processCapture(inp, this.audioCtx.sampleRate);
        };

        const zero = this.audioCtx.createGain();
        zero.gain.value = 0;
        src.connect(hp); hp.connect(pk); pk.connect(lp); lp.connect(cmp); cmp.connect(proc); proc.connect(zero); zero.connect(this.audioCtx.destination);
        this.micProc = proc;
      }
      if (enableMp3 && !this.mp3Rec && typeof global.lamejs !== 'undefined') {
        this.mp3Rec = new global.lamejs.Mp3Encoder(1, 24000, 64);
        this.mp3Resampler = new Resampler(24000);
      }
      this.muted = false;
    }

    setMuted(m) {
      this.muted = !!m;
      if (this.muted && this.wasSpeaking) {
        this.wasSpeaking = false;
        if (this.opts.onSpeakingIdle) this.opts.onSpeakingIdle();
      }
    }

    stopMic() {
      this.setMuted(true);
      if (this.micProc) { try { this.micProc.disconnect(); } catch {} this.micProc = null; }
      if (this.micStream) { this.micStream.getTracks().forEach((t) => t.stop()); this.micStream = null; }
    }

    _processCapture(chunk, srcRate) {
      const enc = this._ensureEncoder();
      if (enc) {
        const res = this.resOpus.push(chunk, srcRate);
        for (let i = 0; i < res.length; i++) this.opusQ.push(res[i]);
        while (this.opusQ.length >= OPUS_FRAME) {
          const frame = new Float32Array(this.opusQ.splice(0, OPUS_FRAME));
          let sq = 0;
          for (let i = 0; i < frame.length; i++) sq += frame[i] * frame[i];
          const rms = Math.sqrt(sq / frame.length);
          if (rms < 0.006) this.noiseFloor = Math.min(0.007, this.noiseFloor * 0.96 + rms * 0.04);
          if (rms >= Math.max(0.0035, this.noiseFloor * 1.8)) this.vadHang = 14;
          else if (this.vadHang > 0) this.vadHang--;

          if (this.vadHang > 0) {
            this.wasSpeaking = true;
            if (this.opts.onLocalSpeaking) this.opts.onLocalSpeaking(true);
            for (let i = 0; i < frame.length; i++) frame[i] = Math.tanh(frame[i] * 1.05);
            try {
              const ad = new AudioData({ format: 'f32', sampleRate: OPUS_RATE, numberOfFrames: OPUS_FRAME, numberOfChannels: 1, timestamp: this.encTs, data: frame });
              this.encTs += 20000;
              enc.encode(ad);
              ad.close();
            } catch {}
          } else if (this.wasSpeaking) {
            this.wasSpeaking = false;
            if (this.opts.onLocalSpeaking) this.opts.onLocalSpeaking(false);
            if (this.opts.onSpeakingIdle) this.opts.onSpeakingIdle();
          }
        }
      } else {
        const res = this.resAdpcm.push(chunk, srcRate);
        for (let i = 0; i < res.length; i++) this.adpcmQ.push(res[i]);
        while (this.adpcmQ.length >= ADPCM_FRAME) {
          const sl = this.adpcmQ.splice(0, ADPCM_FRAME);
          const i16 = new Int16Array(ADPCM_FRAME);
          let sq = 0;
          for (let i = 0; i < ADPCM_FRAME; i++) {
            sq += sl[i] * sl[i];
            const v = Math.tanh(sl[i] * 1.05);
            i16[i] = v < 0 ? (v * 32768) | 0 : (v * 32767) | 0;
          }
          if (Math.sqrt(sq / ADPCM_FRAME) >= 0.0035) this.vadHang = 8;
          else if (this.vadHang > 0) this.vadHang--;
          if (this.vadHang > 0) {
            this.wasSpeaking = true;
            this.seq = (this.seq + 1) & 0xFFFF;
            if (this.opts.onPacket) this.opts.onPacket(encodeAdpcm(i16, this.seq));
          } else if (this.wasSpeaking) {
            this.wasSpeaking = false;
            if (this.opts.onSpeakingIdle) this.opts.onSpeakingIdle();
          }
        }
      }
    }

    _pushMp3Samples(floatChunk) {
      if (!this.mp3Rec) return;
      const rs = this.mp3Resampler.push(floatChunk, this.audioCtx.sampleRate);
      const i16 = new Int16Array(rs.length);
      for (let i = 0; i < rs.length; i++) {
        const v = Math.max(-1, Math.min(1, rs[i]));
        i16[i] = v < 0 ? (v * 32768) | 0 : (v * 32767) | 0;
      }
      const enc = this.mp3Rec.encodeBuffer(i16);
      if (enc && enc.length > 0) this.mp3Buf.push(enc);
      const now = Date.now();
      if ((now - this.mp3LastFlush) >= 5000 && this.mp3Buf.length > 0) {
        this.flushMp3(false);
      }
    }

    flushMp3(final = false) {
      if (!this.mp3Rec) return this.mp3Queue;
      if (final) {
        const tail = this.mp3Rec.flush();
        if (tail && tail.length > 0) this.mp3Buf.push(tail);
      }
      if (this.mp3Buf.length === 0) return this.mp3Queue;
      const parts = this.mp3Buf.splice(0, this.mp3Buf.length);
      this.mp3LastFlush = Date.now();
      const blob = new Blob(parts, { type: 'audio/mpeg' });
      if (this.opts.onMp3Chunk && blob.size > 0) {
        this.mp3Queue = this.mp3Queue.then(() => this.opts.onMp3Chunk(blob)).catch(() => {});
      }
      return this.mp3Queue;
    }

    decodePacket(arrBuf) {
      if (!this.audioCtx || !this.playbackWorkletNode || arrBuf.byteLength < 8) return;
      const u8 = new Uint8Array(arrBuf);
      const speakerId = (u8[0] << 8) | u8[1];
      const codec = u8[2];
      if (codec === 0x02) {
        const pcm = decodeAdpcm(u8);
        const up = new Float32Array(pcm.length * 3);
        for (let i = 0; i < pcm.length; i++) { up[i*3] = pcm[i]; up[i*3+1] = pcm[i]; up[i*3+2] = pcm[i]; }
        this.playbackWorkletNode.port.postMessage(up);
        return;
      }
      if (codec === 0x01 && this.opusSupported) {
        let dec = this.decoders.get(speakerId);
        if (!dec) {
          dec = new AudioDecoder({
            output: (ad) => {
              const out = new Float32Array(ad.numberOfFrames);
              ad.copyTo(out, { planeIndex: 0, format: 'f32' });
              this.playbackWorkletNode.port.postMessage(out);
              ad.close();
            },
            error: () => {},
          });
          dec.configure({ codec: 'opus', sampleRate: OPUS_RATE, numberOfChannels: 1 });
          this.decoders.set(speakerId, dec);
        }
        if (dec.state === 'configured') {
          try {
            dec.decode(new EncodedAudioChunk({
              type: (u8[5] & 1) ? 'key' : 'delta',
              timestamp: Date.now() * 1000,
              data: u8.subarray(6),
            }));
          } catch {}
        }
      }
    }
  }

  global.VoxAudioEngine = VoxAudioEngine;
})(window);

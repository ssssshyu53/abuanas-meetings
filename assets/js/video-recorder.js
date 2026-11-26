/**
 * Abu Anas Meetings — Full Meeting Video & Mixed Audio Recorder
 * Captures meeting screen/tab video + mixed microphone & system audio via MediaRecorder
 */
(function (global) {
  'use strict';

  class AbuAnasVideoRecorder {
    constructor(opts) {
      this.opts = opts || {};
      this.recorder = null;
      this.displayStream = null;
      this.micStream = null;
      this.audioCtx = null;
      this.chunks = [];
      this.totalBytes = 0;
      this.startedAt = 0;
      this.ext = 'webm';
      this.mimeType = 'video/webm';
      this.active = false;
      this._pendingUploads = Promise.resolve();
    }

    isSupported() {
      return !!(navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia && global.MediaRecorder);
    }

    isRecording() {
      return this.active;
    }

    getExt() {
      return this.ext;
    }

    getElapsedSeconds() {
      return this.startedAt ? Math.max(1, Math.round((Date.now() - this.startedAt) / 1000)) : 0;
    }

    _pickMime() {
      const candidates = [
        { mime: 'video/mp4;codecs=avc1,mp4a.40.2', ext: 'mp4' },
        { mime: 'video/webm;codecs=vp9,opus', ext: 'webm' },
        { mime: 'video/webm;codecs=vp8,opus', ext: 'webm' },
        { mime: 'video/webm', ext: 'webm' },
      ];
      for (const c of candidates) {
        if (global.MediaRecorder.isTypeSupported && global.MediaRecorder.isTypeSupported(c.mime)) {
          return c;
        }
      }
      return { mime: '', ext: 'webm' };
    }

    async start() {
      if (this.active) return true;
      if (!this.isSupported()) {
        throw new Error('المتصفح الحالي لا يدعم تسجيل الفيديو');
      }

      const displayStream = await navigator.mediaDevices.getDisplayMedia({
        video: { frameRate: { ideal: 30, max: 30 } },
        audio: true,
        preferCurrentTab: true,
      });
      this.displayStream = displayStream;

      const videoTrack = displayStream.getVideoTracks()[0];
      if (!videoTrack) {
        throw new Error('لم يتم اختيار شاشة أو تبويب للتسجيل');
      }

      const finalStream = new MediaStream();
      finalStream.addTrack(videoTrack);

      try {
        const AudioCtx = global.AudioContext || global.webkitAudioContext;
        const ctx = new AudioCtx();
        this.audioCtx = ctx;
        const dest = ctx.createMediaStreamDestination();
        let hasAudio = false;

        if (displayStream.getAudioTracks().length > 0) {
          const tabSrc = ctx.createMediaStreamSource(new MediaStream([displayStream.getAudioTracks()[0]]));
          tabSrc.connect(dest);
          hasAudio = true;
        }
        try {
          const micStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
          this.micStream = micStream;
          if (micStream.getAudioTracks().length > 0) {
            const micSrc = ctx.createMediaStreamSource(micStream);
            micSrc.connect(dest);
            hasAudio = true;
          }
        } catch {}

        if (hasAudio && dest.stream.getAudioTracks().length > 0) {
          finalStream.addTrack(dest.stream.getAudioTracks()[0]);
        }
      } catch {}

      const picked = this._pickMime();
      this.mimeType = picked.mime || 'video/webm';
      this.ext = picked.ext;
      this.chunks = [];
      this.totalBytes = 0;
      this.startedAt = Date.now();
      this._pendingUploads = Promise.resolve();

      const recOpts = picked.mime ? { mimeType: picked.mime, videoBitsPerSecond: 1500000 } : { videoBitsPerSecond: 1500000 };
      const rec = new global.MediaRecorder(finalStream, recOpts);
      this.recorder = rec;
      this.active = true;

      videoTrack.onended = () => {
        if (this.active && this.opts.onUserStopped) {
          this.opts.onUserStopped();
        }
      };

      rec.ondataavailable = (ev) => {
        if (!ev.data || ev.data.size === 0) return;
        if (this.totalBytes < 160 * 1024 * 1024) {
          this.chunks.push(ev.data);
          this.totalBytes += ev.data.size;
        }
        if (typeof this.opts.onChunk === 'function') {
          this._pendingUploads = this._pendingUploads.then(() => this.opts.onChunk(ev.data, this.ext)).catch(() => {});
        }
      };

      rec.start(5000);
      return true;
    }

    async stop() {
      if (!this.active) return { blob: null, duration: 0, ext: this.ext };
      this.active = false;
      const duration = this.getElapsedSeconds();

      await new Promise((resolve) => {
        if (!this.recorder || this.recorder.state === 'inactive') return resolve();
        this.recorder.onstop = () => resolve();
        try { this.recorder.stop(); } catch { resolve(); }
      });

      await this._pendingUploads;
      this._cleanupStreams();

      const blob = this.chunks.length ? new Blob(this.chunks, { type: this.mimeType }) : null;
      return { blob, duration, ext: this.ext };
    }

    downloadLocal(blob, filename) {
      if (!blob) return;
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename || `abuanas-meeting-${Date.now()}.${this.ext}`;
      document.body.appendChild(a);
      a.click();
      setTimeout(() => {
        URL.revokeObjectURL(url);
        a.remove();
      }, 1500);
    }

    _cleanupStreams() {
      try { this.displayStream?.getTracks().forEach((t) => t.stop()); } catch {}
      try { this.micStream?.getTracks().forEach((t) => t.stop()); } catch {}
      try { this.audioCtx?.close(); } catch {}
      this.displayStream = null;
      this.micStream = null;
      this.audioCtx = null;
      this.recorder = null;
    }
  }

  global.AbuAnasVideoRecorder = AbuAnasVideoRecorder;
})(window);

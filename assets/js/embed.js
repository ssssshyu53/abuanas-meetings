/**
 * Abu Anas Meetings — Drop-in External Embed SDK (Jitsi-style)
 * Usage:
 *   const meet = new AbuAnasMeetings('#container', {
 *     baseUrl: 'https://your-meetings-host',
 *     roomCode: 'ABC123',
 *     displayName: 'أحمد علي',
 *     theme: 'light',
 *     height: '650px',
 *     onJoined: () => {},
 *     onCount: (count) => {},
 *     onLeft: () => {},
 *     onEnded: () => {}
 *   });
 */
(function (global) {
  'use strict';

  class AbuAnasMeetings {
    constructor(target, options) {
      this.el = typeof target === 'string' ? document.querySelector(target) : target;
      if (!this.el) throw new Error('AbuAnasMeetings: target container not found');
      this.opts = options || {};
      this.baseUrl = String(this.opts.baseUrl || '').replace(/\/+$/, '');
      this.iframe = null;
      this._onMessage = this._handleMessage.bind(this);
      this._mount();
    }

    _mount() {
      const params = new URLSearchParams();
      params.set('code', String(this.opts.roomCode || '').trim());
      params.set('embed', '1');
      if (this.opts.displayName) params.set('name', String(this.opts.displayName));
      if (this.opts.theme) params.set('theme', String(this.opts.theme));

      const iframe = document.createElement('iframe');
      iframe.src = `${this.baseUrl}/room.php?${params.toString()}`;
      iframe.style.width = this.opts.width || '100%';
      iframe.style.height = this.opts.height || '640px';
      iframe.style.border = '0';
      iframe.style.borderRadius = '16px';
      iframe.style.overflow = 'hidden';
      iframe.allow = 'microphone; camera; display-capture; autoplay; clipboard-write; fullscreen';
      iframe.setAttribute('allowfullscreen', 'true');

      this.el.innerHTML = '';
      this.el.appendChild(iframe);
      this.iframe = iframe;
      global.addEventListener('message', this._onMessage);
    }

    _handleMessage(ev) {
      const data = ev.data;
      if (!data || data.source !== 'abuanas-meetings') return;
      if (data.event === 'joined' && typeof this.opts.onJoined === 'function') this.opts.onJoined(data);
      if (data.event === 'count' && typeof this.opts.onCount === 'function') this.opts.onCount(data.count, data);
      if (data.event === 'left' && typeof this.opts.onLeft === 'function') this.opts.onLeft(data);
      if (data.event === 'ended' && typeof this.opts.onEnded === 'function') this.opts.onEnded(data);
    }

    dispose() {
      global.removeEventListener('message', this._onMessage);
      if (this.iframe && this.iframe.parentNode) this.iframe.parentNode.removeChild(this.iframe);
      this.iframe = null;
    }
  }

  global.AbuAnasMeetings = AbuAnasMeetings;
})(window);

/**
 * Abu Anas Meetings Normalized Interactive Whiteboard & Slides Engine
 */
(function (global) {
  'use strict';

  class VoxWhiteboard {
    constructor(opts) {
      this.canvas = opts.canvas;
      this.slideImg = opts.slideImg;
      this.laserEl = opts.laserEl;
      this.onStroke = opts.onStroke || (() => {});
      this.onCursor = opts.onCursor || (() => {});
      this.onClear = opts.onClear || (() => {});
      this.ctx = this.canvas ? this.canvas.getContext('2d') : null;
      this.canDraw = !!opts.canDraw;
      this.tool = 'pen';
      this.color = '#2563EB';
      this.width = 3;
      this.strokes = [];
      this.slides = [];
      this.slideIndex = 0;
      this.activeStroke = null;
      this.lastLaserAt = 0;
      if (this.canvas) this._bind();
    }

    setCanDraw(flag) { this.canDraw = !!flag; }
    setTool(t) { this.tool = t; }
    setColor(c) { this.color = c; }

    resize() {
      if (!this.canvas) return;
      const rect = this.canvas.getBoundingClientRect();
      if (!rect.width || !rect.height) return;
      const dpr = Math.min(2, global.devicePixelRatio || 1);
      this.canvas.width = Math.round(rect.width * dpr);
      this.canvas.height = Math.round(rect.height * dpr);
      this.render();
    }

    syncState(wb) {
      if (!wb) return;
      this.strokes = Array.isArray(wb.strokes) ? wb.strokes.slice() : [];
      this.slides = Array.isArray(wb.slides) ? wb.slides.slice() : [];
      this.slideIndex = Number(wb.slideIndex || 0);
      this._updateSlide();
      this.resize();
    }

    addRemoteStroke(stroke) {
      if (!stroke || !Array.isArray(stroke.points)) return;
      this.strokes.push(stroke);
      if (this.strokes.length > 300) this.strokes.shift();
      this.render();
    }

    clearLocal() {
      this.strokes = [];
      this.render();
    }

    setSlides(slides, idx) {
      if (Array.isArray(slides)) this.slides = slides;
      this.slideIndex = Math.max(0, Math.min(Math.max(0, this.slides.length - 1), Number(idx || 0)));
      this._updateSlide();
    }

    showRemoteCursor(x, y, active) {
      if (!this.laserEl) return;
      if (!active) {
        this.laserEl.classList.add('hidden');
        return;
      }
      this.laserEl.classList.remove('hidden');
      this.laserEl.style.left = (x * 100).toFixed(2) + '%';
      this.laserEl.style.top = (y * 100).toFixed(2) + '%';
    }

    _updateSlide() {
      if (!this.slideImg) return;
      const url = this.slides[this.slideIndex] || '';
      if (url) {
        this.slideImg.src = url;
        this.slideImg.classList.remove('hidden');
      } else {
        this.slideImg.src = '';
        this.slideImg.classList.add('hidden');
      }
    }

    _normPt(ev) {
      const r = this.canvas.getBoundingClientRect();
      return [
        Math.max(0, Math.min(1, (ev.clientX - r.left) / r.width)),
        Math.max(0, Math.min(1, (ev.clientY - r.top) / r.height)),
      ];
    }

    _bind() {
      global.addEventListener('resize', () => this.resize());
      this.canvas.addEventListener('pointerdown', (ev) => {
        if (!this.canDraw) return;
        const [x, y] = this._normPt(ev);
        if (this.tool === 'laser') {
          this.showRemoteCursor(x, y, true);
          this.onCursor(x, y, true);
          return;
        }
        this.canvas.setPointerCapture(ev.pointerId);
        this.activeStroke = {
          tool: this.tool,
          color: this.tool === 'eraser' ? '#FFFFFF' : this.color,
          width: this.tool === 'highlighter' ? 12 : (this.tool === 'eraser' ? 18 : this.width),
          points: [[x, y]],
        };
      });

      this.canvas.addEventListener('pointermove', (ev) => {
        if (!this.canDraw) return;
        const [x, y] = this._normPt(ev);
        if (this.tool === 'laser' && (ev.buttons & 1)) {
          this.showRemoteCursor(x, y, true);
          const now = Date.now();
          if (now - this.lastLaserAt > 45) {
            this.lastLaserAt = now;
            this.onCursor(x, y, true);
          }
          return;
        }
        if (!this.activeStroke) return;
        this.activeStroke.points.push([x, y]);
        this.render(this.activeStroke);
      });

      const finish = () => {
        if (this.tool === 'laser') {
          this.showRemoteCursor(0, 0, false);
          this.onCursor(0, 0, false);
          return;
        }
        if (!this.activeStroke) return;
        if (this.activeStroke.points.length > 0) {
          this.strokes.push(this.activeStroke);
          if (this.strokes.length > 300) this.strokes.shift();
          this.onStroke(this.activeStroke);
        }
        this.activeStroke = null;
        this.render();
      };
      this.canvas.addEventListener('pointerup', finish);
      this.canvas.addEventListener('pointercancel', finish);
    }

    render(extra = null) {
      if (!this.ctx || !this.canvas) return;
      const w = this.canvas.width, h = this.canvas.height;
      this.ctx.clearRect(0, 0, w, h);
      const list = extra ? [...this.strokes, extra] : this.strokes;
      for (const s of list) {
        if (!s.points || !s.points.length) continue;
        this.ctx.save();
        this.ctx.lineCap = 'round';
        this.ctx.lineJoin = 'round';
        this.ctx.globalAlpha = s.tool === 'highlighter' ? 0.35 : 1;
        this.ctx.strokeStyle = s.color || '#2563EB';
        this.ctx.lineWidth = (s.width || 3) * (w / 960);
        this.ctx.beginPath();
        const p0 = s.points[0];
        this.ctx.moveTo(p0[0] * w, p0[1] * h);
        for (let i = 1; i < s.points.length; i++) {
          this.ctx.lineTo(s.points[i][0] * w, s.points[i][1] * h);
        }
        if (s.points.length === 1) this.ctx.lineTo(p0[0] * w + 0.5, p0[1] * h + 0.5);
        this.ctx.stroke();
        this.ctx.restore();
      }
    }
  }

  global.VoxWhiteboard = VoxWhiteboard;
})(window);

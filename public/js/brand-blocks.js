/* Block wordmarks that let go and rebuild.

   The logo piece of the Northlatch estate's blocks.js, without the estate's X: here the name is the
   whole mark. Same letter face, same timing, same easing: every so often the letters let go, drop
   below the baseline and come back up as the next word of the sequence (or as themselves).

     <span class="logo" data-logo="gridframes" data-logo-split="4"><img src="logo.svg" alt="…"></span>

   data-logo        the words, comma separated
   data-logo-split  letters before this index take --logo-accent, the rest --logo-word

   The <img> is the finished frame and stays for anyone without script. Nothing moves under
   prefers-reduced-motion, off screen or in a hidden tab, and nothing here makes a network request. */
(function () {
  "use strict";

  var reduce = window.matchMedia("(prefers-reduced-motion: reduce)");

  /* 5 x 9 letter face; rows 7-8 hold descenders. Identical to scripts/brand/block_logo.py. */
  var FACE = {
    a: "...........XXX.....X.XXXXX...X.XXXX..........",
    c: "...........XXX.X...XX....X...X.XXX...........",
    d: "....X....X.XXXXX...XX...XX...X.XXXX..........",
    e: "...........XXX.X...XXXXXXX.....XXX...........",
    f: "..XX..X..XXXXX..X....X....X....X.............",
    g: "...........XXXXX...XX...X.XXXX....XX...X.XXX.",
    h: "X....X....X.XX.XX..XX...XX...XX...X..........",
    i: "..X........XX....X....X....X...XXX...........",
    k: "X....X....X..X.X.X..XX...X.X..X..X...........",
    l: ".XX....X....X....X....X....X...XXX...........",
    m: "..........XX.X.X.X.XX.X.XX.X.XX.X.X..........",
    n: "..........X.XX.XX..XX...XX...XX...X..........",
    o: "...........XXX.X...XX...XX...X.XXX...........",
    p: "..........XXXX.X...XX...XX...XXXXX.X....X....",
    r: "..........X.XX.XX..XX....X....X..............",
    s: "...........XXXXX.....XXX.....XXXXX...........",
    t: ".X....X...XXXX..X....X....X..X..XX...........",
    u: "..........X...XX...XX...XX..XX.XX.X..........",
    w: "..........X...XX...XX.X.XXX.XXX...X..........",
    x: "..........X...X.X.X...X...X.X.X...X.........."
  };
  function wordCells(word) {
    var out = [], x0 = 0, i, r, c;
    for (i = 0; i < word.length; i++) {
      var f = FACE[word[i]];
      if (f) for (r = 0; r < 9; r++) for (c = 0; c < 5; c++) if (f.charAt(r * 5 + c) === "X") out.push([x0 + c, r, i]);
      x0 += 6;
    }
    return out;
  }

  function css(el, name, fallback) {
    var v = getComputedStyle(el).getPropertyValue(name).trim();
    return v || fallback;
  }

  var all = [], raf = 0, last = 0;
  function loop(now) {
    raf = 0;
    var dt = Math.min(48, now - (last || now)); last = now;
    var any = false;
    for (var i = 0; i < all.length; i++) {
      var p = all[i];
      if (!p.live || !p.onScreen || document.hidden) continue;
      var more = p.step(dt);
      p.draw(now);
      if (more) any = true; else p.live = false;
    }
    if (any) raf = requestAnimationFrame(loop); else last = 0;
  }
  function wake(p) {
    if (reduce.matches) { p.live = false; p.finish(); p.draw(0); return; }
    p.live = true;
    if (!raf && !document.hidden) raf = requestAnimationFrame(loop);
  }
  var io = "IntersectionObserver" in window ? new IntersectionObserver(function (es) {
    es.forEach(function (e) {
      var p = e.target.__blocks; if (!p) return;
      p.onScreen = e.isIntersecting;
      if (e.isIntersecting) wake(p);
    });
  }, { threshold: 0.15 }) : null;

  function Logo(el) {
    this.el = el;
    this.seq = (el.getAttribute("data-logo") || "").split(",").map(function (w) { return w.trim(); }).filter(Boolean);
    this.split = parseInt(el.getAttribute("data-logo-split") || "0", 10) || 0;
    this.stage = 0;
    var c = document.createElement("canvas");
    c.setAttribute("aria-hidden", "true");
    c.style.width = "100%"; c.style.height = "100%";
    el.appendChild(c);
    this.canvas = c;
    var img = el.querySelector("img"); if (img) img.style.display = "none";
    this.layout();
    el.__blocks = this;
    this.onScreen = !io;
    all.push(this);
    if (io) io.observe(el); else wake(this);
  }
  Logo.prototype.layout = function () {
    var r = this.el.getBoundingClientRect();
    this.w = Math.max(8, Math.round(r.width));
    this.h = Math.max(8, Math.round(r.height));
    var dpr = Math.min(2, window.devicePixelRatio || 1);
    this.canvas.width = Math.round(this.w * dpr);
    this.canvas.height = Math.round(this.h * dpr);
    this.g = this.canvas.getContext("2d");
    this.g.setTransform(dpr, 0, 0, dpr, 0, 0);
    this.accent = css(this.el, "--logo-accent", getComputedStyle(this.el).color);
    this.ink = css(this.el, "--logo-word", this.accent);
    this.cell = this.h / 9;
    this.blocks = wordCells(this.seq[this.stage % this.seq.length]).map(function (c) {
      return { x: c[0], y: c[1], fx: c[0], fy: c[1], tx: c[0], ty: c[1], k: c[2], t: 1, d: 0, a: 1, keep: true };
    });
    this.state = "hold"; this.until = this.until || 5200 + Math.random() * 1800; this.clock = 0;
    this.draw(0);
  };
  Logo.prototype.finish = function () {
    this.blocks.forEach(function (b) { b.x = b.tx; b.y = b.ty; b.t = 1; b.a = 1; });
  };
  Logo.prototype.morph = function () {
    this.stage++;
    var next = wordCells(this.seq[this.stage % this.seq.length]);
    var cur = this.blocks.filter(function (b) { return b.a > 0; });
    cur.sort(function (p, q) { return p.x - q.x || p.y - q.y; });
    next.sort(function (p, q) { return p[0] - q[0] || p[1] - q[1]; });
    var out = [], n = Math.max(cur.length, next.length);
    for (var i = 0; i < n; i++) {
      var from = cur[Math.floor(i * cur.length / n)] || cur[cur.length - 1];
      var to = i < next.length ? next[i] : null;
      /* extra blocks fall away; missing ones rise from below the baseline */
      out.push({ fx: from.x, fy: from.y, x: from.x, y: from.y,
        tx: to ? to[0] : from.x, ty: to ? to[1] : 12, k: to ? to[2] : from.k, keep: !!to,
        t: 0, d: from.x * 22 + Math.random() * 120, a: 1 });
    }
    this.blocks = out;
    this.state = "move";
  };
  Logo.prototype.step = function (dt) {
    this.clock += dt;
    if (this.state === "hold") {
      if (this.clock > this.until) { this.clock = 0; this.morph(); }
      return true;
    }
    var moving = 0;
    for (var i = 0; i < this.blocks.length; i++) {
      var b = this.blocks[i];
      if (b.d > 0) { b.d -= dt; moving++; continue; }
      if (b.t < 1) {
        b.t = Math.min(1, b.t + dt / 900); moving++;
        var e = b.t;
        /* fall first, then come back up: a dip below the baseline between the two words, so each
           letter is seen to let go and be rebuilt */
        var dip = Math.sin(Math.PI * e) * 5.5;
        b.x = b.fx + (b.tx - b.fx) * (e * e * (3 - 2 * e));
        b.y = b.fy + (b.ty - b.fy) * e + dip;
        if (!b.keep) b.a = 1 - e;
      }
    }
    if (!moving) {
      this.blocks = this.blocks.filter(function (b) { return b.keep; });
      this.state = "hold"; this.clock = 0;
      this.until = this.stage % this.seq.length === 0 ? 9000 : 3800;
    }
    return true;
  };
  Logo.prototype.draw = function () {
    var g = this.g, c = this.cell, s = Math.max(1, c * 0.84), o = (c - s) / 2;
    g.clearRect(0, 0, this.w, this.h);
    g.save(); g.beginPath(); g.rect(0, 0, this.w, this.h); g.clip();
    for (var i = 0; i < this.blocks.length; i++) {
      var b = this.blocks[i];
      g.globalAlpha = b.a;
      g.fillStyle = b.k < this.split ? this.accent : this.ink;
      g.fillRect(b.x * c + o, b.y * c + o, s, s);
    }
    g.globalAlpha = 1; g.restore();
  };

  function mount() {
    var els = document.querySelectorAll("[data-logo]");
    for (var i = 0; i < els.length; i++) if (!els[i].__blocks && (els[i].getAttribute("data-logo") || "").trim()) new Logo(els[i]);
  }
  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) all.forEach(function (p) { if (p.onScreen) wake(p); });
  });
  var rt;
  window.addEventListener("resize", function () {
    clearTimeout(rt);
    rt = setTimeout(function () { all.forEach(function (p) { p.layout(); if (p.onScreen) wake(p); }); }, 180);
  });
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", mount); else mount();
})();

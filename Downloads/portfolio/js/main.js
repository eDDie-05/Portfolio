(function () {
  'use strict';

  var root = document.documentElement;
  var reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var finePointer = !!(window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches);
  var live = { typing: null, network: null };

  function $(sel, scope) { return (scope || document).querySelector(sel); }
  function $$(sel, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(sel)); }

  /* ========================================================
     Global behaviour: runs once per full page load
     ======================================================== */
  function initGlobal() {
    var header = $('#site-header');
    var menuBtn = $('#menu-btn');
    var themeBtn = $('#theme-toggle');
    var progress = $('#progress');
    var toTop = $('#to-top');

    /* ---- Theme ---- */
    function theme() { return root.getAttribute('data-theme') === 'light' ? 'light' : 'dark'; }
    function syncThemeButton() {
      if (!themeBtn) return;
      themeBtn.setAttribute('aria-label', theme() === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
    }
    syncThemeButton();
    if (themeBtn) {
      themeBtn.addEventListener('click', function () {
        var next = theme() === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('theme', next); } catch (e) {}
        syncThemeButton();
        document.dispatchEvent(new CustomEvent('themechange'));
      });
    }

    /* ---- Mobile menu ---- */
    function setMenu(open) {
      if (!header || !menuBtn) return;
      header.classList.toggle('is-open', open);
      menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      menuBtn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    }
    if (menuBtn) {
      menuBtn.addEventListener('click', function () {
        setMenu(!header.classList.contains('is-open'));
      });
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') setMenu(false);
    });
    document.addEventListener('click', function (e) {
      if (header && header.classList.contains('is-open') && !e.target.closest('#site-header')) setMenu(false);
    });
    window.addEventListener('resize', function () {
      if (window.innerWidth >= 860) setMenu(false);
    });

    /* ---- Scroll: header state, progress bar, back to top ---- */
    var ticking = false;
    function onScroll() {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(function () {
        ticking = false;
        var y = window.pageYOffset || root.scrollTop || 0;
        var max = root.scrollHeight - window.innerHeight;
        if (header) header.classList.toggle('is-scrolled', y > 8);
        if (progress) progress.style.setProperty('--p', max > 0 ? Math.min(1, y / max).toFixed(4) : '0');
        if (toTop) toTop.classList.toggle('is-shown', y > 700);
      });
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    onScroll();

    if (toTop) {
      toTop.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
      });
    }

    /* ---- Page transitions between real pages ---- */
    function go(href) {
      document.body.classList.add('is-leaving');
      window.setTimeout(function () {
        if (window.PortfolioNavigate && window.PortfolioNavigate(href)) {
          document.body.classList.remove('is-leaving');
          return;
        }
        window.location.href = href;
      }, reduce ? 0 : 200);
    }
    document.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var a = e.target.closest('a[href]');
      if (!a || a.target === '_blank' || a.hasAttribute('download')) return;
      var href = a.getAttribute('href');
      if (!/^[\w-]+\.html(#.*)?$/.test(href)) return;
      var current = (window.location.pathname.split('/').pop() || 'index.html');
      var file = href.split('#')[0];
      if (file === current && href.indexOf('#') > -1 && !window.PortfolioNavigate) return; /* same page: normal anchor scroll */
      e.preventDefault();
      setMenu(false);
      go(href);
    });
    window.addEventListener('pageshow', function () { document.body.classList.remove('is-leaving'); });

    /* ---- Mouse effects (delegated, so they work on any page) ---- */
    if (finePointer && !reduce) initPointerEffects();
  }

  function initPointerEffects() {
    root.classList.add('has-cursor');

    var dot = document.createElement('div');
    dot.className = 'cursor cursor-dot';
    var ring = document.createElement('div');
    ring.className = 'cursor cursor-ring';
    dot.setAttribute('aria-hidden', 'true');
    ring.setAttribute('aria-hidden', 'true');
    document.body.appendChild(ring);
    document.body.appendChild(dot);

    var mx = -100, my = -100, rx = -100, ry = -100;
    var tilted = null, magnet = null;
    var interactive = 'a, button, summary, input, textarea, select, label, [data-cursor]';

    function loop() {
      rx += (mx - rx) * 0.18;
      ry += (my - ry) * 0.18;
      ring.style.transform = 'translate3d(' + rx.toFixed(1) + 'px,' + ry.toFixed(1) + 'px,0)';
      dot.style.transform = 'translate3d(' + mx + 'px,' + my + 'px,0)';
      window.requestAnimationFrame(loop);
    }
    window.requestAnimationFrame(loop);

    function resetTilt() {
      if (tilted) { tilted.style.transform = ''; tilted = null; }
    }
    function resetMagnet() {
      if (magnet) { magnet.style.translate = ''; magnet = null; }
    }

    document.addEventListener('pointermove', function (e) {
      if (e.pointerType && e.pointerType !== 'mouse') return;
      mx = e.clientX; my = e.clientY;
      ring.classList.add('is-on');
      dot.classList.add('is-on');

      var target = e.target.closest ? e.target.closest(interactive) : null;
      var labelEl = e.target.closest ? e.target.closest('[data-cursor-label]') : null;
      ring.classList.toggle('is-link', !!target);
      ring.classList.toggle('has-label', !!labelEl);
      ring.textContent = labelEl ? labelEl.getAttribute('data-cursor-label') : '';

      /* Card spotlight and tilt */
      var card = e.target.closest ? e.target.closest('.card') : null;
      if (card) {
        var r = card.getBoundingClientRect();
        var x = e.clientX - r.left, y = e.clientY - r.top;
        card.style.setProperty('--mx', x + 'px');
        card.style.setProperty('--my', y + 'px');
        if (card.hasAttribute('data-tilt')) {
          if (tilted && tilted !== card) resetTilt();
          tilted = card;
          var px = x / r.width - 0.5, py = y / r.height - 0.5;
          card.style.transform = 'perspective(900px) rotateX(' + (-py * 5).toFixed(2) + 'deg) rotateY(' + (px * 6).toFixed(2) + 'deg) translateY(-4px)';
        } else { resetTilt(); }
      } else { resetTilt(); }

      /* Magnetic buttons */
      var btn = e.target.closest ? e.target.closest('.btn') : null;
      if (btn) {
        if (magnet && magnet !== btn) resetMagnet();
        magnet = btn;
        var b = btn.getBoundingClientRect();
        var dx = (e.clientX - (b.left + b.width / 2)) * 0.18;
        var dy = (e.clientY - (b.top + b.height / 2)) * 0.28;
        btn.style.translate = dx.toFixed(1) + 'px ' + dy.toFixed(1) + 'px';
      } else { resetMagnet(); }
    });

    document.addEventListener('pointerdown', function () { ring.classList.add('is-down'); });
    document.addEventListener('pointerup', function () { ring.classList.remove('is-down'); });
    document.documentElement.addEventListener('mouseleave', function () {
      ring.classList.remove('is-on');
      dot.classList.remove('is-on');
      resetTilt();
      resetMagnet();
    });
  }

  /* ========================================================
     Per-page behaviour: runs on load, and again after the
     preview swaps pages
     ======================================================== */
  function initPage(scope) {
    scope = scope || document;

    if (live.typing) { live.typing(); live.typing = null; }
    if (live.network) { live.network(); live.network = null; }

    initReveal(scope);

    var typed = $('#typed', scope);
    if (typed && !reduce) live.typing = startTyping(typed);

    var canvas = $('#net', scope);
    if (canvas) live.network = startNetwork(canvas);

    initFilters(scope);
    initContact(scope);
  }

  /* ---- Reveal on scroll ---- */
  function initReveal(scope) {
    var items = $$('.reveal', scope);
    if (!items.length) return;
    if (reduce || !('IntersectionObserver' in window)) {
      items.forEach(function (el) { el.classList.add('is-visible'); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -5% 0px' });
    items.forEach(function (el) { io.observe(el); });
  }

  /* ---- Typing effect in the hero ---- */
  function startTyping(el) {
    var words = ['Computer Science student', 'Software developer', 'Cybersecurity enthusiast'];
    var wi = 0, ci = 0, deleting = false, timer = 0;
    function tick() {
      var word = words[wi];
      if (!deleting) {
        ci++;
        el.textContent = word.slice(0, ci);
        if (ci === word.length) { deleting = true; timer = window.setTimeout(tick, 1700); return; }
        timer = window.setTimeout(tick, 70);
      } else {
        ci--;
        el.textContent = word.slice(0, ci);
        if (ci === 0) { deleting = false; wi = (wi + 1) % words.length; timer = window.setTimeout(tick, 380); return; }
        timer = window.setTimeout(tick, 32);
      }
    }
    el.textContent = '';
    tick();
    return function stop() { window.clearTimeout(timer); };
  }

  /* ---- Animated network background in the hero ---- */
  function startNetwork(canvas) {
    var ctx = canvas.getContext && canvas.getContext('2d');
    if (!ctx) return null;
    var host = canvas.parentElement;
    var w = 0, h = 0, pts = [], raf = 0, running = false;
    var mouse = { x: -9999, y: -9999 };
    var color = '#22D3EE';

    function readColor() {
      var c = window.getComputedStyle(root).getPropertyValue('--accent').trim();
      if (c) color = c;
    }
    function resize() {
      var r = canvas.getBoundingClientRect();
      var dpr = Math.min(window.devicePixelRatio || 1, 2);
      w = r.width; h = r.height;
      canvas.width = Math.round(w * dpr);
      canvas.height = Math.round(h * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      var n = Math.max(26, Math.min(85, Math.round((w * h) / 15000)));
      pts = [];
      for (var i = 0; i < n; i++) {
        pts.push({ x: Math.random() * w, y: Math.random() * h, vx: (Math.random() - 0.5) * 0.35, vy: (Math.random() - 0.5) * 0.35 });
      }
      draw(false);
    }
    function draw(step) {
      ctx.clearRect(0, 0, w, h);
      ctx.fillStyle = color;
      ctx.strokeStyle = color;
      var i, j, p, q, dx, dy, d;
      for (i = 0; i < pts.length; i++) {
        p = pts[i];
        if (step) {
          p.x += p.vx; p.y += p.vy;
          if (p.x < 0 || p.x > w) p.vx *= -1;
          if (p.y < 0 || p.y > h) p.vy *= -1;
        }
        ctx.globalAlpha = 0.75;
        ctx.beginPath();
        ctx.arc(p.x, p.y, 1.7, 0, 6.2832);
        ctx.fill();
        for (j = i + 1; j < pts.length; j++) {
          q = pts[j];
          dx = p.x - q.x; dy = p.y - q.y;
          d = Math.sqrt(dx * dx + dy * dy);
          if (d < 125) {
            ctx.globalAlpha = (1 - d / 125) * 0.35;
            ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(q.x, q.y); ctx.stroke();
          }
        }
        dx = p.x - mouse.x; dy = p.y - mouse.y;
        d = Math.sqrt(dx * dx + dy * dy);
        if (d < 170) {
          ctx.globalAlpha = (1 - d / 170) * 0.7;
          ctx.lineWidth = 1.2;
          ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(mouse.x, mouse.y); ctx.stroke();
        }
      }
      ctx.globalAlpha = 1;
    }
    function frame() {
      if (!running) return;
      draw(true);
      raf = window.requestAnimationFrame(frame);
    }
    function start() { if (!running && !reduce) { running = true; raf = window.requestAnimationFrame(frame); } }
    function stopLoop() { running = false; window.cancelAnimationFrame(raf); }

    function onMove(e) {
      var r = canvas.getBoundingClientRect();
      mouse.x = e.clientX - r.left; mouse.y = e.clientY - r.top;
    }
    function onLeave() { mouse.x = -9999; mouse.y = -9999; }
    function onVisibility() { if (document.hidden) stopLoop(); else start(); }
    function onTheme() { readColor(); if (!running) draw(false); }
    var resizeTimer = 0;
    function onResize() { window.clearTimeout(resizeTimer); resizeTimer = window.setTimeout(resize, 150); }

    readColor();
    resize();
    host.addEventListener('pointermove', onMove);
    host.addEventListener('pointerleave', onLeave);
    window.addEventListener('resize', onResize);
    document.addEventListener('visibilitychange', onVisibility);
    document.addEventListener('themechange', onTheme);

    var io = null;
    if ('IntersectionObserver' in window) {
      io = new IntersectionObserver(function (entries) {
        if (entries[0].isIntersecting) start(); else stopLoop();
      });
      io.observe(host);
    } else { start(); }

    return function stop() {
      stopLoop();
      if (io) io.disconnect();
      host.removeEventListener('pointermove', onMove);
      host.removeEventListener('pointerleave', onLeave);
      window.removeEventListener('resize', onResize);
      document.removeEventListener('visibilitychange', onVisibility);
      document.removeEventListener('themechange', onTheme);
    };
  }

  /* ---- Project filters ---- */
  function initFilters(scope) {
    var bar = $('.filters', scope);
    if (!bar) return;
    var items = $$('[data-tags]', scope);
    var count = $('#filter-count', scope);
    bar.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-filter]');
      if (!btn) return;
      var f = btn.getAttribute('data-filter');
      $$('[data-filter]', bar).forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
      var shown = 0;
      items.forEach(function (el) {
        var tags = (el.getAttribute('data-tags') || '').split(' ');
        var match = f === 'all' || tags.indexOf(f) > -1;
        el.hidden = !match;
        if (match) {
          shown++;
          var card = el.querySelector('.project-card');
          if (card && !reduce) {
            card.classList.remove('is-filtering');
            void card.offsetWidth;
            card.classList.add('is-filtering');
          }
        }
      });
      if (count) count.textContent = 'Showing ' + shown + (shown === 1 ? ' project' : ' projects');
    });
  }

  /* ---- Contact page: mailto form and copy button ---- */
  function initContact(scope) {
    var RECIPIENT = 'edynamite05@gmail.com';

    var copyBtn = $('#copy-email', scope);
    if (copyBtn) {
      copyBtn.addEventListener('click', function () {
        var label = $('.copy-label', copyBtn);
        function done(ok) {
          if (label) label.textContent = ok ? 'Copied' : 'Press and hold to copy';
          window.setTimeout(function () { if (label) label.textContent = 'Copy'; }, 2000);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(RECIPIENT).then(function () { done(true); }, function () { done(false); });
        } else { done(false); }
      });
    }

    var form = $('#contact-form', scope);
    var status = $('#cf-status', scope);
    if (!form) return;
    function setStatus(text) { if (status) status.textContent = text; }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var f = {
        name: form.elements['name'],
        email: form.elements['email'],
        subject: form.elements['subject'],
        message: form.elements['message']
      };
      var firstInvalid = null;
      Object.keys(f).forEach(function (key) {
        var value = f[key].value.trim();
        var valid = value.length > 0;
        if (key === 'email' && valid) valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
        f[key].setAttribute('aria-invalid', valid ? 'false' : 'true');
        if (!valid && !firstInvalid) firstInvalid = f[key];
      });
      if (firstInvalid) {
        setStatus(firstInvalid === f.email ? 'Enter a valid email address so I can reply.' : 'Fill in every field, then send again.');
        firstInvalid.focus();
        return;
      }
      var body = f.message.value.trim() + '\n\n' + f.name.value.trim() + '\n' + f.email.value.trim();
      window.location.href = 'mailto:' + RECIPIENT + '?subject=' + encodeURIComponent(f.subject.value.trim()) + '&body=' + encodeURIComponent(body);
      setStatus('Your email app should open now. If it does not, write to ' + RECIPIENT + '.');
    });
  }

  window.PortfolioInit = initPage;
  initGlobal();
  initPage(document);
})();

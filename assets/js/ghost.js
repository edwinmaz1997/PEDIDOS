// ============================================================
// NuevaExpress — Fantasmita de Halloween 👻
// Ronda por la pantalla del 1 de octubre al 2 de noviembre.
// No bloquea la página: solo el fantasma recibe toques (dice "¡Boo!").
// ============================================================
(function () {
  var now = new Date();
  var m = now.getMonth(), d = now.getDate();
  var season = (m === 9) || (m === 10 && d <= 2);
  if (!season) return;
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  try { if (sessionStorage.getItem('nx_ghost_off') === '1') return; } catch (e) {}

  var css = document.createElement('style');
  css.textContent =
    '.nx-ghost{position:fixed;left:0;top:0;width:58px;height:70px;z-index:950;cursor:pointer;opacity:.92;' +
      'filter:drop-shadow(0 10px 14px rgba(15,23,42,.22));will-change:transform;-webkit-tap-highlight-color:transparent;transition:opacity .4s}' +
    '.nx-ghost svg{width:100%;height:100%;display:block;transition:transform .35s ease}' +
    '.nx-ghost.flip svg{transform:scaleX(-1)}' +
    '.nx-ghost .nx-g-body{animation:nxGhostWave 1.6s ease-in-out infinite;transform-origin:50% 80%}' +
    '.nx-ghost .nx-g-eye{animation:nxGhostBlink 4.5s infinite;transform-origin:center;transform-box:fill-box}' +
    '.nx-ghost.boo svg{animation:nxGhostBoo .6s ease}' +
    '.nx-ghost.hide{opacity:0;pointer-events:none}' +
    '.nx-ghost-say{position:absolute;bottom:100%;left:50%;transform:translateX(-50%) translateY(4px);background:#0f172a;color:#fff;' +
      'font:800 .78rem "Plus Jakarta Sans",system-ui,sans-serif;padding:5px 10px;border-radius:999px;white-space:nowrap;opacity:0;' +
      'transition:opacity .2s,transform .2s;pointer-events:none}' +
    '.nx-ghost-say.on{opacity:1;transform:translateX(-50%) translateY(-4px)}' +
    '@keyframes nxGhostWave{0%,100%{transform:skewX(0)}50%{transform:skewX(-4deg)}}' +
    '@keyframes nxGhostBlink{0%,92%,100%{transform:scaleY(1)}95%{transform:scaleY(.1)}}' +
    '@keyframes nxGhostBoo{0%{transform:scale(1)}40%{transform:scale(1.35) rotate(-8deg)}100%{transform:scale(1)}}';
  document.head.appendChild(css);

  var g = document.createElement('div');
  g.className = 'nx-ghost';
  g.setAttribute('aria-hidden', 'true');
  g.innerHTML =
    '<span class="nx-ghost-say">¡Boo!</span>' +
    '<svg viewBox="0 0 64 78">' +
      '<g class="nx-g-body">' +
        '<path d="M32 3C16.5 3 6 14.6 6 30v36.5c0 2.4 2.7 3.8 4.6 2.4l4.4-3.2 5 4.2c1.3 1.1 3.2 1.1 4.5 0L32 64l7.5 5.9c1.3 1.1 3.2 1.1 4.5 0l5-4.2 4.4 3.2c1.9 1.4 4.6 0 4.6-2.4V30C58 14.6 47.5 3 32 3z" fill="#ffffff"/>' +
        '<path d="M32 3C16.5 3 6 14.6 6 30v36.5c0 2.4 2.7 3.8 4.6 2.4l4.4-3.2 5 4.2c1.3 1.1 3.2 1.1 4.5 0L32 64l7.5 5.9c1.3 1.1 3.2 1.1 4.5 0l5-4.2 4.4 3.2c1.9 1.4 4.6 0 4.6-2.4V30C58 14.6 47.5 3 32 3z" fill="none" stroke="#dbe3ee" stroke-width="2"/>' +
        '<ellipse class="nx-g-eye" cx="23" cy="30" rx="4.2" ry="5.6" fill="#0f172a"/>' +
        '<ellipse class="nx-g-eye" cx="41" cy="30" rx="4.2" ry="5.6" fill="#0f172a"/>' +
        '<circle cx="24.4" cy="28" r="1.4" fill="#fff"/><circle cx="42.4" cy="28" r="1.4" fill="#fff"/>' +
        '<ellipse cx="32" cy="42" rx="4" ry="5" fill="#0f172a"/>' +
        '<ellipse cx="16" cy="38" rx="4" ry="2.4" fill="#fda4af" opacity=".7"/>' +
        '<ellipse cx="48" cy="38" rx="4" ry="2.4" fill="#fda4af" opacity=".7"/>' +
      '</g>' +
    '</svg>';

  function start() {
    document.body.appendChild(g);
    var say = g.querySelector('.nx-ghost-say');
    var W = window.innerWidth, H = window.innerHeight;
    var x = Math.random() * (W - 60), y = H * (0.25 + Math.random() * 0.4);
    var tx = x, ty = y, t = 0, last = performance.now();
    var speed = 38; // px por segundo
    var hidden = false;

    function pickTarget() {
      W = window.innerWidth; H = window.innerHeight;
      tx = 10 + Math.random() * Math.max(10, W - 80);
      ty = 90 + Math.random() * Math.max(10, H - 260); // evita la barra de arriba y la de abajo
    }
    pickTarget();

    function frame(nowT) {
      var dt = Math.min(0.05, (nowT - last) / 1000); last = nowT; t += dt;
      if (!hidden) {
        var dx = tx - x, dy = ty - y, dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < 6) pickTarget();
        else { x += dx / dist * speed * dt; y += dy / dist * speed * dt; }
        if (Math.abs(dx) > 2) g.classList.toggle('flip', dx < 0);
        var bob = Math.sin(t * 2.4) * 7;
        g.style.transform = 'translate(' + x.toFixed(1) + 'px,' + (y + bob).toFixed(1) + 'px)';
      }
      requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);

    // Tocar al fantasma: dice "¡Boo!", desaparece y vuelve más tarde
    g.addEventListener('click', function (e) {
      e.stopPropagation();
      g.classList.remove('boo'); void g.offsetWidth; g.classList.add('boo');
      say.classList.add('on');
      setTimeout(function () { say.classList.remove('on'); g.classList.add('hide'); hidden = true; }, 900);
      setTimeout(function () {
        x = Math.random() < 0.5 ? -60 : window.innerWidth + 10; y = 120 + Math.random() * 200;
        pickTarget(); hidden = false; g.classList.remove('hide');
      }, 9000);
    });

    // Se esconde si hay una ventana abierta encima (para no estorbar)
    setInterval(function () {
      var modalOpen = document.body.style.overflow === 'hidden';
      g.style.opacity = modalOpen ? '0' : '';
      g.style.pointerEvents = modalOpen ? 'none' : '';
    }, 500);

    window.addEventListener('resize', pickTarget);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();

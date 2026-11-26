/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚═════╝ ╚══════╝   ╚═╝
 *
 *   SOCLY — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */

/*
 * “Goditi una pausa”: the small bubble-popping game shown while a heavy SOCLY
 * operation keeps the instance paused. Vanilla JS, no dependencies, keyboard
 * accessible (every bubble is a real button) and quiet under prefers-reduced-motion.
 *
 * Usage:
 *   SoclyPauseGame.init(document);            // scans [data-pause-game]
 *   SoclyPauseGame.init(someContainerElement);
 *
 * Labels travel as data-game-* attributes on the game box so i18n stays in the
 * translation files (resource_busy.game_*).
 */
(function () {
  'use strict';

  var MAX_SCORE = 10;
  var BUBBLES = 7;
  var RESPAWN_MS = 320;
  /** Base speed (px per second) before the first pop. */
  var BASE_SPEED = 26;
  /** Every popped bubble adds this fraction to the speed (0.15 = +15%). */
  var SPEED_STEP = 0.15;
  /** Never faster than this multiple of the base speed. */
  var MAX_SPEED_FACTOR = 2.5;
  /** Longest frame we integrate, so a background tab does not teleport bubbles. */
  var MAX_FRAME_MS = 50;

  var DEFAULTS = {
    title: 'Pop the bubbles',
    hint: 'A light pastime while you wait.',
    score: ':count bubbles popped',
    win: 'Break complete: the desk feels lighter!',
    reset: 'Play again',
    aria: 'Mini-game: pop the bubbles',
    bubble: 'Pop the bubble',
  };

  function labelsFor(box) {
    var dataset = box.dataset || {};
    return {
      title: dataset.gameTitle || DEFAULTS.title,
      hint: dataset.gameHint || DEFAULTS.hint,
      score: dataset.gameScore || DEFAULTS.score,
      win: dataset.gameWin || DEFAULTS.win,
      reset: dataset.gameReset || DEFAULTS.reset,
      aria: dataset.gameAria || DEFAULTS.aria,
      bubble: dataset.gameBubble || DEFAULTS.bubble,
    };
  }

  function formatScore(template, score) {
    return String(template || '').replace(':count', String(score));
  }

  function setup(box) {
    var labels = labelsFor(box);
    box.innerHTML = '';
    box.setAttribute('role', 'group');
    box.setAttribute('aria-label', labels.aria);

    var head = document.createElement('div');
    head.className = 'pause-game-head';
    var headTitle = document.createElement('strong');
    headTitle.textContent = labels.title;
    var scoreEl = document.createElement('span');
    scoreEl.className = 'pause-score';
    scoreEl.setAttribute('aria-live', 'polite');
    head.appendChild(headTitle);
    head.appendChild(scoreEl);

    var hint = document.createElement('p');
    hint.className = 'pause-game-hint';
    hint.textContent = labels.hint;

    var field = document.createElement('div');
    field.className = 'pause-playfield';

    var win = document.createElement('p');
    win.className = 'pause-win';
    win.hidden = true;
    win.textContent = labels.win;

    var reset = document.createElement('button');
    reset.type = 'button';
    reset.className = 'btn btn-ghost btn-sm pause-reset';
    reset.textContent = labels.reset;
    reset.hidden = true;

    box.appendChild(head);
    box.appendChild(hint);
    box.appendChild(field);
    box.appendChild(win);
    box.appendChild(reset);

    var score = 0;
    var finished = false;
    var slots = [];
    var used = [];
    for (var row = 0; row < 4; row += 1) {
      for (var col = 0; col < 4; col += 1) {
        slots.push({ left: 6 + col * 23, top: 8 + row * 21 });
      }
    }

    // Reduced motion: the bubbles stay still (no rAF loop at all).
    var reducedMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    // Live bubbles with their position/velocity in pixels.
    var bubbles = [];
    var rafId = 0;
    var lastTs = 0;

    function remPx() {
      var value = parseFloat(window.getComputedStyle(document.documentElement).fontSize);
      return isNaN(value) || value <= 0 ? 16 : value;
    }

    function speedFactor() {
      // Each pop speeds the whole field up, up to a cap so it stays playable.
      return Math.min(MAX_SPEED_FACTOR, 1 + score * SPEED_STEP);
    }

    function paint(entry) {
      entry.el.style.setProperty('--bx', entry.x.toFixed(1) + 'px');
      entry.el.style.setProperty('--by', entry.y.toFixed(1) + 'px');
    }

    /** Keep the bubble inside the field, reversing the velocity on the hit wall. */
    function clampToField(entry, width, height) {
      if (entry.x < 0) {
        entry.x = 0;
        entry.vx = Math.abs(entry.vx);
      }
      if (entry.y < 0) {
        entry.y = 0;
        entry.vy = Math.abs(entry.vy);
      }
      var maxX = Math.max(0, width - entry.size);
      var maxY = Math.max(0, height - entry.size);
      if (entry.x > maxX) {
        entry.x = maxX;
        entry.vx = -Math.abs(entry.vx);
      }
      if (entry.y > maxY) {
        entry.y = maxY;
        entry.vy = -Math.abs(entry.vy);
      }
    }

    /** Elastic separation so bubbles bounce off each other instead of stacking. */
    function separateBubbles(width, height) {
      for (var i = 0; i < bubbles.length; i += 1) {
        for (var j = i + 1; j < bubbles.length; j += 1) {
          var a = bubbles[i];
          var b = bubbles[j];
          var dx = (b.x + b.size / 2) - (a.x + a.size / 2);
          var dy = (b.y + b.size / 2) - (a.y + a.size / 2);
          var distance = Math.sqrt(dx * dx + dy * dy) || 0.0001;
          var minimum = (a.size + b.size) / 2;
          if (distance >= minimum) continue;
          var nx = dx / distance;
          var ny = dy / distance;
          var push = (minimum - distance) / 2 + 0.1;
          a.x -= nx * push;
          a.y -= ny * push;
          b.x += nx * push;
          b.y += ny * push;
          var va = a.vx * nx + a.vy * ny;
          var vb = b.vx * nx + b.vy * ny;
          if (va - vb <= 0) continue; // already moving apart
          var transfer = vb - va;
          a.vx += transfer * nx;
          a.vy += transfer * ny;
          b.vx -= transfer * nx;
          b.vy -= transfer * ny;
          clampToField(a, width, height);
          clampToField(b, width, height);
        }
      }
    }

    function step(ts) {
      rafId = 0;
      if (!box.isConnected || finished) return;
      var width = field.clientWidth;
      var height = field.clientHeight;
      var dt = lastTs === 0 ? 0 : Math.min(MAX_FRAME_MS, Math.max(0, ts - lastTs)) / 1000;
      lastTs = ts;

      if (width >= 24 && height >= 24) {
        var factor = speedFactor();
        for (var i = 0; i < bubbles.length; i += 1) {
          var entry = bubbles[i];
          if (!entry.placed) {
            // The overlay was hidden when the bubble spawned: spread it now.
            entry.x = Math.random() * Math.max(0, width - entry.size);
            entry.y = Math.random() * Math.max(0, height - entry.size);
            entry.placed = true;
          } else {
            entry.x += entry.vx * factor * dt;
            entry.y += entry.vy * factor * dt;
          }
          clampToField(entry, width, height);
        }
        separateBubbles(width, height);
        for (var k = 0; k < bubbles.length; k += 1) {
          paint(bubbles[k]);
        }
      }

      rafId = window.requestAnimationFrame(step);
    }

    function startLoop() {
      if (reducedMotion || rafId || finished) return;
      lastTs = 0;
      rafId = window.requestAnimationFrame(step);
    }

    function stopLoop() {
      if (rafId) {
        window.cancelAnimationFrame(rafId);
        rafId = 0;
      }
    }

    function takeSlot() {
      for (var attempt = 0; attempt < 24; attempt += 1) {
        var index = Math.floor(Math.random() * slots.length);
        if (!used[index]) {
          used[index] = true;
          return { index: index, left: slots[index].left, top: slots[index].top };
        }
      }
      return null;
    }

    function releaseSlot(index) {
      if (typeof index === 'number') used[index] = false;
    }

    function updateScore() {
      scoreEl.textContent = formatScore(labels.score, score);
      if (score >= MAX_SCORE && !finished) {
        finished = true;
        stopLoop();
        field.classList.add('is-won');
        win.hidden = false;
        reset.hidden = false;
      }
    }

    function pop(entry, slotIndex) {
      if (entry.el.classList.contains('is-popped')) return;
      entry.el.classList.add('is-popped');
      entry.el.disabled = true;
      var index = bubbles.indexOf(entry);
      if (index !== -1) bubbles.splice(index, 1);
      score += 1;
      updateScore();
      window.setTimeout(function () {
        if (entry.el.parentNode) entry.el.parentNode.removeChild(entry.el);
        releaseSlot(slotIndex);
        if (!finished) spawn();
      }, RESPAWN_MS);
    }

    function spawn() {
      if (finished) return;
      if (bubbles.length >= BUBBLES) return;
      var slot = takeSlot();
      if (!slot) return;

      var bubble = document.createElement('button');
      bubble.type = 'button';
      bubble.className = 'pause-bubble';
      bubble.dataset.slot = String(slot.index);
      var sizeRem = 1.35 + Math.random() * 0.85;
      var size = sizeRem * remPx();
      bubble.style.setProperty('--bubble-size', sizeRem.toFixed(2) + 'rem');
      bubble.setAttribute('aria-label', labels.bubble);

      var angle = Math.random() * Math.PI * 2;
      var speed = BASE_SPEED * (0.75 + Math.random() * 0.5);
      var width = field.clientWidth;
      var height = field.clientHeight;
      var measurable = width >= size + 8 && height >= size + 8;
      var entry = {
        el: bubble,
        size: size,
        // Start from the slot grid so the first frame is not a pile in a corner.
        x: measurable ? Math.max(0, Math.min(width - size, (slot.left / 100) * width)) : 0,
        y: measurable ? Math.max(0, Math.min(height - size, (slot.top / 100) * height)) : 0,
        vx: Math.cos(angle) * speed,
        vy: Math.sin(angle) * speed,
        placed: measurable,
      };
      bubbles.push(entry);
      bubble.addEventListener('click', function () {
        pop(entry, slot.index);
      });
      field.appendChild(bubble);
      paint(entry);
      startLoop();
    }

    function fill() {
      for (var i = 0; i < BUBBLES; i += 1) spawn();
    }

    reset.addEventListener('click', function () {
      score = 0;
      finished = false;
      used = [];
      bubbles = [];
      field.classList.remove('is-won');
      field.innerHTML = '';
      win.hidden = true;
      reset.hidden = true;
      updateScore();
      fill();
    });

    updateScore();
    fill();
  }

  function init(scope) {
    var root = scope && scope.querySelectorAll ? scope : document;
    var boxes = [];
    if (root.matches && root.matches('[data-pause-game]')) boxes.push(root);
    Array.prototype.push.apply(boxes, Array.prototype.slice.call(root.querySelectorAll('[data-pause-game]')));
    boxes.forEach(function (box) {
      if (box.dataset.pauseGameBound === '1') return;
      box.dataset.pauseGameBound = '1';
      setup(box);
    });
  }

  window.SoclyPauseGame = { init: init };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      init(document);
    });
  } else {
    init(document);
  }
})();

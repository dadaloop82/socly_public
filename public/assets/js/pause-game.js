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
 * accessible (every bubble is a real button).
 *
 * The game is endless: a popped bubble is replaced immediately and every pop
 * speeds the whole field up. A small scoreboard shows the current score, the
 * operator's best and the shared all-time record with its holder (the score is
 * saved to /pause-game/score while the overlay is open).
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

  var BUBBLES = 8;
  var RESPAWN_MS = 260;
  /** Base speed (px per second) before the first pop. */
  var BASE_SPEED = 42;
  /** Every popped bubble adds this fraction to the speed (0.22 = +22%). */
  var SPEED_STEP = 0.22;
  /** Never faster than this multiple of the base speed. */
  var MAX_SPEED_FACTOR = 6;
  /** Longest frame we integrate, so a background tab does not teleport bubbles. */
  var MAX_FRAME_MS = 50;
  /** Score POSTs are coalesced: a quick burst of pops sends one update. */
  var SAVE_DEBOUNCE_MS = 900;

  var DEFAULTS = {
    title: 'Pop the bubbles',
    hint: 'The more you pop, the faster they get.',
    scoreLabel: 'Score',
    recordLabel: 'Record',
    recordNone: 'No record yet',
    recordYou: 'You',
    personalLabel: 'Your best',
    recordNew: 'New record!',
    players: ':count players',
    aria: 'Mini-game: pop the bubbles',
    bubble: 'Pop the bubble',
  };

  function labelsFor(box) {
    var dataset = box.dataset || {};
    return {
      title: dataset.gameTitle || DEFAULTS.title,
      hint: dataset.gameHint || DEFAULTS.hint,
      scoreLabel: dataset.gameScoreLabel || DEFAULTS.scoreLabel,
      recordLabel: dataset.gameRecordLabel || DEFAULTS.recordLabel,
      recordNone: dataset.gameRecordNone || DEFAULTS.recordNone,
      recordYou: dataset.gameRecordYou || DEFAULTS.recordYou,
      personalLabel: dataset.gamePersonalLabel || DEFAULTS.personalLabel,
      recordNew: dataset.gameRecordNew || DEFAULTS.recordNew,
      players: dataset.gamePlayers || DEFAULTS.players,
      aria: dataset.gameAria || DEFAULTS.aria,
      bubble: dataset.gameBubble || DEFAULTS.bubble,
    };
  }

  function fillTemplate(template, values) {
    var out = String(template || '');
    Object.keys(values || {}).forEach(function (key) {
      out = out.split(':' + key).join(String(values[key] == null ? '' : values[key]));
    });
    return out;
  }

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? String(meta.getAttribute('content') || '') : '';
  }

  function setup(box) {
    var labels = labelsFor(box);
    var apiUrl = String(box.dataset.gameUrl || '');
    var token = csrfToken();
    box.innerHTML = '';
    box.setAttribute('role', 'group');
    box.setAttribute('aria-label', labels.aria);

    var head = document.createElement('div');
    head.className = 'pause-game-head';
    var headTitle = document.createElement('strong');
    headTitle.textContent = labels.title;
    head.appendChild(headTitle);

    var hint = document.createElement('p');
    hint.className = 'pause-game-hint';
    hint.textContent = labels.hint;

    var hud = document.createElement('div');
    hud.className = 'pause-hud';
    hud.setAttribute('role', 'status');

    function stat(text, modifier) {
      var el = document.createElement('div');
      el.className = 'pause-stat ' + modifier;
      var label = document.createElement('span');
      label.className = 'pause-stat-label';
      label.textContent = text;
      var value = document.createElement('strong');
      value.className = 'pause-stat-value';
      value.textContent = '0';
      el.appendChild(label);
      el.appendChild(value);
      return { el: el, value: value };
    }

    var scoreStat = stat(labels.scoreLabel, 'pause-stat--score');
    scoreStat.value.setAttribute('aria-live', 'polite');
    var recordStat = stat(labels.recordLabel, 'pause-stat--record');
    var recordMeta = document.createElement('span');
    recordMeta.className = 'pause-stat-meta';
    recordMeta.textContent = labels.recordNone;
    recordStat.el.appendChild(recordMeta);
    var personalStat = stat(labels.personalLabel, 'pause-stat--personal');
    hud.appendChild(scoreStat.el);
    hud.appendChild(recordStat.el);
    hud.appendChild(personalStat.el);

    var recordFlash = document.createElement('p');
    recordFlash.className = 'pause-record-flash';
    recordFlash.textContent = labels.recordNew;
    recordFlash.hidden = true;

    var field = document.createElement('div');
    field.className = 'pause-playfield';

    box.appendChild(head);
    box.appendChild(hint);
    box.appendChild(hud);
    box.appendChild(recordFlash);
    box.appendChild(field);


    var score = 0;
    var personalBest = 0;
    var record = null;
    var players = 0;
    var savedScore = 0;
    var saveTimer = 0;
    var saving = false;
    var slots = [];
    var used = [];
    for (var row = 0; row < 4; row += 1) {
      for (var col = 0; col < 4; col += 1) {
        slots.push({ left: 6 + col * 23, top: 8 + row * 21 });
      }
    }

    // Reduced motion: the bubbles still drift, just much more slowly (the motion is
    // the game, so it is not removed entirely), and the decorative CSS animations
    // stay off.
    var reducedMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var motionScale = reducedMotion ? 0.35 : 1;
    var bubbles = [];
    var rafId = 0;
    var lastTs = 0;
    var everConnected = false;

    function remPx() {
      var value = parseFloat(window.getComputedStyle(document.documentElement).fontSize);
      return isNaN(value) || value <= 0 ? 16 : value;
    }

    function speedFactor() {
      // Every pop makes the whole field faster; the cap only keeps the game playable.
      return Math.min(MAX_SPEED_FACTOR, 1 + score * SPEED_STEP);
    }

    function renderHud() {
      var personal = Math.max(personalBest, score);
      scoreStat.value.textContent = String(score);
      personalStat.value.textContent = String(personal);

      var bestScore = record && typeof record.score === 'number' ? record.score : 0;
      var holder = record ? String(record.name || '') : '';
      if (score > bestScore) {
        bestScore = score;
        holder = labels.recordYou;
      }
      if (bestScore <= 0) {
        recordStat.value.textContent = '—';
        recordMeta.textContent = labels.recordNone;
        return;
      }
      recordStat.value.textContent = String(bestScore);
      var meta = [];
      if (holder !== '') meta.push(holder);
      if (players > 0) meta.push(fillTemplate(labels.players, { count: players }));
      recordMeta.textContent = meta.join(' · ');
    }

    function applyState(data) {
      if (!data || data.ok !== true) return;
      if (typeof data.personal === 'number') {
        personalBest = Math.max(personalBest, data.personal);
      }
      if (data.record && typeof data.record.score === 'number') {
        record = { score: data.record.score, name: String(data.record.name || '') };
      }
      if (typeof data.players === 'number') {
        players = data.players;
      }
      renderHud();
    }

    function flashRecord() {
      hud.classList.add('is-record');
      recordFlash.hidden = false;
      window.setTimeout(function () {
        hud.classList.remove('is-record');
        recordFlash.hidden = true;
      }, 2600);
    }

    function loadState() {
      if (apiUrl === '') return;
      fetch(apiUrl, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      })
        .then(function (response) {
          return response.ok ? response.json() : null;
        })
        .then(applyState)
        .catch(function () {});
    }

    function needsSave() {
      // Only a score above both the last saved value and the stored personal best is worth a POST.
      return apiUrl !== '' && token !== '' && score > Math.max(savedScore, personalBest);
    }

    function saveScore() {
      if (saving || !needsSave()) return;
      var value = score;
      saving = true;
      var body = new URLSearchParams();
      body.set('_token', token);
      body.set('score', String(value));
      fetch(apiUrl, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: body.toString(),
      })
        .then(function (response) {
          return response.ok ? response.json() : null;
        })
        .then(function (data) {
          saving = false;
          if (!data || data.ok !== true) return;
          savedScore = Math.max(savedScore, value);
          var previousBest = record ? record.score : 0;
          applyState(data);
          if (data.is_record === true && value > previousBest) flashRecord();
        })
        .catch(function () {
          saving = false;
        });
    }

    function scheduleSave() {
      if (!needsSave()) return;
      window.clearTimeout(saveTimer);
      saveTimer = window.setTimeout(saveScore, SAVE_DEBOUNCE_MS);
    }

    function flushScore() {
      if (!needsSave()) return;
      try {
        var body = new URLSearchParams();
        body.set('_token', token);
        body.set('score', String(score));
        if (navigator.sendBeacon && navigator.sendBeacon(apiUrl, body)) {
          savedScore = score;
          return;
        }
      } catch (error) {
        // The debounced POST remains the fallback.
      }
      saveScore();
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
      if (box.isConnected) {
        everConnected = true;
      } else if (everConnected) {
        // The overlay was closed: stop for good.
        return;
      }
      // Not in the document yet: keep polling, the game starts as soon as it is.
      var width = field.clientWidth;
      var height = field.clientHeight;
      var dt = lastTs === 0 ? 0 : Math.min(MAX_FRAME_MS, Math.max(0, ts - lastTs)) / 1000;
      lastTs = ts;

      if (width >= 24 && height >= 24) {
        // The speed factor is applied every frame, so a pop accelerates every bubble already on
        // the field, not only the next ones.
        var factor = speedFactor() * motionScale;
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
      if (rafId) return;
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


    function pop(entry, slotIndex) {
      if (entry.el.classList.contains('is-popped')) return;
      entry.el.classList.add('is-popped');
      entry.el.disabled = true;
      var index = bubbles.indexOf(entry);
      if (index !== -1) bubbles.splice(index, 1);
      score += 1;
      renderHud();
      scheduleSave();
      // Endless game: the popped bubble is always replaced, so the field never empties.
      window.setTimeout(function () {
        if (entry.el.parentNode) entry.el.parentNode.removeChild(entry.el);
        releaseSlot(slotIndex);
        spawn();
      }, RESPAWN_MS);
    }

    function spawn() {
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

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        stopLoop();
        flushScore();
      } else {
        startLoop();
      }
    });
    window.addEventListener('pagehide', flushScore);

    loadState();
    renderHud();
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


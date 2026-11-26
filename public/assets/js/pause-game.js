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
        field.classList.add('is-won');
        win.hidden = false;
        reset.hidden = false;
      }
    }

    function spawn() {
      if (finished) return;
      if (field.querySelectorAll('.pause-bubble').length >= BUBBLES) return;
      var slot = takeSlot();
      if (!slot) return;

      var bubble = document.createElement('button');
      bubble.type = 'button';
      bubble.className = 'pause-bubble';
      bubble.dataset.slot = String(slot.index);
      var size = 1.35 + Math.random() * 0.85;
      bubble.style.setProperty('--bubble-size', size.toFixed(2) + 'rem');
      bubble.style.left = (slot.left + (Math.random() * 5 - 2.5)).toFixed(2) + '%';
      bubble.style.top = (slot.top + (Math.random() * 4 - 2)).toFixed(2) + '%';
      bubble.style.animationDelay = (-Math.random() * 4).toFixed(2) + 's';
      bubble.style.animationDuration = (3.2 + Math.random() * 2.4).toFixed(2) + 's';
      bubble.setAttribute('aria-label', labels.bubble);
      bubble.addEventListener('click', function () {
        if (bubble.classList.contains('is-popped')) return;
        bubble.classList.add('is-popped');
        bubble.disabled = true;
        score += 1;
        updateScore();
        var slotIndex = Number(bubble.dataset.slot || -1);
        window.setTimeout(function () {
          if (bubble.parentNode) bubble.parentNode.removeChild(bubble);
          releaseSlot(slotIndex);
          if (!finished) spawn();
        }, RESPAWN_MS);
      });
      field.appendChild(bubble);
    }

    function fill() {
      for (var i = 0; i < BUBBLES; i += 1) spawn();
    }

    reset.addEventListener('click', function () {
      score = 0;
      finished = false;
      used = [];
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

/*
 * AI Actions: chat bubble in the agent panel.
 *
 * The conversation belongs to the ticket that is open right now: the ticket
 * view, or the quick view of the Ticket Board plugin. The conversation lives
 * on the server; the browser only sends the next message and renders what
 * comes back. Everything is rendered with textContent, nothing the model
 * writes ends up as HTML.
 */
(function () {
  'use strict';

  var cfgEl = document.getElementById('spa-ai-config');
  if (!cfgEl || document.getElementById('spa-ai-launcher')) return;
  var cfg;
  try { cfg = JSON.parse(cfgEl.textContent); } catch (e) { return; }
  var t = cfg.strings || {};

  var STORE_KEY = 'spa-ai-open';
  var state = { open: false, ticket: 0, number: '', transcript: [], turnsLeft: 0, busy: false, error: '' };

  // ---------------------------------------------------------------- helpers

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = String(text);
    return n;
  }

  function button(cls, text, onClick) {
    var b = el('button', cls, text);
    b.type = 'button';
    b.addEventListener('click', onClick);
    return b;
  }

  function csrf() {
    var m = document.querySelector('meta[name="csrf_token"]');
    return m ? m.getAttribute('content') : '';
  }

  function errorText(code) {
    var e = t.errors || {};
    return e[code] || e.generic || 'Error';
  }

  function request(method, path, data) {
    var opts = { method: method, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
    var url = cfg.base + path;
    if (method === 'POST') {
      var body = new URLSearchParams();
      body.append('__CSRFToken__', csrf());
      Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
      opts.body = body;
    } else if (data) {
      url += '?' + new URLSearchParams(data).toString();
    }
    return fetch(url, opts).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (json) {
        if (!res.ok) throw new Error(json.error || 'generic');
        return json;
      });
    });
  }

  function remember(open) {
    try { window.sessionStorage.setItem(STORE_KEY, open ? '1' : '0'); } catch (e) { /* storage blocked */ }
  }

  function remembered() {
    try { return window.sessionStorage.getItem(STORE_KEY) === '1'; } catch (e) { return false; }
  }

  /** The board's quick view drawer, if it is open. */
  function boardDrawer() {
    var d = document.querySelector('.spb-drawer.spb-open');
    return d && !d.hidden ? d : null;
  }

  /** ID of the ticket the agent is looking at, or 0. */
  function currentTicket() {
    var drawer = boardDrawer();
    var body = drawer && drawer.querySelector('.spb-panel-body[data-ticket-id]');
    if (body) return parseInt(body.getAttribute('data-ticket-id'), 10) || 0;
    if (document.querySelector('.spb-board')) return 0;
    var input = document.querySelector('#note input[name="id"], #response_options input[name="id"]');
    return input ? (parseInt(input.value, 10) || 0) : 0;
  }

  // ------------------------------------------------------------------ frame

  var launcher = button('spa-ai-launcher', '', function () { setOpen(!state.open); });
  launcher.id = 'spa-ai-launcher';
  launcher.setAttribute('aria-label', t.open || 'AI');
  launcher.setAttribute('aria-expanded', 'false');
  launcher.innerHTML = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">'
    + '<path fill="currentColor" d="M12 2l1.9 5.6L19.5 9.5l-5.6 1.9L12 17l-1.9-5.6L4.5 9.5l5.6-1.9L12 2zm7 11l.95 2.55L22.5 16.5l-2.55.95L19 20l-.95-2.55L15.5 16.5l2.55-.95L19 13z"/></svg>';

  var panel = el('section', 'spa-ai-panel');
  panel.id = 'spa-ai';
  panel.hidden = true;
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', t.title || 'AI');

  var head = el('header', 'spa-ai-head');
  var titleBox = el('div', 'spa-ai-titles');
  var title = el('div', 'spa-ai-title', t.title);
  var subtitle = el('div', 'spa-ai-subtitle');
  titleBox.appendChild(title);
  titleBox.appendChild(subtitle);
  var newBtn = button('spa-ai-icon-btn spa-ai-new', t['new'], function () { reset(); });
  var closeBtn = button('spa-ai-icon-btn spa-ai-close', '×', function () { setOpen(false); });
  closeBtn.setAttribute('aria-label', t.close || 'Close');
  head.appendChild(titleBox);
  head.appendChild(newBtn);
  head.appendChild(closeBtn);

  var log = el('div', 'spa-ai-log');
  log.setAttribute('aria-live', 'polite');

  var form = el('form', 'spa-ai-form');
  var input = el('textarea', 'spa-ai-input');
  input.rows = 1;
  input.maxLength = 2000;
  input.placeholder = t.placeholder || '';
  input.setAttribute('aria-label', t.placeholder || 'Message');
  var sendBtn = el('button', 'spa-ai-send', t.send);
  sendBtn.type = 'submit';
  form.appendChild(input);
  form.appendChild(sendBtn);
  var foot = el('div', 'spa-ai-foot');

  panel.appendChild(head);
  panel.appendChild(log);
  panel.appendChild(form);
  panel.appendChild(foot);
  document.body.appendChild(panel);
  document.body.appendChild(launcher);

  // ----------------------------------------------------------------- render

  function render() {
    subtitle.textContent = state.ticket ? (t.ticket + ' #' + state.number) : '';
    newBtn.hidden = !state.ticket || !state.transcript.length;
    var usable = state.ticket > 0;
    input.disabled = !usable || state.busy;
    sendBtn.disabled = !usable || state.busy;
    foot.textContent = usable && state.transcript.length && state.turnsLeft <= 5
      ? (t.turns_left || '%d').replace('%d', state.turnsLeft) : '';

    log.textContent = '';
    if (!usable) {
      log.appendChild(el('p', 'spa-ai-hint', t.no_ticket));
      return;
    }
    if (!state.transcript.length && !state.busy) {
      log.appendChild(el('p', 'spa-ai-hint', t.empty));
    }
    state.transcript.forEach(function (item) { log.appendChild(message(item)); });
    if (state.busy) {
      var dots = el('div', 'spa-ai-msg spa-ai-msg-ai spa-ai-busy');
      dots.appendChild(el('span', 'spa-ai-dots', state.busy === 'run' ? t.running : state.busy === 'undo' ? t.undoing : t.thinking));
      log.appendChild(dots);
    }
    if (state.error) log.appendChild(el('div', 'spa-ai-error', state.error));
    log.scrollTop = log.scrollHeight;
  }

  function message(item) {
    var mine = item.role === 'user';
    var box = el('div', 'spa-ai-msg ' + (mine ? 'spa-ai-msg-me' : 'spa-ai-msg-ai'));
    if (item.text) box.appendChild(el('div', 'spa-ai-bubble', item.text));
    if (item.plan) box.appendChild(planCard(item.plan));
    return box;
  }

  function planCard(plan) {
    var card = el('div', 'spa-ai-plan spa-ai-plan-' + (plan.state || 'open'));
    card.appendChild(el('div', 'spa-ai-plan-title', t.preview));
    var list = el('ul', 'spa-ai-plan-list');
    var results = plan.results || [];
    (plan.changes || []).forEach(function (c, i) {
      var r = results[i];
      var li = el('li', r ? (r.ok ? 'spa-ai-ok' : 'spa-ai-fail') : 'spa-ai-change');
      li.appendChild(el('span', 'spa-ai-plan-label', c.label));
      li.appendChild(el('span', 'spa-ai-plan-value', c.value));
      if (r && !r.ok) {
        li.appendChild(el('span', 'spa-ai-plan-why', t.failed + ': ' + errorText(r.error) + (r.message ? ' (' + r.message + ')' : '')));
      }
      list.appendChild(li);
    });
    card.appendChild(list);

    if (plan.state === 'open' && plan.id) {
      var actions = el('div', 'spa-ai-plan-actions');
      actions.appendChild(button('spa-ai-run', t.run, function () { run(plan.id); }));
      actions.appendChild(button('spa-ai-discard', t.discard, function () { discard(plan.id); }));
      card.appendChild(actions);
      return card;
    }

    if (plan.undo && plan.undo.length) {
      var back = el('ul', 'spa-ai-plan-list spa-ai-plan-undo');
      plan.undo.forEach(function (u) {
        var li = el('li', u.ok ? 'spa-ai-ok' : 'spa-ai-fail');
        li.appendChild(el('span', 'spa-ai-plan-label', '↺ ' + u.label));
        li.appendChild(el('span', 'spa-ai-plan-value', u.value));
        if (!u.ok) li.appendChild(el('span', 'spa-ai-plan-why', t.failed + ': ' + errorText(u.error)));
        back.appendChild(li);
      });
      card.appendChild(back);
    }

    var foot = el('div', 'spa-ai-plan-foot');
    foot.appendChild(el('span', 'spa-ai-plan-state', (t.states || {})[plan.state] || plan.state));
    if (plan.state === 'done' && plan.id && plan.undo_left > 0) {
      foot.appendChild(undoButton(plan.id, plan.undo_left));
    }
    card.appendChild(foot);
    return card;
  }

  /** Undo, counting down; at zero it disappears (the server refuses after that anyway). */
  function undoButton(planId, seconds) {
    var until = Date.now() + seconds * 1000;
    var b = button('spa-ai-undo', '', function () { undo(planId); });
    var timer = 0;
    function tick(first) {
      var left = Math.ceil((until - Date.now()) / 1000);
      // A re-render replaces the card; this button is then gone from the page.
      if (left <= 0 || (!first && !b.isConnected)) {
        window.clearInterval(timer);
        if (b.parentNode) b.parentNode.removeChild(b);
        return;
      }
      b.textContent = (t.undo_left || '%ds').replace('%d', left);
    }
    tick(true);
    timer = window.setInterval(tick, 1000);
    return b;
  }

  // ---------------------------------------------------------------- actions

  function apply(json) {
    state.ticket = json.ticket ? json.ticket.id : state.ticket;
    state.number = json.ticket ? json.ticket.number : state.number;
    state.transcript = json.transcript || [];
    state.turnsLeft = json.turns_left || 0;
  }

  function fail(err) {
    state.error = errorText(err && err.message);
  }

  function load(id) {
    state.ticket = id;
    state.number = '';
    state.transcript = [];
    state.error = '';
    render();
    if (!id) return Promise.resolve();
    return request('GET', '/chat', { ticket_id: String(id) }).then(function (json) {
      if (state.ticket === id) apply(json);
    }).catch(fail).then(render);
  }

  function sync() {
    var id = currentTicket();
    if (id !== state.ticket) load(id);
    place();
  }

  function send(text) {
    if (!state.ticket || state.busy) return;
    var id = state.ticket;
    state.error = '';
    state.transcript = state.transcript.concat([{ role: 'user', text: text }]);
    state.busy = 'plan';
    render();
    request('POST', '/plan', { ticket_id: String(id), prompt: text }).then(function (json) {
      if (state.ticket === id) apply(json);
      input.value = '';
    }).catch(function (err) {
      // The server kept nothing of this message; the text stays in the box.
      state.transcript = state.transcript.slice(0, -1);
      input.value = text;
      fail(err);
    }).then(function () {
      state.busy = false;
      render();
      input.focus();
    });
  }

  function run(planId) {
    var id = state.ticket;
    state.busy = 'run';
    state.error = '';
    render();
    request('POST', '/run', { plan_id: planId }).then(function (json) {
      var changed = (json.results || []).some(function (r) { return r.ok; });
      if (changed) refreshTicket();
    }).catch(fail).then(function () {
      state.busy = false;
      return load(id);
    });
  }

  function discard(planId) {
    var id = state.ticket;
    request('POST', '/discard', { plan_id: planId }).catch(fail).then(function () { return load(id); });
  }

  function undo(planId) {
    var id = state.ticket;
    state.busy = 'undo';
    state.error = '';
    render();
    request('POST', '/undo', { plan_id: planId }).then(function (json) {
      var changed = (json.results || []).some(function (r) { return r.ok; });
      if (changed) refreshTicket();
    }).catch(fail).then(function () {
      state.busy = false;
      return load(id);
    });
  }

  function reset() {
    var id = state.ticket;
    if (!id) return;
    request('POST', '/reset', { ticket_id: String(id) }).then(apply).catch(fail).then(render);
  }

  /** Shows the changed fields. The ticket view reloads; the board refreshes itself. */
  function refreshTicket() {
    var $ = window.jQuery;
    if (document.querySelector('.spb-board')) return;
    window.setTimeout(function () {
      if ($ && $.pjax && document.getElementById('pjax-container')) {
        $.pjax.reload('#pjax-container', { timeout: 5000 });
      } else {
        window.location.reload();
      }
    }, 600);
  }

  // ------------------------------------------------------------ open, place

  function setOpen(open) {
    state.open = open;
    panel.hidden = !open;
    launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
    launcher.classList.toggle('spa-ai-launcher-open', open);
    remember(open);
    if (open) {
      sync();
      render();
      input.focus();
    }
  }

  /** Moves the bubble and window left of the board's drawer while it is open. */
  function place() {
    var drawer = boardDrawer();
    var right = 20;
    if (drawer) {
      var r = drawer.getBoundingClientRect();
      if (r.width > 0 && r.left > 0) right = Math.round(window.innerWidth - r.left) + 16;
    }
    launcher.style.right = right + 'px';
    panel.style.right = right + 'px';
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (text) send(text);
  });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
      e.preventDefault();
      form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
    }
  });
  input.addEventListener('input', function () {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 140) + 'px';
  });
  panel.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { setOpen(false); launcher.focus(); }
  });

  // Ticket changes: PJAX navigation in the agent panel, cards in the board.
  var pending = 0;
  function watch() {
    if (pending) return;
    pending = window.setTimeout(function () {
      pending = 0;
      place();
      if (state.open) sync();
    }, 150);
  }
  if (window.jQuery) window.jQuery(document).on('pjax:end', watch);
  new MutationObserver(watch).observe(document.body, {
    childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'hidden'],
  });
  window.addEventListener('resize', place);

  place();
  if (remembered()) setOpen(true);
})();

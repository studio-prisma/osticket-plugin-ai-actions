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
  var NAME = cfg.name || t.title || 'AI';

  var STORE_KEY = 'spa-ai-open';
  var LARGE_KEY = 'spa-ai-large';
  var SIZE_KEY = 'spa-ai-size';
  var MIN_W = 320;
  var MIN_H = 360;

  // view: chat | history (list of earlier conversations) | archived (one of them, read only)
  var state = {
    open: false, ticket: -1, number: '', transcript: [], proposals: [], runs: [], turnsLeft: 0, busy: false, error: '',
    view: 'chat', history: null, archived: null,
  };

  // Icons: 24x24, filled with currentColor.
  var ICONS = {
    sparkles: 'M12 2l1.9 5.6L19.5 9.5l-5.6 1.9L12 17l-1.9-5.6L4.5 9.5l5.6-1.9L12 2zm7 11l.95 2.55L22.5 16.5l-2.55.95L19 20l-.95-2.55L15.5 16.5l2.55-.95L19 13z',
    robot: 'M11 2h2v2.07A7 7 0 0 1 19 11v6a3 3 0 0 1-3 3H8a3 3 0 0 1-3-3v-6a7 7 0 0 1 6-6.93V2zm-2 9.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm6 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zM9 17h6v-1.5H9V17zM2 11h2v5H2v-5zm18 0h2v5h-2v-5z',
    chat: 'M4 3h16a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H9l-5 4v-4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm3 6.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm5 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm5 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3z',
    bulb: 'M12 2a7 7 0 0 1 4 12.74V17a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1v-2.26A7 7 0 0 1 12 2zm-3 17h6v1a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2v-1z',
    bolt: 'M13 2L4 14h7l-1 8 9-12h-7l1-8z',
    history: 'M13 3a9 9 0 1 1-8.95 10h2.02A7 7 0 1 0 13 5a6.98 6.98 0 0 0-5.2 2.3L10 9.5H4v-6l2.38 2.38A8.97 8.97 0 0 1 13 3zm-1 5h2v4.6l3.2 1.85-1 1.73L12 13.75V8z',
    expand: 'M4 4h6v2H7.41l3.3 3.29-1.42 1.42L6 7.41V10H4V4zm16 0v6h-2V7.41l-3.29 3.3-1.42-1.42L16.59 6H14V4h6zM4 20v-6h2v2.59l3.29-3.3 1.42 1.42L7.41 18H10v2H4zm16 0h-6v-2h2.59l-3.3-3.29 1.42-1.42L18 16.59V14h2v6z',
    shrink: 'M10 4v6H4V8h2.59l-3.3-3.29 1.42-1.42L8 6.59V4h2zm4 0h2v2.59l3.29-3.3 1.42 1.42L17.41 8H20v2h-6V4zM4 14h6v6H8v-2.59l-3.29 3.3-1.42-1.42L6.59 16H4v-2zm10 0h6v2h-2.59l3.3 3.29-1.42 1.42L16 17.41V20h-2v-6z',
  };

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

  function svg(name, size) {
    var ns = 'http://www.w3.org/2000/svg';
    var s = document.createElementNS(ns, 'svg');
    s.setAttribute('viewBox', '0 0 24 24');
    s.setAttribute('width', String(size));
    s.setAttribute('height', String(size));
    s.setAttribute('aria-hidden', 'true');
    s.setAttribute('focusable', 'false');
    var p = document.createElementNS(ns, 'path');
    p.setAttribute('fill', 'currentColor');
    p.setAttribute('d', ICONS[name] || ICONS.sparkles);
    s.appendChild(p);
    return s;
  }

  /** The assistant's picture: icon, first letter, or the admin's image. */
  function avatar(cls, size) {
    var box = el('span', 'spa-ai-avatar ' + cls);
    if (cfg.icon === 'image' && cfg.image) {
      var img = el('img');
      img.alt = '';
      img.src = cfg.image;
      img.addEventListener('error', function () {
        box.textContent = '';
        box.appendChild(svg('sparkles', size));
      });
      box.appendChild(img);
    } else if (cfg.icon === 'initial') {
      box.appendChild(el('span', 'spa-ai-initial', (NAME.trim().charAt(0) || 'A').toUpperCase()));
    } else {
      box.appendChild(svg(cfg.icon, size));
    }
    return box;
  }

  function iconButton(cls, icon, label, onClick) {
    var b = button('spa-ai-icon-btn ' + cls, '', onClick);
    b.appendChild(svg(icon, 16));
    b.setAttribute('aria-label', label);
    b.title = label;
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

  function fill(text) {
    var args = Array.prototype.slice.call(arguments, 1);
    return String(text || '').replace(/%d/g, function () { return args.length ? args.shift() : ''; });
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

  function stored(store, key) {
    try { return window[store].getItem(key); } catch (e) { return null; }
  }

  function store(where, key, value) {
    try {
      if (value === null) window[where].removeItem(key); else window[where].setItem(key, value);
    } catch (e) { /* storage blocked */ }
  }

  /** The board's quick view drawer, if it is open. */
  function boardDrawer() {
    var d = document.querySelector('.spb-drawer.spb-open');
    return d && !d.hidden ? d : null;
  }

  /** ID of the ticket the agent is looking at, 0 for the settings chat on admin pages, or -1 for nothing. */
  function currentTicket() {
    if (cfg.admin) return 0;
    return ticketOnPage() || -1;
  }

  function ticketOnPage() {
    var drawer = boardDrawer();
    var body = drawer && drawer.querySelector('.spb-panel-body[data-ticket-id]');
    if (body) return parseInt(body.getAttribute('data-ticket-id'), 10) || 0;
    if (document.querySelector('.spb-board')) return 0;
    var input = document.querySelector('#note input[name="id"], #response_options input[name="id"]');
    return input ? (parseInt(input.value, 10) || 0) : 0;
  }

  function narrow() {
    return window.matchMedia && window.matchMedia('(max-width: 640px)').matches;
  }

  // ------------------------------------------------------------------ frame

  var launcher = button('spa-ai-launcher', '', function () { setOpen(!state.open); });
  launcher.id = 'spa-ai-launcher';
  launcher.setAttribute('aria-label', cfg.name ? NAME : (t.open || 'AI'));
  launcher.title = NAME;
  launcher.setAttribute('aria-expanded', 'false');
  launcher.appendChild(avatar('spa-ai-avatar-launcher', 24));

  var panel = el('section', 'spa-ai-panel');
  panel.id = 'spa-ai';
  panel.hidden = true;
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', NAME);

  // The admin's accent colour beats the theme's; text on it stays readable.
  if (/^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test(cfg.accent || '')) {
    [launcher, panel].forEach(function (n) {
      n.style.setProperty('--spa-accent', cfg.accent);
      n.style.setProperty('--spa-accent-ink', inkOn(cfg.accent));
    });
  }

  function inkOn(hex) {
    var h = hex.slice(1);
    if (h.length === 3) h = h.replace(/./g, '$&$&');
    var c = [0, 2, 4].map(function (i) {
      var v = parseInt(h.substr(i, 2), 16) / 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    var l = 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
    return l > 0.4 ? '#111111' : '#ffffff';
  }

  var grip = el('div', 'spa-ai-grip');
  grip.setAttribute('aria-hidden', 'true');
  grip.title = t.resize || '';

  var head = el('header', 'spa-ai-head');
  var titleBox = el('div', 'spa-ai-titles');
  var title = el('div', 'spa-ai-title', NAME);
  var subtitle = el('div', 'spa-ai-subtitle');
  titleBox.appendChild(title);
  titleBox.appendChild(subtitle);
  var backBtn = button('spa-ai-icon-btn spa-ai-back', '‹ ' + (t.back || 'Back'), function () { back(); });
  var historyBtn = iconButton('spa-ai-history-btn', 'history', t.history || 'History', function () { showHistory(); });
  var largeBtn = iconButton('spa-ai-large-btn', 'expand', t.large || 'Large', function () { setLarge(!panel.classList.contains('spa-ai-large')); });
  var newBtn = button('spa-ai-icon-btn spa-ai-new', t['new'], function () { reset(); });
  var closeBtn = button('spa-ai-icon-btn spa-ai-close', '×', function () { setOpen(false); });
  closeBtn.setAttribute('aria-label', t.close || 'Close');
  head.appendChild(backBtn);
  head.appendChild(avatar('spa-ai-avatar-head', 18));
  head.appendChild(titleBox);
  head.appendChild(historyBtn);
  head.appendChild(newBtn);
  head.appendChild(largeBtn);
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

  panel.appendChild(grip);
  panel.appendChild(head);
  panel.appendChild(log);
  panel.appendChild(form);
  panel.appendChild(foot);
  document.body.appendChild(panel);
  document.body.appendChild(launcher);

  // ----------------------------------------------------------------- render

  function render() {
    var usable = state.ticket > 0 || state.ticket === 0;
    var chat = state.view === 'chat';
    subtitle.textContent = state.ticket > 0 ? (t.ticket + ' #' + state.number) : (state.ticket === 0 ? (t.settings || 'Settings') : '');
    backBtn.hidden = chat;
    historyBtn.hidden = !usable || !chat || state.ticket === 0;
    newBtn.hidden = !usable || !chat || !state.transcript.length;
    form.hidden = !chat;
    input.disabled = !usable || !!state.busy;
    sendBtn.disabled = !usable || !!state.busy;
    foot.textContent = chat && usable && state.transcript.length && state.turnsLeft <= 5
      ? fill(t.turns_left, state.turnsLeft) : '';

    log.textContent = '';
    if (!usable) {
      log.appendChild(el('p', 'spa-ai-hint', t.no_ticket));
      return;
    }
    if (state.view === 'history') return renderHistory();
    if (state.view === 'archived') return renderArchived();

    if (!state.transcript.length && !state.proposals.length && !state.runs.length && !state.busy) {
      log.appendChild(el('p', 'spa-ai-hint spa-ai-welcome', state.ticket === 0 ? t.admin_empty : (cfg.welcome || t.empty)));
      var chips = el('div', 'spa-ai-chips');
      ((state.ticket === 0 ? t.admin_suggestions : t.suggestions) || []).forEach(function (s) {
        chips.appendChild(button('spa-ai-chip', s, function () {
          input.value = s;
          input.focus();
          input.dispatchEvent(new Event('input'));
        }));
      });
      log.appendChild(chips);
    }
    state.runs.forEach(function (r) { log.appendChild(runCard(r)); });
    state.proposals.forEach(function (p) { log.appendChild(proposalCard(p)); });
    state.transcript.forEach(function (item) { log.appendChild(message(item)); });
    if (state.busy) {
      var dots = el('div', 'spa-ai-msg spa-ai-msg-ai spa-ai-busy');
      dots.appendChild(el('span', 'spa-ai-dots', state.busy === 'run' ? t.running : state.busy === 'undo' ? t.undoing : t.thinking));
      log.appendChild(dots);
    }
    if (state.error) log.appendChild(el('div', 'spa-ai-error', state.error));
    log.scrollTop = log.scrollHeight;
  }

  function renderHistory() {
    var h = state.history;
    var kept = cfg.keep_days > 0;
    log.appendChild(el('p', 'spa-ai-hint', kept ? fill(t.history_kept, cfg.idle_hours, cfg.keep_days) : t.history_off));
    if (state.error) log.appendChild(el('div', 'spa-ai-error', state.error));
    if (!h) {
      log.appendChild(el('span', 'spa-ai-dots', '…'));
      return;
    }
    if (!h.length) {
      if (kept) log.appendChild(el('p', 'spa-ai-hint', t.history_none));
      return;
    }
    var list = el('ul', 'spa-ai-history');
    h.forEach(function (c) {
      var li = el('li');
      var b = button('spa-ai-history-item', '', function () { openArchived(c.id); });
      b.appendChild(el('span', 'spa-ai-history-title', c.title || '…'));
      b.appendChild(el('span', 'spa-ai-history-meta', c.ended + ' · ' + fill(t.messages, c.count)));
      li.appendChild(b);
      list.appendChild(li);
    });
    log.appendChild(list);
  }

  function renderArchived() {
    var a = state.archived;
    if (state.error) log.appendChild(el('div', 'spa-ai-error', state.error));
    if (!a) {
      log.appendChild(el('span', 'spa-ai-dots', '…'));
      return;
    }
    log.appendChild(el('p', 'spa-ai-hint', t.read_only + ' ' + a.ended));
    (a.transcript || []).forEach(function (item) { log.appendChild(message(item)); });
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
      // After a run the result's value, e.g. with the new sub-ticket's number.
      li.appendChild(el('span', 'spa-ai-plan-value', r && r.value ? r.value : c.value));
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
      var undone = el('ul', 'spa-ai-plan-list spa-ai-plan-undo');
      plan.undo.forEach(function (u) {
        var li = el('li', u.ok ? 'spa-ai-ok' : 'spa-ai-fail');
        li.appendChild(el('span', 'spa-ai-plan-label', '↺ ' + u.label));
        li.appendChild(el('span', 'spa-ai-plan-value', u.value));
        if (!u.ok) li.appendChild(el('span', 'spa-ai-plan-why', t.failed + ': ' + errorText(u.error)));
        undone.appendChild(li);
      });
      card.appendChild(undone);
    }

    var foot = el('div', 'spa-ai-plan-foot');
    foot.appendChild(el('span', 'spa-ai-plan-state', (t.states || {})[plan.state] || plan.state));
    // undo_left: seconds left; 0 = the window has passed (greyed out); absent = nothing to undo.
    if (plan.state === 'done' && typeof plan.undo_left === 'number') {
      foot.appendChild(undoButton(plan.id, plan.undo_left));
    }
    card.appendChild(foot);
    return card;
  }

  /** Header line of an AI agent's card: its picture (if any), who, which rule. */
  function agentLine(text, image) {
    var line = el('div', 'spa-ai-proposal-by');
    if (image) {
      var img = el('img', 'spa-ai-agent-img');
      img.alt = '';
      img.src = image;
      img.addEventListener('error', function () { img.remove(); });
      line.appendChild(img);
    }
    line.appendChild(document.createTextNode(text));
    return line;
  }

  /** An AI agent's automatic run of the last 24 hours, with undo while the server allows it. */
  function runCard(r) {
    var box = el('div', 'spa-ai-msg spa-ai-msg-ai spa-ai-autorun');
    box.appendChild(agentLine(String(t.auto_by || '%s').replace('%s', r.by) + (r.rule ? ' · ' + r.rule : '')
      + (r.when ? ' · ' + r.when : ''), r.image));
    var card = el('div', 'spa-ai-plan spa-ai-plan-' + (r.undone ? 'undone' : 'done'));
    var list = el('ul', 'spa-ai-plan-list');
    (r.changes || []).forEach(function (c) {
      var li = el('li', c.ok ? 'spa-ai-ok' : 'spa-ai-fail');
      li.appendChild(el('span', 'spa-ai-plan-label', c.label));
      li.appendChild(el('span', 'spa-ai-plan-value', c.value));
      if (!c.ok) li.appendChild(el('span', 'spa-ai-plan-why', t.failed + ': ' + errorText(c.error)));
      list.appendChild(li);
    });
    card.appendChild(list);
    var foot = el('div', 'spa-ai-plan-foot');
    foot.appendChild(el('span', 'spa-ai-plan-state', (t.states || {})[r.undone ? 'undone' : 'done'] || ''));
    if (r.undoable) {
      foot.appendChild(button('spa-ai-undo spa-ai-autorun-undo', t.undo || 'Undo', function () { autoUndo(r.id); }));
    }
    card.appendChild(foot);
    box.appendChild(card);
    return box;
  }

  function autoUndo(id) {
    var ticket = state.ticket;
    state.busy = 'undo';
    state.error = '';
    render();
    request('POST', '/autoundo', { ticket_id: String(ticket), id: String(id) }).then(function () {
      refreshTicket(ticket);
    }).catch(fail).then(function () {
      state.busy = false;
      return load(ticket);
    });
  }

  /** A plan an AI agent made through a rule; anyone who can open the ticket decides, with their own rights. */
  function proposalCard(p) {
    var box = el('div', 'spa-ai-msg spa-ai-msg-ai spa-ai-proposal');
    box.appendChild(agentLine(String(t.proposal_by || '%s').replace('%s', p.by) + (p.rule ? ' · ' + p.rule : ''), p.image));
    if (p.answer) box.appendChild(el('div', 'spa-ai-bubble', p.answer));
    var card = el('div', 'spa-ai-plan spa-ai-plan-open');
    card.appendChild(el('div', 'spa-ai-plan-title', t.preview));
    var list = el('ul', 'spa-ai-plan-list');
    (p.changes || []).forEach(function (c) {
      var li = el('li', 'spa-ai-change');
      li.appendChild(el('span', 'spa-ai-plan-label', c.label));
      li.appendChild(el('span', 'spa-ai-plan-value', c.value));
      list.appendChild(li);
    });
    card.appendChild(list);
    var actions = el('div', 'spa-ai-plan-actions');
    actions.appendChild(button('spa-ai-run', t.run, function () { decide(p.id, 'run'); }));
    actions.appendChild(button('spa-ai-discard', t.discard, function () { decide(p.id, 'discard'); }));
    card.appendChild(actions);
    box.appendChild(card);
    return box;
  }

  function decide(id, what) {
    var ticket = state.ticket;
    state.busy = what === 'run' ? 'run' : false;
    state.error = '';
    render();
    request('POST', '/proposal', { ticket_id: String(ticket), id: String(id), do: what }).then(function (json) {
      if ((json.results || []).some(function (r) { return r.ok; })) refreshTicket(ticket);
    }).catch(fail).then(function () {
      state.busy = false;
      return load(ticket);
    });
  }

  /** Undo without a countdown: usable while the server allows it, then greyed out. */
  function undoButton(planId, seconds) {
    var b = button('spa-ai-undo', t.undo || 'Undo', function () { if (!b.disabled) undo(planId); });
    function gone() {
      b.disabled = true;
      b.title = fill(t.undo_gone, cfg.undo_window || 30);
    }
    if (!planId || seconds <= 0) {
      gone();
    } else {
      window.setTimeout(gone, seconds * 1000);
    }
    return b;
  }

  // ---------------------------------------------------------------- actions

  function apply(json) {
    state.ticket = json.ticket ? json.ticket.id : state.ticket;
    state.number = json.ticket ? json.ticket.number : state.number;
    state.transcript = json.transcript || [];
    state.proposals = json.proposals || [];
    state.runs = json.runs || [];
    state.turnsLeft = json.turns_left || 0;
    marks(true);
  }

  function fail(err) {
    state.error = errorText(err && err.message);
  }

  function load(id) {
    state.ticket = id;
    state.number = '';
    state.transcript = [];
    state.proposals = [];
    state.runs = [];
    state.error = '';
    state.view = 'chat';
    render();
    if (id < 0) return Promise.resolve();
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
    if (state.ticket < 0 || state.busy) return;
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
      if (changed) refreshTicket(id);
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
      if (changed) refreshTicket(id);
    }).catch(fail).then(function () {
      state.busy = false;
      return load(id);
    });
  }

  function reset() {
    var id = state.ticket;
    if (id < 0) return;
    request('POST', '/reset', { ticket_id: String(id) }).then(apply).catch(fail).then(render);
  }

  function showHistory() {
    var id = state.ticket;
    state.view = 'history';
    state.history = null;
    state.error = '';
    render();
    request('GET', '/history', { ticket_id: String(id) }).then(function (json) {
      if (state.ticket === id && state.view === 'history') state.history = json.conversations || [];
    }).catch(function (err) { state.history = []; fail(err); }).then(render);
  }

  function openArchived(convId) {
    var id = state.ticket;
    state.view = 'archived';
    state.archived = null;
    state.error = '';
    render();
    request('GET', '/history', { ticket_id: String(id), id: String(convId) }).then(function (json) {
      if (state.ticket === id && state.view === 'archived') state.archived = json;
    }).catch(fail).then(render);
  }

  function back() {
    state.error = '';
    if (state.view === 'archived') return showHistory();
    state.view = 'chat';
    render();
  }

  /**
   * Shows the changed fields right away. The ticket view reloads through
   * PJAX; the board reloads with the quick view of this ticket open again
   * (the board restores it from #spb-ticket=ID). The chat stays open.
   */
  function refreshTicket(id) {
    var $ = window.jQuery;
    if (id === 0) {
      // Settings changed: reload the admin page to show them.
      window.setTimeout(function () { window.location.reload(); }, 400);
      return;
    }
    window.setTimeout(function () {
      if (document.querySelector('.spb-board')) {
        history.replaceState(null, '', location.pathname + location.search + (boardDrawer() ? '#spb-ticket=' + id : ''));
        window.location.reload();
      } else if ($ && $.pjax && document.getElementById('pjax-container')) {
        $.pjax.reload('#pjax-container', { timeout: 5000 });
      } else {
        window.location.reload();
      }
    }, 400);
  }

  // ------------------------------------------------------- open, size, place

  function setOpen(open) {
    state.open = open;
    panel.hidden = !open;
    launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
    launcher.classList.toggle('spa-ai-launcher-open', open);
    store('sessionStorage', STORE_KEY, open ? '1' : '0');
    if (open) {
      sync();
      render();
      if (!narrow()) input.focus();
    }
  }

  function setLarge(on) {
    panel.classList.toggle('spa-ai-large', on);
    largeBtn.textContent = '';
    largeBtn.appendChild(svg(on ? 'shrink' : 'expand', 16));
    largeBtn.setAttribute('aria-label', on ? t.small : t.large);
    largeBtn.title = on ? t.small : t.large;
    store('localStorage', LARGE_KEY, on ? '1' : null);
    applySize();
    place();
  }

  /** The size the agent dragged to, within the window; none in large view or on a phone. */
  function applySize() {
    var size = null;
    try { size = JSON.parse(stored('localStorage', SIZE_KEY) || 'null'); } catch (e) { size = null; }
    if (!size || panel.classList.contains('spa-ai-large') || narrow()) {
      panel.style.width = '';
      panel.style.height = '';
      return;
    }
    panel.style.width = Math.max(MIN_W, Math.min(size.w, window.innerWidth - 32)) + 'px';
    panel.style.height = Math.max(MIN_H, Math.min(size.h, window.innerHeight - 110)) + 'px';
  }

  // Drag the top-left corner: the window is anchored bottom right, so it grows up and to the left.
  grip.addEventListener('pointerdown', function (e) {
    if (panel.classList.contains('spa-ai-large') || narrow()) return;
    e.preventDefault();
    var r = panel.getBoundingClientRect();
    var startX = e.clientX;
    var startY = e.clientY;
    grip.setPointerCapture(e.pointerId);
    panel.classList.add('spa-ai-resizing');
    function move(ev) {
      var w = Math.max(MIN_W, Math.min(r.width + (startX - ev.clientX), window.innerWidth - 32));
      var h = Math.max(MIN_H, Math.min(r.height + (startY - ev.clientY), window.innerHeight - 110));
      panel.style.width = w + 'px';
      panel.style.height = h + 'px';
    }
    function up() {
      grip.removeEventListener('pointermove', move);
      grip.removeEventListener('pointerup', up);
      grip.removeEventListener('pointercancel', up);
      panel.classList.remove('spa-ai-resizing');
      var now = panel.getBoundingClientRect();
      store('localStorage', SIZE_KEY, JSON.stringify({ w: Math.round(now.width), h: Math.round(now.height) }));
    }
    grip.addEventListener('pointermove', move);
    grip.addEventListener('pointerup', up);
    grip.addEventListener('pointercancel', up);
  });
  grip.addEventListener('dblclick', function () {
    store('localStorage', SIZE_KEY, null);
    applySize();
  });

  /** Moves the bubble and window left of the board's drawer while it is open. */
  function place() {
    var drawer = boardDrawer();
    var right = narrow() ? 12 : 20;
    if (drawer && !narrow()) {
      var r = drawer.getBoundingClientRect();
      if (r.width > 0 && r.left > 0) right = Math.round(window.innerWidth - r.left) + 16;
    }
    launcher.style.right = right + 'px';
    panel.style.right = panel.classList.contains('spa-ai-large') || narrow() ? '' : right + 'px';
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
    if (e.key !== 'Escape') return;
    if (panel.classList.contains('spa-ai-large')) { setLarge(false); return; }
    setOpen(false);
    launcher.focus();
  });

  // ------------------------------------------------- marks outside the chat

  // Tickets on the page with an open proposal of an AI agent, and pictures
  // of AI agents' thread entries. Fetched when the set of tickets on the
  // page changes; put back on every DOM change, since the board re-renders
  // its cards.
  var marked = { key: '', proposals: [], entries: {} };

  function pageTickets() {
    if (cfg.admin) return [];
    var cards = document.querySelectorAll('.spb-board .spb-card[data-ticket-id]');
    var ids = [];
    if (cards.length) {
      Array.prototype.forEach.call(cards, function (c) {
        var id = parseInt(c.getAttribute('data-ticket-id'), 10);
        if (id > 0 && ids.indexOf(id) < 0) ids.push(id);
      });
    }
    var current = ticketOnPage();
    if (current > 0 && ids.indexOf(current) < 0) ids.push(current);
    return ids.slice(0, 300);
  }

  function marks(force) {
    var ids = pageTickets();
    var key = ids.join(',');
    if (!force && key === marked.key) return paint();
    marked.key = key;
    if (!ids.length) {
      marked.proposals = [];
      marked.entries = {};
      return paint();
    }
    request('GET', '/marks', { ids: key }).then(function (json) {
      if (marked.key !== key) return;
      marked.proposals = json.proposals || [];
      marked.entries = json.entries || {};
      paint();
    }).catch(function () { /* marks are a hint; the chat shows the truth */ });
  }

  function paint() {
    var waiting = t.proposal_waiting || '';
    var current = ticketOnPage();
    launcher.classList.toggle('spa-ai-launcher-dot', current > 0 && marked.proposals.indexOf(current) >= 0);
    Array.prototype.forEach.call(document.querySelectorAll('.spb-board .spb-card[data-ticket-id]'), function (c) {
      var on = marked.proposals.indexOf(parseInt(c.getAttribute('data-ticket-id'), 10)) >= 0;
      var dot = c.querySelector(':scope > .spa-ai-card-dot');
      if (on && !dot) {
        dot = el('span', 'spa-ai-card-dot');
        dot.title = waiting;
        dot.setAttribute('aria-label', waiting);
        c.appendChild(dot);
      } else if (!on && dot) {
        dot.remove();
      }
    });
    Object.keys(marked.entries).forEach(function (entryId) {
      var anchor = document.querySelector('a[name="entry-' + parseInt(entryId, 10) + '"]');
      var entry = anchor && anchor.closest('.thread-entry');
      var url = marked.entries[entryId];
      if (!entry || !url || entry.getAttribute('data-spa-ai-img') === url) return;
      entry.setAttribute('data-spa-ai-img', url);
      var img = entry.querySelector(':scope > .avatar img');
      if (img) {
        img.removeAttribute('srcset');
        img.src = url;
        return;
      }
      var name = entry.querySelector('.header > b');
      if (!name) return;
      var small = el('img', 'spa-ai-entry-img');
      small.alt = '';
      small.src = url;
      name.parentNode.insertBefore(small, name);
    });
  }

  // Ticket changes: PJAX navigation in the agent panel, cards in the board.
  var pending = 0;
  function watch() {
    if (pending) return;
    pending = window.setTimeout(function () {
      pending = 0;
      place();
      marks(false);
      if (state.open) sync();
    }, 150);
  }
  if (window.jQuery) window.jQuery(document).on('pjax:end', watch);
  new MutationObserver(watch).observe(document.body, {
    childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'hidden'],
  });
  window.addEventListener('resize', function () { applySize(); place(); });

  setLarge(stored('localStorage', LARGE_KEY) === '1');
  place();
  marks(true);
  if (stored('sessionStorage', STORE_KEY) === '1') setOpen(true);
})();

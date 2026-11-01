/*
 * AI Actions: the field in the agent ticket view.
 *
 * Mounts on every ticket view, including the ones the agent panel loads
 * through PJAX. Everything from the server is rendered with textContent;
 * nothing the model writes ends up as HTML.
 */
(function () {
  'use strict';

  var cfgEl = document.getElementById('spa-ai-config');
  if (!cfgEl) return;
  var cfg;
  try { cfg = JSON.parse(cfgEl.textContent); } catch (e) { return; }
  var t = cfg.strings || {};

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = String(text);
    return n;
  }

  function csrf() {
    var m = document.querySelector('meta[name="csrf_token"]');
    return m ? m.getAttribute('content') : '';
  }

  function errorText(code) {
    var e = t.errors || {};
    return e[code] || e.generic || 'Error';
  }

  function post(path, data) {
    var body = new URLSearchParams();
    body.append('__CSRFToken__', csrf());
    Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
    return fetch(cfg.base + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: body
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (json) {
        if (!res.ok) throw new Error(json.error || 'generic');
        return json;
      });
    });
  }

  function ticketId() {
    var input = document.querySelector('#note input[name="id"], #response_options input[name="id"]');
    var id = input ? parseInt(input.value, 10) : 0;
    return id > 0 ? id : 0;
  }

  function mount() {
    var anchor = document.getElementById('response_options');
    var id = ticketId();
    if (!anchor || !id || document.getElementById('spa-ai')) return;

    var box = el('div', 'spa-ai');
    box.id = 'spa-ai';
    box.setAttribute('data-ticket-id', String(id));

    var head = el('div', 'spa-ai-head', t.title);
    var input = el('textarea', 'spa-ai-input');
    input.rows = 2;
    input.maxLength = 2000;
    input.placeholder = t.placeholder || '';
    input.setAttribute('aria-label', t.title || 'AI');
    var planBtn = el('button', 'spa-ai-btn spa-ai-plan', t.plan);
    planBtn.type = 'button';
    var status = el('div', 'spa-ai-status');
    status.setAttribute('role', 'status');
    var answer = el('div', 'spa-ai-answer');
    var preview = el('div', 'spa-ai-preview');

    box.appendChild(head);
    box.appendChild(input);
    box.appendChild(planBtn);
    box.appendChild(status);
    box.appendChild(answer);
    box.appendChild(preview);
    anchor.parentNode.insertBefore(box, anchor);

    function reset() {
      answer.textContent = '';
      preview.textContent = '';
      status.textContent = '';
    }

    function showPreview(data) {
      preview.textContent = '';
      if (!data.changes || !data.changes.length || !data.plan_id) {
        if (!data.answer) preview.appendChild(el('p', 'spa-ai-muted', t.no_changes));
        return;
      }
      preview.appendChild(el('p', 'spa-ai-label', t.preview));
      var list = el('ul', 'spa-ai-list');
      data.changes.forEach(function (c) {
        var li = el('li', 'spa-ai-change');
        li.appendChild(el('strong', '', c.label + ': '));
        li.appendChild(document.createTextNode(c.value));
        list.appendChild(li);
      });
      preview.appendChild(list);

      var run = el('button', 'spa-ai-btn spa-ai-run', t.run);
      run.type = 'button';
      var discard = el('button', 'spa-ai-btn spa-ai-discard', t.discard);
      discard.type = 'button';
      preview.appendChild(run);
      preview.appendChild(discard);

      discard.addEventListener('click', reset);
      run.addEventListener('click', function () {
        run.disabled = true;
        discard.disabled = true;
        status.textContent = t.running;
        post('/run', { plan_id: data.plan_id }).then(function (res) {
          showResults(res.results || []);
        }).catch(function (err) {
          status.textContent = errorText(err.message);
        });
      });
    }

    function showResults(results) {
      preview.textContent = '';
      var list = el('ul', 'spa-ai-list');
      var anyOk = false;
      results.forEach(function (r) {
        var li = el('li', r.ok ? 'spa-ai-ok' : 'spa-ai-fail');
        li.appendChild(el('strong', '', r.label + ': '));
        li.appendChild(document.createTextNode(r.value));
        if (!r.ok) {
          var why = errorText(r.error) + (r.message ? ': ' + r.message : '');
          li.appendChild(document.createTextNode(' (' + t.failed + ', ' + why + ')'));
        }
        anyOk = anyOk || r.ok;
        list.appendChild(li);
      });
      preview.appendChild(list);
      status.textContent = t.done;
      if (anyOk) reloadTicket();
    }

    planBtn.addEventListener('click', function () {
      var prompt = input.value.trim();
      if (!prompt) return;
      reset();
      planBtn.disabled = true;
      status.textContent = t.thinking;
      post('/plan', { ticket_id: String(id), prompt: prompt }).then(function (data) {
        status.textContent = '';
        if (data.answer) answer.textContent = data.answer;
        showPreview(data);
      }).catch(function (err) {
        status.textContent = errorText(err.message);
      }).then(function () {
        planBtn.disabled = false;
      });
    });
  }

  // Shows the changed fields. The result list is lost on reload, the
  // internal note in the thread keeps it.
  function reloadTicket() {
    var $ = window.jQuery;
    window.setTimeout(function () {
      if ($ && $.pjax && document.getElementById('pjax-container')) {
        $.pjax.reload('#pjax-container', { timeout: 5000 });
      } else {
        window.location.reload();
      }
    }, 1200);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
  if (window.jQuery) {
    window.jQuery(document).on('pjax:end', mount);
  }
})();

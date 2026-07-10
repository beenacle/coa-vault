/**
 * All-COAs list — bulk "Read data with AI" backfill.
 *
 * Select rows → bulk action (or a row's "Read data" link) → each record's attached
 * certificate is re-read through the existing parse-only scan endpoint, one at a
 * time with progress — then ONE review table of proposed values, applied only on
 * an explicit click. Backfill-only by construction: the client proposes values
 * only for empty fields, and the server re-checks emptiness before writing, so a
 * bad parse can never overwrite good data.
 */
(function ($) {
  'use strict';
  if (!window.coaList) { return; }

  var i18n   = coaList.i18n;
  var FIELDS = ['batch', 'lab', 'date', 'purity', 'mass', 'verify'];
  var state  = null; // { items, results, cancelled, running }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
  }

  function fmt(template, n) { return String(template).replace('%d', n); }

  function $root() { return $('#coa-backfill-root'); }

  function rowData($cb) {
    try { return JSON.parse($cb.attr('data-coa') || ''); } catch (e) { return null; }
  }

  // Would an AI read have anything to fill on this record?
  function hasBlanks(item) {
    var c = item && item.cur;
    if (!c) { return false; }
    return c.batch === '' || c.lab === '' || !c.date || c.purity === null
      || c.mass === null || !c.verify || c.chars === 0;
  }

  // Proposals = scanned values for fields that are CURRENTLY empty. Nothing else.
  function proposalsFor(item, prefill) {
    var cur = item.cur, f = {};
    if (cur.batch === '' && prefill.batch) { f.batch = prefill.batch; }
    if (cur.lab === '' && prefill.lab && prefill.lab.label) { f.lab = prefill.lab.label; }
    if (!cur.date && prefill.analysis_date) { f.date = prefill.analysis_date; }
    if (cur.purity === null && prefill.purity_pct != null) { f.purity = prefill.purity_pct; }
    if (cur.mass === null && prefill.mass_mg != null) { f.mass = prefill.mass_mg; }
    if (!cur.verify && prefill.report && prefill.report.verify_url) { f.verify = prefill.report.verify_url; }
    var chars = (cur.chars === 0 && prefill.characteristics && prefill.characteristics.length)
      ? prefill.characteristics : null;
    return { fields: f, chars: chars };
  }

  function proposalCount(r) {
    if (!r.prop) { return 0; }
    return Object.keys(r.prop.fields).length + (r.prop.chars ? 1 : 0);
  }

  // ── Flow ─────────────────────────────────────────────────────────────────

  function startFlow(items) {
    if (state && state.running) { return; }
    var todo = (items || []).filter(function (it) { return it && it.file_id && hasBlanks(it); });
    if (!todo.length) { notice(i18n.nothingToDo); return; }
    if (!coaList.aiEnabled) { notice(i18n.aiOff); return; }
    if (!window.confirm(fmt(i18n.confirm, todo.length))) { return; }

    state = { items: todo, results: [], cancelled: false, running: true };
    renderProgress(0, todo[0]);
    next(0);
  }

  function next(idx) {
    if (state.cancelled || idx >= state.items.length) {
      state.running = false;
      renderReview();
      return;
    }
    var item = state.items[idx];
    renderProgress(idx, item);
    $.post(coaList.ajaxurl, {
      action: 'coa_scan_report',
      nonce: coaList.nonce,
      attachment_id: item.file_id,
      product_id: item.product_id
    }).done(function (res) {
      if (res && res.success && res.data && res.data.prefill) {
        // ai_used=false means the AI call itself failed (API down, oversize file) —
        // report it as a read failure, not as "the certificate holds nothing new".
        if (res.data.ai_used === false) {
          state.results.push({ item: item, error: i18n.readFail });
        } else {
          state.results.push({
            item: item,
            prop: proposalsFor(item, res.data.prefill),
            warning: res.data.warning || ''
          });
        }
      } else {
        state.results.push({ item: item, error: (res && res.data && res.data.message) || i18n.readFail });
      }
    }).fail(function (xhr) {
      state.results.push({
        item: item,
        error: (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || i18n.readFail
      });
    }).always(function () { next(idx + 1); });
  }

  // ── Rendering ────────────────────────────────────────────────────────────

  function notice(msg) {
    $root().html('<div class="coa-backfill-panel"><p>' + esc(msg) +
      ' <button type="button" class="button-link coa-bf-close">' + esc(i18n.close) + '</button></p></div>');
    scrollToPanel();
  }

  function renderProgress(idx, item) {
    var total = state.items.length;
    var $p = $root();
    if (!$p.find('.coa-bf-progress').length) {
      $p.html(
        '<div class="coa-backfill-panel coa-bf-progress">' +
          '<p class="coa-bf-head">' + esc(i18n.reading) + ' — <span class="coa-bf-count"></span></p>' +
          '<p class="coa-bf-current"></p>' +
          '<progress max="' + total + '" value="0"></progress>' +
          '<p><button type="button" class="button coa-bf-cancel">' + esc(i18n.cancel) + '</button></p>' +
        '</div>'
      );
      scrollToPanel();
    }
    $p.find('.coa-bf-count').text((idx + 1) + ' / ' + total);
    $p.find('.coa-bf-current').text(item ? item.title : '');
    $p.find('progress').attr('value', idx);
  }

  function cellHtml(r, idx, field) {
    if (r.prop && r.prop.fields[field] != null) {
      var val = r.prop.fields[field];
      var shown = field === 'verify' ? shortUrl(val) : (field === 'purity' ? val + '%' : val);
      return '<td><label title="' + esc(val) + '">' +
        '<input type="checkbox" checked data-idx="' + idx + '" data-field="' + field + '"> ' +
        '<span class="coa-bf-new">' + esc(shown) + '</span></label></td>';
    }
    // No proposal — show the record's current value, muted, for row context.
    var cur = r.item.cur[field];
    var curText = (cur === null || cur === '') ? '' : String(cur);
    if (curText && field === 'purity') { curText += '%'; }
    if (curText && field === 'verify') { curText = shortUrl(curText); }
    return '<td class="coa-bf-cur">' + (curText ? esc(curText) : '—') + '</td>';
  }

  function shortUrl(u) {
    try { return new URL(u).host; } catch (e) { return u; }
  }

  function renderReview() {
    var results = state.results;
    var total = results.reduce(function (n, r) { return n + proposalCount(r); }, 0);

    if (!total) {
      notice(i18n.noneProposed);
      return;
    }

    var head = state.cancelled ? '<p class="coa-bf-cancelled">' + esc(i18n.cancelled) + '</p>' : '';
    var html =
      '<div class="coa-backfill-panel">' + head +
        '<p class="coa-bf-head">' + esc(i18n.reviewHead) + '</p>' +
        '<div class="coa-bf-scroll"><table class="coa-bf-table widefat striped"><thead><tr>' +
          '<th>' + esc(i18n.cols.product) + '</th><th>' + esc(i18n.cols.batch) + '</th>' +
          '<th>' + esc(i18n.cols.lab) + '</th><th>' + esc(i18n.cols.date) + '</th>' +
          '<th>' + esc(i18n.cols.purity) + '</th><th>' + esc(i18n.cols.mass) + '</th>' +
          '<th>' + esc(i18n.cols.verify) + '</th><th>' + esc(i18n.cols.extras) + '</th>' +
        '</tr></thead><tbody>';

    results.forEach(function (r, idx) {
      html += '<tr><td class="coa-bf-title">' + esc(r.item.title);
      if (r.warning) { html += '<br><small class="coa-bf-warn">' + esc(r.warning) + '</small>'; }
      html += '</td>';

      if (r.error || !proposalCount(r)) {
        html += '<td colspan="7" class="coa-bf-cur">' + esc(r.error || i18n.nothingRead) + '</td></tr>';
        return;
      }
      FIELDS.forEach(function (f) { html += cellHtml(r, idx, f); });

      if (r.prop.chars) {
        var names = r.prop.chars.map(function (c) { return c.name + ': ' + c.value + (c.unit ? ' ' + c.unit : ''); }).join('\n');
        html += '<td><label title="' + esc(names) + '">' +
          '<input type="checkbox" checked data-idx="' + idx + '" data-field="chars"> ' +
          '<span class="coa-bf-new">' + esc(fmt(i18n.chars, r.prop.chars.length)) + '</span></label></td>';
      } else {
        html += '<td class="coa-bf-cur">—</td>';
      }
      html += '</tr>';
    });

    html += '</tbody></table></div>' +
      '<p class="coa-bf-actions">' +
        '<button type="button" class="button button-primary coa-bf-apply">' + esc(i18n.apply) + '</button> ' +
        '<button type="button" class="button coa-bf-close">' + esc(i18n.close) + '</button>' +
      '</p></div>';

    $root().html(html);
    scrollToPanel();
  }

  function scrollToPanel() {
    var el = $root().get(0);
    if (el && el.scrollIntoView) { el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
  }

  // ── Apply ────────────────────────────────────────────────────────────────

  function apply() {
    var $panel = $root();
    var items = [];
    state.results.forEach(function (r, idx) {
      if (!r.prop) { return; }
      var set = {}, chars = null, any = false;
      FIELDS.forEach(function (f) {
        if (r.prop.fields[f] != null &&
            $panel.find('input[data-idx="' + idx + '"][data-field="' + f + '"]').is(':checked')) {
          set[f] = r.prop.fields[f];
          any = true;
        }
      });
      if (r.prop.chars &&
          $panel.find('input[data-idx="' + idx + '"][data-field="chars"]').is(':checked')) {
        chars = r.prop.chars;
        any = true;
      }
      if (any) { items.push({ id: r.item.id, set: set, characteristics: chars || [] }); }
    });

    if (!items.length) { notice(i18n.nothingToDo); return; } // everything unticked ≠ silent close

    var $buttons = $panel.find('.coa-bf-apply, .coa-bf-close').prop('disabled', true);
    $panel.find('.coa-bf-apply').text(i18n.applying);

    $.post(coaList.ajaxurl, {
      action: 'coa_backfill_apply',
      nonce: coaList.nonce,
      items: JSON.stringify(items)
    }).done(function (res) {
      if (res && res.success) {
        $panel.html('<div class="coa-backfill-panel"><p class="coa-bf-done">' +
          esc(fmt(i18n.applied, res.data.applied)) + '</p></div>');
        window.setTimeout(function () { window.location.reload(); }, 900);
      } else {
        $buttons.prop('disabled', false);
        $panel.find('.coa-bf-apply').text(i18n.apply);
        window.alert((res && res.data && res.data.message) || i18n.applyFail);
      }
    }).fail(function () {
      $buttons.prop('disabled', false);
      $panel.find('.coa-bf-apply').text(i18n.apply);
      window.alert(i18n.applyFail);
    });
  }

  // ── Events ───────────────────────────────────────────────────────────────

  // Bulk action: intercept the native Apply when our action is selected.
  $(document).on('click', '#doaction, #doaction2', function (e) {
    var sel = this.id === 'doaction' ? '#bulk-action-selector-top' : '#bulk-action-selector-bottom';
    if ($(sel).val() !== 'coa_read_data') { return; }
    e.preventDefault();
    var items = [];
    $('input.coa-bf-cb:checked').each(function () {
      var d = rowData($(this));
      if (d) { items.push(d); }
    });
    startFlow(items);
  });

  // Per-row "Read data" link → same flow, one record.
  $(document).on('click', '.coa-read-row', function (e) {
    e.preventDefault();
    var d = rowData($(this).closest('tr').find('input.coa-bf-cb'));
    if (d) { startFlow([d]); }
  });

  $(document).on('click', '.coa-bf-cancel', function () {
    if (state) { state.cancelled = true; }
    $(this).prop('disabled', true);
  });

  $(document).on('click', '.coa-bf-close', function () {
    $root().empty();
  });

  $(document).on('click', '.coa-bf-apply', apply);
})(jQuery);

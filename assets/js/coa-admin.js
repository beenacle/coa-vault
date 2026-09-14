/* global jQuery, coaAdmin, wp */
(function ($) {
  'use strict';

  function ctx() {
    return $('#coa-admin');
  }

  // Escape values before they go into HTML attributes — characteristic name/value/unit
  // come from stored data and must not be able to break out of the value="" attribute.
  function escAttr(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  // A characteristic row: name / value / unit, plus the certificate's stated limit
  // ("spec") and its pass-fail verdict. `passed` arrives as true/false/null.
  function charRow(name, value, unit, spec, passed) {
    var cur = (passed === true || passed === 1 || passed === '1') ? '1'
            : (passed === false || passed === 0 || passed === '0') ? '0' : '';
    function opt(v, label) {
      return '<option value="' + v + '"' + (cur === v ? ' selected' : '') + '>' + escAttr(label) + '</option>';
    }
    return (
      '<p class="coa-char-row">' +
      '<input type="text" class="coa-c-name" placeholder="' + escAttr(coaAdmin.i18n.name) + '" value="' + escAttr(name) + '">' +
      '<input type="text" class="coa-c-value" placeholder="' + escAttr(coaAdmin.i18n.value) + '" value="' + escAttr(value) + '">' +
      '<input type="text" class="coa-c-unit" placeholder="' + escAttr(coaAdmin.i18n.unit) + '" value="' + escAttr(unit) + '">' +
      '<input type="text" class="coa-c-spec" placeholder="' + escAttr(coaAdmin.i18n.spec) + '" value="' + escAttr(spec) + '">' +
      '<select class="coa-c-passed" title="' + escAttr(coaAdmin.i18n.spec) + '">' +
        opt('', coaAdmin.i18n.resultNone) + opt('1', coaAdmin.i18n.pass) + opt('0', coaAdmin.i18n.fail) +
      '</select>' +
      '<button type="button" class="button-link coa-remove-char">' + escAttr(coaAdmin.i18n.remove) + '</button>' +
      '</p>'
    );
  }

  // Collapse the dashed drop zone into a media card (thumbnail + filename + meta).
  // PDFs use WP's first-page preview when available, else a document icon — never a
  // broken <img>.
  function renderMediaSet($form, rep) {
    rep = rep || {};
    if (!rep.file_id && !rep.url) { clearMediaSet($form); return; }
    var name = rep.filename || (rep.url ? rep.url.split('/').pop().split(/[?#]/)[0] : '');
    var isImg = rep.kind === 'image' || /\.(png|jpe?g|gif|webp|avif|svg)(\?|#|$)/i.test(rep.url || '');
    var thumb = rep.thumb_url || (isImg ? rep.url : '');
    var $thumb = $form.find('.coa-thumb').empty();
    if (thumb) {
      // Fall back to the document icon if the sized file 404s (offloaded media / missing size).
      $thumb.append($('<img>', { src: thumb, alt: '' }).on('error', function () {
        $thumb.empty().append($('<span>', { 'class': 'dashicons dashicons-media-document' }));
      }));
    } else {
      $thumb.append($('<span>', { 'class': 'dashicons dashicons-media-document' }));
    }
    $form.find('.coa-f-filename').text(name);
    var sub = [];
    if (rep.filesize) { sub.push(rep.filesize); }
    if (rep.kind) { sub.push(String(rep.kind).toUpperCase()); }
    $form.find('.coa-media-sub').text(sub.join(' · '));
    $form.find('.coa-drop').attr('hidden', true);
    $form.find('.coa-media-set').removeAttr('hidden');
    // Re-read only makes sense for an actual attached file; a link-only COA (external
    // report URL, no file_id) has nothing local to read. Inline display:none beats the
    // stylesheet's mobile display rule; '' hands control back to CSS.
    $form.find('.coa-reread').css('display', rep.file_id ? '' : 'none');
  }

  function clearMediaSet($form) {
    $form.find('.coa-f-fileid').val('');
    // Clear the report URL too: Report::resolve() reverse-maps a lone URL back to
    // the attachment id on save, which would silently resurrect a removed file.
    $form.find('.coa-f-url').val('');
    $form.find('.coa-thumb').empty();
    $form.find('.coa-f-filename').text('');
    $form.find('.coa-media-sub').text('');
    $form.find('.coa-media-set').attr('hidden', true);
    $form.find('.coa-drop').removeAttr('hidden');
  }

  // Visible add-vs-edit line under the form heading, derived from the hidden id so
  // every path (edit, scan merge, reset) reflects the mode that Save will act in.
  function updateModeIndicator($form) {
    var $el = $form.find('.coa-form-mode');
    if (!$el.length) { return; }
    var id = $form.find('.coa-f-id').val();
    $el.text(id ? coaAdmin.i18n.editingBatch.replace('%s', id) : '');
  }

  function resetForm($form) {
    $form.find('.coa-f-id').val('');
    updateModeIndicator($form);
    $form.find('input[type="text"], input[type="number"], input[type="url"], input[type="date"]').val('');
    $form.find('.coa-f-lab').val('');
    $form.find('.coa-f-date-raw').val('');
    $form.find('.coa-f-size-select').val('');
    $form.find('.coa-f-variation').val('');
    $form.find('.coa-f-chars-rows').empty();
    $form.find('.coa-scan-status').text('');
    $form.find('.coa-scan-warn, .coa-reread-review').remove();
    clearMediaSet($form);
  }

  // Fill the form from a record. merge=true (a scan pre-fill) only writes the fields
  // the scan actually read, so it never blanks values the admin typed first; merge
  // falsy (editing a saved COA) loads the record exactly.
  function populateForm($form, rec, merge) {
    function setIf($el, v) {
      if (merge && (v == null || v === '')) { return; }
      $el.val(v == null ? '' : v);
    }
    // The id survives a merge: a scan pre-fill landing while a record is being
    // edited (Replace / Media-Library pick on that record) belongs to THAT record —
    // blanking it silently flipped the form to add-mode and saved a duplicate.
    // Only a non-merge populate (loading a record, or the empty prefill of a fresh
    // add) sets the id outright.
    if (!merge) {
      $form.find('.coa-f-id').val(rec.id != null ? rec.id : '');
    }
    updateModeIndicator($form);

    // "Applies to": select the record's size; add a custom/legacy token if missing.
    var $size = $form.find('.coa-f-size-select');
    var tok = rec.size_token || '';
    if (tok) {
      if (!$size.find('option[value="' + tok + '"]').length) {
        // Carry the variation binding so re-selecting this option keeps it.
        $size.append($('<option>').val(tok).text(tok)
          .attr('data-variation-id', rec.variation_id != null ? rec.variation_id : ''));
      }
      $size.val(tok);
      $form.find('.coa-f-variation').val(rec.variation_id != null ? rec.variation_id : '');
    } else if (!merge) {
      $size.val('');
      $form.find('.coa-f-variation').val('');
    }

    setIf($form.find('.coa-f-batch'), rec.batch);
    setIf($form.find('.coa-f-lab'), rec.lab ? rec.lab.label : '');
    setIf($form.find('.coa-f-date'), rec.analysis_date);
    // The certificate's own date text, kept when it could not be read unambiguously.
    // Via setIf so a scan merge that read none can't blank an already-stored one.
    setIf($form.find('.coa-f-date-raw'), rec.analysis_date_raw);
    setIf($form.find('.coa-f-purity'), rec.purity_pct);
    setIf($form.find('.coa-f-mass'), rec.mass_mg);

    // The attached file reflects the latest attach; URL + verify go through setIf so
    // a merge (scan that read nothing for them) can't blank a hand-typed value.
    var rep = rec.report || {};
    $form.find('.coa-f-fileid').val(rep.file_id ? rep.file_id : '');
    setIf($form.find('.coa-f-url'), rep.url);
    setIf($form.find('.coa-f-verify'), rep.verify_url);

    // Characteristics: an EDIT loads every stored row (a legacy textual/off-unit
    // purity or mass row must survive the round-trip — dropping it here deleted it
    // on save); a scan MERGE hides purity/mass rows (they fold into the headline
    // fields) and replaces the repeater only when the scan found some.
    var chars = rec.characteristics || [];
    if (merge) {
      chars = chars.filter(function (c) { return c.name !== 'purity' && c.name !== 'mass'; });
    }
    if (!merge || chars.length) {
      var $rows = $form.find('.coa-f-chars-rows').empty();
      chars.forEach(function (c) { $rows.append(charRow(c.label || c.name, c.value, c.unit, c.spec, c.passed)); });
    }

    // Media card for any attached file OR external report URL (link-kind COAs have a
    // url but no file_id). Reveal Advanced when a URL/verify link is present so it is
    // not hidden in the collapsed section.
    if (rep.file_id || rep.url) {
      renderMediaSet($form, rep);
    } else if (!merge) {
      clearMediaSet($form);
    }
    if ((rep.url && rep.url !== '') || (rep.verify_url && rep.verify_url !== '')) {
      $form.find('.coa-advanced').attr('open', 'open');
    }
  }

  function collect($form) {
    var chars = [];
    $form.find('.coa-char-row').each(function () {
      var name = $(this).find('.coa-c-name').val();
      var value = $(this).find('.coa-c-value').val();
      if (!name && !value) { return; }
      chars.push({
        name: name,
        value: value,
        unit: $(this).find('.coa-c-unit').val(),
        spec: $(this).find('.coa-c-spec').val(),
        passed: $(this).find('.coa-c-passed').val()
      });
    });
    return {
      id: $form.find('.coa-f-id').val(),
      product_id: $form.data('product-id'),
      size_token: $form.find('.coa-f-size-select').val(),
      batch: $form.find('.coa-f-batch').val(),
      lab_label: $form.find('.coa-f-lab').val(),
      analysis_date: $form.find('.coa-f-date').val(),
      analysis_date_raw: $form.find('.coa-f-date-raw').val(),
      purity_pct: $form.find('.coa-f-purity').val(),
      mass_mg: $form.find('.coa-f-mass').val(),
      variation_id: $form.find('.coa-f-variation').val(),
      report_file_id: $form.find('.coa-f-fileid').val(),
      report_url: $form.find('.coa-f-url').val(),
      verify_url: $form.find('.coa-f-verify').val(),
      characteristics: chars
    };
  }

  // Process a dropped certificate in the browser: read its QR (jsQR) and, for an
  // oversized image, hand back a downscaled JPEG so big phone photos stay under the
  // API's per-image limit (and the stored report image stays sensible). PDFs and
  // small images upload as-is. cb({ qr, upload, name }).
  function processCertificate(file, cb) {
    if (!file || !/^image\//.test(file.type) || typeof window.jsQR !== 'function') {
      cb({ qr: null, upload: file, name: file ? file.name : 'report' });
      return;
    }
    // ~over the 10MB-base64 image budget once encoded (raw × 4/3).
    var oversized = file.size > 4 * 1024 * 1024;
    var url = URL.createObjectURL(file);
    var img = new Image();
    img.onload = function () {
      var done = function (qr, upload, name) { URL.revokeObjectURL(url); cb({ qr: qr, upload: upload, name: name }); };
      try {
        var scale = Math.min(1, 2000 / Math.max(img.width, img.height));
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(img.width * scale);
        canvas.height = Math.round(img.height * scale);
        var cx = canvas.getContext('2d');
        cx.drawImage(img, 0, 0, canvas.width, canvas.height);
        var pixels = cx.getImageData(0, 0, canvas.width, canvas.height);
        var res = window.jsQR(pixels.data, pixels.width, pixels.height);
        var qr = res && res.data ? res.data : null;
        if (!oversized || typeof canvas.toBlob !== 'function') {
          done(qr, file, file.name);
          return;
        }
        canvas.toBlob(function (blob) {
          if (blob) {
            done(qr, blob, file.name.replace(/\.[^.]+$/, '') + '.jpg');
          } else {
            done(qr, file, file.name);
          }
        }, 'image/jpeg', 0.85);
      } catch (e) {
        done(null, file, file.name);
      }
    };
    img.onerror = function () { URL.revokeObjectURL(url); cb({ qr: null, upload: file, name: file.name }); };
    img.src = url;
  }

  $(function () {
    var $root = ctx();
    if (!$root.length) { return; }
    var $form = $root.find('.coa-admin-form');
    var scanning = false;

    // Lock the certificate zone while a scan is in flight: the `is-scanning` class
    // dims + disables the drop zone (CSS) and we disable its buttons, so a second
    // click or drop can't fire a duplicate upload/read.
    function setBusy(on) {
      scanning = on;
      $form.toggleClass('is-scanning', on);
      // Save/Cancel lock too: saving mid-scan would store the record without the
      // file, then the late scan response re-fills a form the admin thinks is done.
      $form.find('.coa-upload, .coa-replace, .coa-pick-media, .coa-reread, .coa-save, .coa-cancel')
        .prop('disabled', on);
    }

    // Server-side error messages (400/403 responses) land in jQuery's fail path.
    function failMessage(xhr, fallback) {
      return (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || fallback;
    }

    function openPicker() {
      if (scanning) { return; }
      $form.find('.coa-scan-input').val('').trigger('click');
    }

    function applyScan(res) {
      var $status = $form.find('.coa-scan-status');
      if (res && res.success) {
        populateForm($form, res.data.prefill, true); // merge: keep anything already typed
        var msg = res.data.ai_used ? coaAdmin.i18n.scanDone : coaAdmin.i18n.scanManual;
        if (res.data.peptide) { msg += ' — ' + res.data.peptide; }
        $status.text(msg);
        if (res.data.warning) {
          $('<div class="coa-scan-warn"></div>').text(res.data.warning).insertAfter($status);
        }
        // The control that had focus (Upload / Media Library) is now hidden — move
        // focus to the visible Replace button so keyboard focus is not lost.
        var $replace = $form.find('.coa-replace');
        if ($replace.is(':visible')) { $replace.trigger('focus'); }
        $('html, body').animate({ scrollTop: $form.offset().top - 60 }, 200);
      } else {
        $status.text((res && res.data && res.data.message) || coaAdmin.i18n.scanFail);
      }
    }

    // Pin the record the scan was started against: if the admin switches to editing
    // a different record while the (multi-second) read is in flight, the stale
    // response must not merge this file's figures into that other record.
    function pinnedApplyScan() {
      var editId = $form.find('.coa-f-id').val();
      return function (res) {
        if ($form.find('.coa-f-id').val() !== editId) { return; }
        applyScan(res);
      };
    }

    // Upload / drop a local file: read the QR + fields, attach it, and pre-fill.
    function setReportFromLocalFile(file) {
      if (!file || scanning) { return; }
      $form.find('.coa-scan-warn, .coa-reread-review').remove();
      // Announced to screen readers (aria-live); hidden visually during the scan since the
      // in-box spinner is the visual cue (CSS .is-scanning).
      var $status = $form.find('.coa-scan-status').text(coaAdmin.i18n.scanning);
      var onDone = pinnedApplyScan();
      setBusy(true);
      processCertificate(file, function (r) {
        var fd = new FormData();
        fd.append('action', 'coa_scan_report');
        fd.append('nonce', coaAdmin.nonce);
        fd.append('product_id', $form.data('product-id'));
        fd.append('report', r.upload, r.name);
        if (r.qr && /^https?:\/\//i.test(r.qr)) { fd.append('qr_url', r.qr); }
        $.ajax({ url: coaAdmin.ajaxurl, method: 'POST', data: fd, processData: false, contentType: false })
          .done(onDone)
          .fail(function (xhr) { $status.text(failMessage(xhr, coaAdmin.i18n.scanFail)); })
          .always(function () { setBusy(false); });
      });
    }

    // Read an existing Media Library file by id. `onDone` defaults to the scan pre-fill
    // (Media Library pick); the Re-read action passes its own handler so the same read
    // routes to a review/diff instead of merging blindly into the form.
    function setReportFromAttachment(id, onDone) {
      if (scanning) { return; }
      // Drop any open re-read panel: it holds the PREVIOUS file's diff and must not
      // survive a file swap (the Re-read flow rebuilds its own panel afterward).
      $form.find('.coa-scan-warn, .coa-reread-review').remove();
      $form.find('.coa-scan-status').text(coaAdmin.i18n.scanning);
      setBusy(true);
      $.post(coaAdmin.ajaxurl, {
        action: 'coa_scan_report',
        nonce: coaAdmin.nonce,
        product_id: $form.data('product-id'),
        attachment_id: id
      })
        .done(onDone || pinnedApplyScan())
        .fail(function (xhr) { $form.find('.coa-scan-status').text(failMessage(xhr, coaAdmin.i18n.scanFail)); })
        .always(function () { setBusy(false); });
    }

    // Field map for the Re-read review: the scalar figures a person would fix on an old
    // COA. Size / variation and the attached file are intentionally NOT touched — those
    // are set deliberately per record, not read off the page.
    //
    // The verify / source link is deliberately EXCLUDED: a re-read reads the file by id
    // and never re-decodes the QR (that only happens in the browser for a freshly-dropped
    // image), so the link would come purely from OCR of the printed URL — which for some
    // labs (e.g. AccuMark's "/CODE" form) is the wrong/404 variant and would clobber the
    // correct QR-derived link captured at first scan. Re-read is for the figures only.
    var REREAD_FIELDS = [
      { key: 'batch',  sel: '.coa-f-batch',  get: function (p) { return p.batch; } },
      { key: 'lab',    sel: '.coa-f-lab',    get: function (p) { return p.lab ? p.lab.label : ''; } },
      { key: 'date',   sel: '.coa-f-date',   get: function (p) { return p.analysis_date; } },
      { key: 'purity', sel: '.coa-f-purity', get: function (p) { return p.purity_pct; }, num: true },
      { key: 'mass',   sel: '.coa-f-mass',   get: function (p) { return p.mass_mg; }, num: true }
    ];

    // The characteristics currently in the form, normalized for a set comparison.
    function currentChars() {
      var out = [];
      $form.find('.coa-char-row').each(function () {
        var name = $.trim($(this).find('.coa-c-name').val() || '');
        var value = $.trim($(this).find('.coa-c-value').val() || '');
        if (!name && !value) { return; }
        out.push({ name: name, value: value, unit: $.trim($(this).find('.coa-c-unit').val() || '') });
      });
      return out;
    }
    // Collapse a value that is a clean number to its canonical form ("10.0" -> "10") so
    // trailing-zero formatting doesn't read as a change (a saved value_num round-trips
    // through JSON stripped, while the AI returns the printed string). Anything not purely
    // numeric ("<0.1", "10 mg", "N/A") is left untouched.
    function canonNum(v) {
      v = (v == null) ? '' : String(v).trim();
      if (v !== '' && /^[+-]?(\d+\.?\d*|\.\d+)$/.test(v)) {
        var n = parseFloat(v);
        if (!isNaN(n) && isFinite(n)) { return String(n); }
      }
      return v;
    }
    function charsKey(list) {
      return (list || []).map(function (c) {
        return (c.label || c.name || '') + ' ' + canonNum(c.value) + ' ' + (c.unit || '');
      }).sort().join('|');
    }

    // Show a review/diff after a Re-read: one checked row per field the AI read that
    // DIFFERS from the saved value (empty fields it fills, wrong values it corrects).
    // Nothing is written until "Apply selected" — the record is still saved by the
    // normal "Save batch", so "review, not blind overwrite" holds.
    function showRereadReview(res) {
      var t = coaAdmin.i18n.reread;
      var $status = $form.find('.coa-scan-status');
      $form.find('.coa-reread-review, .coa-scan-warn').remove();
      if (!res || !res.success) {
        $status.text((res && res.data && res.data.message) || coaAdmin.i18n.scanFail);
        return;
      }
      var data = res.data || {};
      if (data.warning) {
        $('<div class="coa-scan-warn"></div>').text(data.warning).insertAfter($status);
      }
      if (!data.ai_used) { $status.text(t.off); return; }
      var prefill = data.prefill || {};

      var rows = [];
      REREAD_FIELDS.forEach(function (f) {
        var neu = f.get(prefill);
        neu = (neu == null) ? '' : String(neu);
        if (neu === '') { return; } // the AI read nothing for this field — leave it alone
        var cur = $form.find(f.sel).val();
        cur = (cur == null) ? '' : String(cur);
        var same = f.num
          ? (cur !== '' && parseFloat(cur) === parseFloat(neu))
          : ($.trim(cur) === $.trim(neu));
        if (same) { return; }
        rows.push({ field: f, cur: cur, neu: neu });
      });

      var newChars = (prefill.characteristics || []).filter(function (c) {
        return c.name !== 'purity' && c.name !== 'mass';
      });
      var charsChanged = newChars.length > 0 && charsKey(newChars) !== charsKey(currentChars());

      if (!rows.length && !charsChanged) { $status.text(t.none); return; }

      var $panel = $('<div class="coa-reread-review"></div>');
      $panel.append($('<p class="coa-reread-head"></p>').text(t.head));
      var $tbl = $('<table class="coa-reread-table"></table>');
      rows.forEach(function (r) {
        var $chk = $('<input type="checkbox" checked>').data('row', r);
        var $tr = $('<tr></tr>');
        $tr.append($('<td></td>').append(
          $('<label></label>').append($chk).append(document.createTextNode(' ' + (t.fields[r.field.key] || r.field.key)))
        ));
        $tr.append($('<td class="coa-reread-vals"></td>')
          .append($('<span class="coa-reread-old"></span>').text(r.cur === '' ? t.empty : r.cur))
          .append(document.createTextNode(' → '))
          .append($('<span class="coa-reread-new"></span>').text(r.neu)));
        $tbl.append($tr);
      });
      if (charsChanged) {
        // Left UNCHECKED by default: applying it replaces ALL characteristic rows
        // wholesale, which would drop any the admin typed by hand — so it needs a
        // deliberate tick, unlike the per-field scalar rows which are safe individual writes.
        var $cchk = $('<input type="checkbox">').data('chars', newChars);
        var $ctr = $('<tr></tr>');
        $ctr.append($('<td></td>').append(
          $('<label></label>').append($cchk).append(document.createTextNode(' ' + t.chars))
        ));
        $ctr.append($('<td class="coa-reread-vals"></td>').text('(' + newChars.length + ')'));
        $tbl.append($ctr);
      }
      $panel.append($tbl);
      $panel.append($('<p></p>')
        .append($('<button type="button" class="button button-primary coa-reread-apply"></button>').text(t.apply))
        .append(document.createTextNode(' '))
        .append($('<button type="button" class="button coa-reread-cancel"></button>').text(t.cancel)));
      // A labeled, focusable group: the Re-read button disabled itself (focus fell to
      // <body>), so move focus into the panel — announcing its label to a screen reader
      // and putting a keyboard user on the changes instead of stranding them at page top.
      $panel.attr({ role: 'group', 'aria-label': t.head, tabindex: '-1' });
      $form.find('.coa-media').after($panel);
      $status.text('');
      $panel.trigger('focus');
    }

    // Upload and Replace open the native file picker; the drop zone is a plain
    // (mouse-only) drag target — the two real buttons carry the keyboard path.
    $root.on('click', '.coa-upload, .coa-replace', function (e) { e.preventDefault(); openPicker(); });
    $root.on('change', '.coa-scan-input', function () {
      setReportFromLocalFile(this.files && this.files[0]);
    });

    // Media Library: choose an existing image/PDF, then attach + read it.
    $root.on('click', '.coa-pick-media', function (e) {
      e.preventDefault();
      var frame = wp.media({
        title: coaAdmin.i18n.selectReport,
        library: { type: ['image', 'application/pdf'] },
        multiple: false
      });
      frame.on('select', function () {
        var att = frame.state().get('selection').first().toJSON();
        setReportFromAttachment(att.id);
      });
      frame.open();
    });

    // Re-read the already-attached file with AI and show a review/diff of what changed.
    $root.on('click', '.coa-reread', function (e) {
      e.preventDefault();
      var id = $form.find('.coa-f-fileid').val();
      if (!id || scanning) { return; }
      // Pin the record + file this read belongs to. If the admin switches records or
      // swaps the file while the (multi-second) read is in flight, discard the stale
      // response instead of diffing this file's figures against a different record.
      var editId = $form.find('.coa-f-id').val();
      $form.find('.coa-reread-review').remove();
      setReportFromAttachment(id, function (res) {
        if ($form.find('.coa-f-id').val() !== editId || $form.find('.coa-f-fileid').val() !== id) { return; }
        showRereadReview(res);
      });
    });

    // Apply the ticked changes into the form (still not saved — the user clicks Save batch).
    $root.on('click', '.coa-reread-apply', function (e) {
      e.preventDefault();
      var $panel = $(this).closest('.coa-reread-review');
      $panel.find('input[type="checkbox"]:checked').each(function () {
        var row = $(this).data('row');
        var chars = $(this).data('chars');
        if (row) {
          $form.find(row.field.sel).val(row.neu);
        } else if (chars) {
          var $rows = $form.find('.coa-f-chars-rows').empty();
          chars.forEach(function (c) { $rows.append(charRow(c.label || c.name, c.value, c.unit, c.spec, c.passed)); });
        }
      });
      $panel.remove();
      $form.find('.coa-scan-status').text(coaAdmin.i18n.reread.applied);
    });

    $root.on('click', '.coa-reread-cancel', function (e) {
      e.preventDefault();
      $(this).closest('.coa-reread-review').remove();
      $form.find('.coa-scan-status').text('');
    });

    // Remove the attached file (the Media Library copy itself is kept).
    $root.on('click', '.coa-remove-media', function (e) {
      e.preventDefault();
      clearMediaSet($form);
      $form.find('.coa-scan-status').text('');
      $form.find('.coa-reread-review').remove(); // its diff refers to the now-removed file
      // The Remove button just disappeared with the card — land focus on a real
      // control (the drop zone is an unfocusable div).
      $form.find('.coa-upload').trigger('focus');
    });

    // Drag and drop onto the zone. The relatedTarget guard avoids highlight flicker
    // as the cursor crosses child nodes.
    $root.on('dragenter dragover', '.coa-drop', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (e.originalEvent && e.originalEvent.dataTransfer) { e.originalEvent.dataTransfer.dropEffect = 'copy'; }
      $(this).addClass('is-dragover');
    });
    $root.on('dragleave', '.coa-drop', function (e) {
      var rt = e.originalEvent && e.originalEvent.relatedTarget;
      if (rt && this.contains(rt)) { return; }
      $(this).removeClass('is-dragover');
    });
    $root.on('drop', '.coa-drop', function (e) {
      e.preventDefault();
      e.stopPropagation();
      $(this).removeClass('is-dragover');
      if (scanning) { return; }
      var dt = e.originalEvent && e.originalEvent.dataTransfer;
      var file = dt && dt.files && dt.files[0];
      if (file && /^(image\/|application\/pdf)/.test(file.type)) {
        setReportFromLocalFile(file);
      }
    });
    // A near-miss drop anywhere in the metabox must not navigate away (which would
    // lose the unsaved form). Only suppress the default inside #coa-admin.
    $(document).on('dragover drop', function (e) {
      if ($(e.target).closest('#coa-admin').length) { e.preventDefault(); }
    });

    // Picking a size auto-fills the (hidden) variation id from the chosen option.
    // Choosing a real date resolves the ambiguity — drop the kept certificate text.
    $root.on('change', '.coa-f-date', function () {
      if ($(this).val()) { $form.find('.coa-f-date-raw').val(''); }
    });

    $root.on('change', '.coa-f-size-select', function () {
      var vid = $(this).find('option:selected').data('variation-id');
      $form.find('.coa-f-variation').val(vid ? vid : '');
    });

    $root.on('click', '.coa-add-char', function () {
      $form.find('.coa-f-chars-rows').append(charRow());
    });
    $root.on('click', '.coa-remove-char', function () {
      $(this).closest('.coa-char-row').remove();
    });

    $root.on('click', '.coa-edit', function () {
      var rec = $(this).closest('tr').data('record');
      $form.find('.coa-reread-review, .coa-scan-warn').remove();
      $form.find('.coa-scan-status').text('');
      populateForm($form, rec);
      $('html, body').animate({ scrollTop: $form.offset().top - 60 }, 200);
    });

    $root.on('click', '.coa-cancel', function () {
      resetForm($form);
    });

    $root.on('click', '.coa-save', function () {
      var $btn = $(this);
      if ($btn.prop('disabled')) { return; }
      // In-flight lock: a double-click on a slow connection saved two records.
      $btn.prop('disabled', true);
      var $spin = $form.find('.spinner').addClass('is-active');
      $.post(coaAdmin.ajaxurl, {
        action: 'coa_save_batch',
        nonce: coaAdmin.nonce,
        coa: collect($form)
      })
        .done(function (res) {
          $root.find('.coa-admin-list').html(res.data.list_html);
          resetForm($form);
        })
        // Server errors respond 4xx (nonce expiry, validation) — jQuery routes
        // them here, so without this handler a failed save was completely silent.
        .fail(function (xhr) { window.alert(failMessage(xhr, coaAdmin.i18n.saveFail)); })
        .always(function () {
          $spin.removeClass('is-active');
          $btn.prop('disabled', false);
        });
    });

    $root.on('click', '.coa-delete', function () {
      if (!window.confirm(coaAdmin.i18n.confirmDelete)) { return; }
      $.post(coaAdmin.ajaxurl, {
        action: 'coa_delete_batch',
        nonce: coaAdmin.nonce,
        id: $(this).data('id'),
        product_id: $form.data('product-id')
      })
        .done(function (res) {
          $root.find('.coa-admin-list').html(res.data.list_html);
        })
        .fail(function (xhr) { window.alert(failMessage(xhr, coaAdmin.i18n.deleteFail)); });
    });
  });
})(jQuery);

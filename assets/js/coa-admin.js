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

  function charRow(name, value, unit) {
    return (
      '<p class="coa-char-row">' +
      '<input type="text" class="coa-c-name" placeholder="' + escAttr(coaAdmin.i18n.name) + '" value="' + escAttr(name) + '">' +
      '<input type="text" class="coa-c-value" placeholder="' + escAttr(coaAdmin.i18n.value) + '" value="' + escAttr(value) + '">' +
      '<input type="text" class="coa-c-unit" placeholder="' + escAttr(coaAdmin.i18n.unit) + '" value="' + escAttr(unit) + '">' +
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
  }

  function clearMediaSet($form) {
    $form.find('.coa-f-fileid').val('');
    $form.find('.coa-thumb').empty();
    $form.find('.coa-f-filename').text('');
    $form.find('.coa-media-sub').text('');
    $form.find('.coa-media-set').attr('hidden', true);
    $form.find('.coa-drop').removeAttr('hidden');
  }

  function resetForm($form) {
    $form.find('.coa-f-id').val('');
    $form.find('input[type="text"], input[type="number"], input[type="url"], input[type="date"]').val('');
    $form.find('.coa-f-lab').val('');
    $form.find('.coa-f-size-select').val('');
    $form.find('.coa-f-variation').val('');
    $form.find('.coa-f-chars-rows').empty();
    $form.find('.coa-scan-status').text('');
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
    // The id is never merged: a scan pre-fill (id '') must start a NEW record even if
    // the form was mid-edit, or it would silently overwrite the record being edited.
    $form.find('.coa-f-id').val(rec.id != null ? rec.id : '');

    // "Applies to": select the record's size; add a custom/legacy token if missing.
    var $size = $form.find('.coa-f-size-select');
    var tok = rec.size_token || '';
    if (tok) {
      if (!$size.find('option[value="' + tok + '"]').length) {
        $size.append($('<option>').val(tok).text(tok));
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
    setIf($form.find('.coa-f-purity'), rec.purity_pct);
    setIf($form.find('.coa-f-mass'), rec.mass_mg);

    // The report state always reflects the latest attach / the edited record.
    var rep = rec.report || {};
    $form.find('.coa-f-fileid').val(rep.file_id ? rep.file_id : '');
    $form.find('.coa-f-url').val(rep.url || '');
    $form.find('.coa-f-verify').val(rep.verify_url || '');

    // Characteristics: replace on edit; on a scan, replace only when the scan found some.
    var chars = (rec.characteristics || []).filter(function (c) { return c.name !== 'purity' && c.name !== 'mass'; });
    if (!merge || chars.length) {
      var $rows = $form.find('.coa-f-chars-rows').empty();
      chars.forEach(function (c) { $rows.append(charRow(c.label || c.name, c.value, c.unit)); });
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
      chars.push({ name: name, value: value, unit: $(this).find('.coa-c-unit').val() });
    });
    return {
      id: $form.find('.coa-f-id').val(),
      product_id: $form.data('product-id'),
      size_token: $form.find('.coa-f-size-select').val(),
      batch: $form.find('.coa-f-batch').val(),
      lab_label: $form.find('.coa-f-lab').val(),
      analysis_date: $form.find('.coa-f-date').val(),
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

    function openPicker() {
      $form.find('.coa-scan-input').val('').trigger('click');
    }

    function applyScan(res) {
      var $status = $form.find('.coa-scan-status');
      if (res && res.success) {
        populateForm($form, res.data.prefill, true); // merge: keep anything already typed
        var msg = res.data.ai_used ? coaAdmin.i18n.scanDone : coaAdmin.i18n.scanManual;
        if (res.data.peptide) { msg += ' — ' + res.data.peptide; }
        $status.text(msg);
        // The control that had focus (Upload / Media Library) is now hidden — move
        // focus to the visible Replace button so keyboard focus is not lost.
        var $replace = $form.find('.coa-replace');
        if ($replace.is(':visible')) { $replace.trigger('focus'); }
        $('html, body').animate({ scrollTop: $form.offset().top - 60 }, 200);
      } else {
        $status.text((res && res.data && res.data.message) || coaAdmin.i18n.scanFail);
      }
    }

    // Upload / drop a local file: read the QR + fields, attach it, and pre-fill.
    function setReportFromLocalFile(file) {
      if (!file) { return; }
      var $status = $form.find('.coa-scan-status').text(coaAdmin.i18n.scanning);
      var $busy = $form.find('.coa-upload, .coa-replace').prop('disabled', true);
      processCertificate(file, function (r) {
        var fd = new FormData();
        fd.append('action', 'coa_scan_report');
        fd.append('nonce', coaAdmin.nonce);
        fd.append('product_id', $form.data('product-id'));
        fd.append('report', r.upload, r.name);
        if (r.qr && /^https?:\/\//i.test(r.qr)) { fd.append('qr_url', r.qr); }
        $.ajax({ url: coaAdmin.ajaxurl, method: 'POST', data: fd, processData: false, contentType: false })
          .done(applyScan)
          .fail(function () { $status.text(coaAdmin.i18n.scanFail); })
          .always(function () { $busy.prop('disabled', false); });
      });
    }

    // Pick an existing Media Library file: attach it and read its fields by id.
    function setReportFromAttachment(id) {
      $form.find('.coa-scan-status').text(coaAdmin.i18n.scanning);
      $.post(coaAdmin.ajaxurl, {
        action: 'coa_scan_report',
        nonce: coaAdmin.nonce,
        product_id: $form.data('product-id'),
        attachment_id: id
      })
        .done(applyScan)
        .fail(function () { $form.find('.coa-scan-status').text(coaAdmin.i18n.scanFail); });
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

    // Remove the attached file (the Media Library copy itself is kept).
    $root.on('click', '.coa-remove-media', function (e) {
      e.preventDefault();
      clearMediaSet($form);
      $form.find('.coa-scan-status').text('');
      $form.find('.coa-drop').trigger('focus');
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
      populateForm($form, rec);
      $('html, body').animate({ scrollTop: $form.offset().top - 60 }, 200);
    });

    $root.on('click', '.coa-cancel', function () {
      resetForm($form);
    });

    $root.on('click', '.coa-save', function () {
      var $spin = $form.find('.spinner').addClass('is-active');
      $.post(coaAdmin.ajaxurl, {
        action: 'coa_save_batch',
        nonce: coaAdmin.nonce,
        coa: collect($form)
      })
        .done(function (res) {
          if (res && res.success) {
            $root.find('.coa-admin-list').html(res.data.list_html);
            resetForm($form);
          } else {
            window.alert((res && res.data && res.data.message) || 'Save failed');
          }
        })
        .always(function () { $spin.removeClass('is-active'); });
    });

    $root.on('click', '.coa-delete', function () {
      if (!window.confirm('Delete this COA batch?')) { return; }
      $.post(coaAdmin.ajaxurl, {
        action: 'coa_delete_batch',
        nonce: coaAdmin.nonce,
        id: $(this).data('id'),
        product_id: $form.data('product-id')
      }).done(function (res) {
        if (res && res.success) {
          $root.find('.coa-admin-list').html(res.data.list_html);
        }
      });
    });
  });
})(jQuery);

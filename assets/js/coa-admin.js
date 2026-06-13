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

  function resetForm($form) {
    $form.find('.coa-f-id').val('');
    $form.find('input[type="text"], input[type="number"], input[type="url"], input[type="date"]').val('');
    $form.find('.coa-f-lab').val('');
    $form.find('.coa-f-size-select').val('');
    $form.find('.coa-f-variation').val('');
    $form.find('.coa-f-fileid').val('');
    $form.find('.coa-f-filename').text('');
    $form.find('.coa-f-chars-rows').empty();
  }

  function populateForm($form, rec) {
    $form.find('.coa-f-id').val(rec.id);
    // "Applies to" dropdown: select the record's size; if it predates the current
    // variations (or is a custom token) add it so editing never loses the value.
    var $size = $form.find('.coa-f-size-select');
    var tok = rec.size_token || '';
    if (tok && !$size.find('option[value="' + tok + '"]').length) {
      $size.append($('<option>').val(tok).text(tok));
    }
    $size.val(tok);
    $form.find('.coa-f-variation').val(rec.variation_id != null ? rec.variation_id : '');
    $form.find('.coa-f-batch').val(rec.batch || '');
    $form.find('.coa-f-lab').val(rec.lab ? (rec.lab.label || '') : '');
    $form.find('.coa-f-date').val(rec.analysis_date || '');
    $form.find('.coa-f-purity').val(rec.purity_pct != null ? rec.purity_pct : '');
    $form.find('.coa-f-mass').val(rec.mass_mg != null ? rec.mass_mg : '');
    $form.find('.coa-f-fileid').val(rec.report && rec.report.file_id ? rec.report.file_id : '');
    $form.find('.coa-f-url').val(rec.report ? rec.report.url : '');
    $form.find('.coa-f-verify').val(rec.report && rec.report.verify_url ? rec.report.verify_url : '');
    var $rows = $form.find('.coa-f-chars-rows').empty();
    (rec.characteristics || []).forEach(function (c) {
      if (c.name === 'purity' || c.name === 'mass') { return; }
      $rows.append(charRow(c.label || c.name, c.value, c.unit));
    });
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

  $(function () {
    var $root = ctx();
    if (!$root.length) { return; }
    var $form = $root.find('.coa-admin-form');

    // Picking a size auto-fills the (hidden) variation id from the chosen option,
    // so admins never type a token or hunt for a variation id.
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

    $root.on('click', '.coa-pick-media', function (e) {
      e.preventDefault();
      var frame = wp.media({ title: 'Select report', multiple: false });
      frame.on('select', function () {
        var att = frame.state().get('selection').first().toJSON();
        $form.find('.coa-f-fileid').val(att.id);
        $form.find('.coa-f-filename').text(att.filename || att.url);
      });
      frame.open();
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

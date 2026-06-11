/* global jQuery, coaVault */
(function ($) {
  'use strict';

  // Lazy-fetch the rendered COA for the selected variation and swap it in.
  // We bind delegated so it survives themes/quick-views that re-render the form.
  $(document)
    .on('found_variation', 'form.variations_form', function (event, variation) {
      var $form = $(this);
      var $wrap = $form.closest('.product').find('.coa-vault-wrap');
      if (!$wrap.length) {
        return;
      }
      var productId = $wrap.data('product-id') || $form.data('product_id');
      if (!productId) {
        return;
      }
      var size = variation && variation.coa ? variation.coa.size || '' : '';
      var url =
        coaVault.rest +
        'products/' +
        encodeURIComponent(productId) +
        '/resolve?variation_id=' +
        encodeURIComponent(variation.variation_id) +
        '&size=' +
        encodeURIComponent(size);

      $wrap.addClass('is-loading');
      $.ajax({
        url: url,
        method: 'GET',
        headers: { 'X-WP-Nonce': coaVault.nonce }
      })
        .done(function (res) {
          $wrap.html(res && res.html ? res.html : '');
        })
        .always(function () {
          $wrap.removeClass('is-loading');
        });
    })
    .on('reset_data', 'form.variations_form', function () {
      // Selection cleared — restore the product-level default that was rendered server-side.
      var $wrap = $(this).closest('.product').find('.coa-vault-wrap');
      if ($wrap.data('initial') === undefined) {
        return;
      }
      $wrap.html($wrap.data('initial'));
    });

  // Remember the server-rendered product-level content so reset_data can restore it.
  $(function () {
    $('.coa-vault-wrap').each(function () {
      $(this).data('initial', $(this).html());
    });
  });
})(jQuery);

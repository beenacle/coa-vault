/* global jQuery, coaVault */
(function ($) {
  'use strict';

  // Swap the wrap to the given URL's rendered COA. Sends the REST nonce so
  // logged-in editors keep draft-product preview; on failure — typically a stale
  // nonce baked into a full-page-cached product page older than the nonce
  // lifetime, which core rejects with 403 even on public routes — retries once
  // anonymously, and as a last resort restores the server-rendered content.
  // In-flight requests are aborted so the latest selection always wins.
  function fetchCoa($wrap, url, withNonce) {
    var pending = $wrap.data('coaXhr');
    if (pending && pending.abort) {
      pending.abort();
    }
    var xhr = $.ajax({
      url: url,
      method: 'GET',
      headers: withNonce ? { 'X-WP-Nonce': coaVault.nonce } : {}
    });
    $wrap.data('coaXhr', xhr);
    xhr
      .done(function (res) {
        $wrap.html(res && res.html ? res.html : '');
      })
      .fail(function (jqXhr, status) {
        if (status === 'abort') {
          return;
        }
        if (withNonce) {
          fetchCoa($wrap, url, false);
          return;
        }
        var initial = $wrap.data('initial');
        if (initial !== undefined) {
          $wrap.html(initial);
        }
      })
      .always(function () {
        // Only clear state if no newer request has replaced this one.
        if ($wrap.data('coaXhr') === xhr) {
          $wrap.removeData('coaXhr').removeClass('is-loading').attr('aria-busy', 'false');
        }
      });
  }

  // Lazy-fetch the rendered COA for the selected variation and swap it in.
  // We bind delegated so it survives themes/quick-views that re-render the form.
  $(document)
    .on('found_variation', 'form.variations_form', function (event, variation) {
      var $form = $(this);
      var $wrap = $form.closest('.product').find('.coa-vault-wrap');
      if (!$wrap.length || !variation) {
        return;
      }
      var productId = $wrap.data('product-id') || $form.data('product_id');
      if (!productId) {
        return;
      }
      var size = variation.coa ? variation.coa.size || '' : '';
      var url =
        coaVault.rest +
        'products/' +
        encodeURIComponent(productId) +
        '/resolve?variation_id=' +
        encodeURIComponent(variation.variation_id) +
        '&size=' +
        encodeURIComponent(size);

      $wrap.addClass('is-loading').attr('aria-busy', 'true');
      fetchCoa($wrap, url, true);
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

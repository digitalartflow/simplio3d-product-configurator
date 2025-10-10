(function(){
  if (typeof Simplio3DWoo === 'undefined') return;

  function originAllowed(origin){
    var allowed = Simplio3DWoo.allowed_origins || [];
    if (!allowed || !allowed.length) return true; // allow all if not configured
    return allowed.indexOf(origin) !== -1;
  }

  function showWooNotice(message, type) {
    var wrapper = document.querySelector('.woocommerce-notices-wrapper');
    if (!wrapper) {
      wrapper = document.createElement('div');
      wrapper.className = 'woocommerce-notices-wrapper';
      // put it near the top of the content area
      var main = document.querySelector('main') || document.querySelector('#primary') || document.body;
      main.insertBefore(wrapper, main.firstChild);
    }

    var div = document.createElement('div');
    div.className = 'woocommerce-' + (type === 'error' ? 'error' : 'message');
    div.setAttribute('role', 'alert');
    div.textContent = message || 'This product has been added to the basket';
    wrapper.innerHTML = '';           // replace previous notices (optional)
    wrapper.appendChild(div);

    // scroll into view + auto-hide (optional)
    try {
      var y = wrapper.getBoundingClientRect().top + window.scrollY - 80;
      window.scrollTo({ top: y, behavior: 'smooth' });
    } catch (e) {}
    setTimeout(function(){ div.remove(); }, 5000);
  }


  window.addEventListener('message', function(event){
    try {
      if (!originAllowed(event.origin)) return;
      var data = event.data || {};
      if (data.action !== 'add_to_cart') return;

      var p = new URLSearchParams();
      p.append('action', 'simplio3d_add_to_cart');
      p.append('nonce', Simplio3DWoo.nonce);
      if (data.product_id) p.append('product_id', data.product_id);
      if (data.quantity)   p.append('quantity', data.quantity);
      if (data.description)p.append('description', data.description);
      if (data.thumbnail)  p.append('thumbnail', data.thumbnail);
      if (data.printMaps)  p.append('printmaps', JSON.stringify(data.printMaps));
      if (data.snapShots)  p.append('snapshots', JSON.stringify(data.snapShots));
      if (data.orderurl)  p.append('orderurl', data.orderurl);
      if (data.config_id)  p.append('config_id', data.config_id);
      if (data.price)      p.append('price', data.price);

      fetch(Simplio3DWoo.ajax_url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: p.toString()
      })
      .then(function(r){ return r.json(); })
      .then(function(json){
        if (json && json.success) {
          if (window.jQuery) {
            // update mini-cart and show notifications
            jQuery(document.body).trigger('wc_fragment_refresh');
            jQuery(document.body).trigger('added_to_cart', [[], {}, null]);
          }
          showWooNotice('This product has been added to the basket', 'success');
        } else {
          console.error('Simplio3D add_to_cart failed', json);
          showWooNotice('Could not add the product to the basket', 'error');
        }
      })
      .catch(function(err){ console.error(err); });
    } catch(e) {
      console.error(e);
    }
  }, false);
})();

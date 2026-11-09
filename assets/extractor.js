/*
 * Extrator (bookmarklet). Roda na página do produto, no navegador do usuário.
 * Vira uma linha só em class-qpi-admin.php: use apenas comentários de bloco,
 * termine as instruções com ponto e vírgula e não escreva barra-asterisco em
 * strings ou regex.
 *
 * Saída: { v, source, method, url, title, description, images[], variations[{name, options[{name, image}]}], source_price,
 *          rating_summary{average, count}, reviews[{author, rating, text, date, variation}], reviews_method }
 * Enviada para a tela de importação no #hash (não passa pelo servidor) e copiada
 * para a área de transferência como reserva.
 */
(function () {
  var TARGET = "__QPI_TARGET__";

  function txt(el) { return el ? String(el.innerText || el.textContent || '').trim() : ''; }
  function uniq(list) { var seen = {}, out = []; list.forEach(function (x) { if (x && !seen[x]) { seen[x] = 1; out.push(x); } }); return out; }
  function abs(u) { try { return new URL(u, location.href).href; } catch (e) { return ''; } }
  function strip(html) { var d = document.createElement('div'); d.innerHTML = String(html || ''); return txt(d) || d.textContent || ''; }

  /* ================= Shopee ================= */

  function shopeeCdn() {
    var imgs = [].slice.call(document.images);
    for (var i = 0; i < imgs.length; i++) {
      var m = String(imgs[i].currentSrc || imgs[i].src).match(/^(https:\/\/[^\/]+susercontent\.com)\/file\//);
      if (m) { return m[1]; }
    }
    return 'https://down-br.img.susercontent.com';
  }

  /* hash ou URL (miniatura _tn, @resize..., .webp) para a URL da imagem em tamanho original */
  function shopeeImg(h, cdn) {
    if (!h) { return ''; }
    var s = String(h);
    var m = s.match(/\/file\/([^?#]+)/);
    var hash = m ? m[1] : s;
    hash = hash.split('@')[0].replace(/_tn$/, '').replace(/\.(webp|jpe?g|png)$/i, '');
    if (!/^[a-z0-9_-]+$/i.test(hash)) { return /^https?:/.test(s) ? s : ''; }
    return cdn + '/file/' + hash;
  }

  /* Procura, nas props dos componentes React da página, o objeto do item (o mesmo da API da Shopee). */
  function shopeeReactItem() {
    var seen = typeof WeakSet === 'function' ? new WeakSet() : null;
    function isItem(o) {
      return o && typeof o === 'object' && Array.isArray(o.tier_variations) && (o.title || o.name) && (o.images || o.item_id || o.itemid);
    }
    function find(o, depth) {
      if (!o || typeof o !== 'object' || depth > 4) { return null; }
      if (seen) { if (seen.has(o)) { return null; } seen.add(o); }
      if (isItem(o)) { return o; }
      if (o.nodeType || o === window) { return null; }
      var keys; try { keys = Object.keys(o); } catch (e) { return null; }
      for (var i = 0; i < keys.length && i < 80; i++) {
        var k = keys[i];
        if (k === 'children' || k === '_owner' || k === 'return' || k === 'sibling' || k === 'child' || k === 'stateNode' || k.indexOf('__') === 0) { continue; }
        var v; try { v = o[k]; } catch (e2) { continue; }
        if (v && typeof v === 'object') { var r = find(v, depth + 1); if (r) { return r; } }
      }
      return null;
    }
    var els = [document.querySelector('h1')].concat([].slice.call(document.querySelectorAll('button[aria-label], h1 ~ *, main *')).slice(0, 600));
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (!el) { continue; }
      var key = Object.keys(el).filter(function (k) { return k.indexOf('__reactFiber$') === 0 || k.indexOf('__reactInternalInstance$') === 0; })[0];
      if (!key) { continue; }
      var f = el[key], n = 0;
      while (f && n++ < 60) {
        var r = find(f.memoizedProps, 0) || find(f.memoizedState, 0);
        if (r) { return r; }
        f = f.return;
      }
    }
    return null;
  }

  function shopeeFromItem(it) {
    var cdn = shopeeCdn();
    var variations = (it.tier_variations || []).filter(function (t) { return t && t.options && t.options.length; }).map(function (t) {
      return {
        name: t.name || 'Variação',
        options: t.options.map(function (o, i) {
          return { name: String(o).trim(), image: t.images && t.images[i] ? shopeeImg(t.images[i], cdn) : '' };
        })
      };
    });
    var raw = it.price_min || it.price || 0;
    var ir = it.item_rating || {};
    var total = Array.isArray(ir.rating_count) ? ir.rating_count[0] : (it.cmt_count || 0);
    return {
      ids: { shop: it.shopid || it.shop_id, item: it.itemid || it.item_id },
      rating_summary: ir.rating_star ? { average: Math.round(ir.rating_star * 10) / 10, count: total || 0 } : null,
      method: 'react',
      title: String(it.title || it.name || '').trim(),
      description: String(it.description || '').trim(),
      images: uniq((it.images || []).map(function (h) { return shopeeImg(h, cdn); })),
      variations: variations,
      source_price: raw ? Math.round(raw / 1000) / 100 : null
    };
  }

  /* Reserva: lê o que está visível na página. */
  function shopeeFromDom() {
    var cdn = shopeeCdn();
    var h1 = document.querySelector('h1');
    var title = txt(h1) || (document.querySelector('meta[property="og:title"]') || {}).content || document.title;

    var description = '';
    var heads = [].slice.call(document.querySelectorAll('h2, h3, div, span')).filter(function (e) {
      return e.children.length === 0 && /^descri[çc][ãa]o do produto$/i.test(txt(e));
    });
    if (heads[0]) {
      var box = heads[0].parentElement;
      while (box && txt(box) === txt(heads[0])) { box = box.parentElement; }
      description = txt(box).replace(txt(heads[0]), '').trim();
    }

    var variations = [];
    var groups = new Map();
    [].slice.call(document.querySelectorAll('button[aria-label]')).forEach(function (b) {
      var parent = b.parentElement;
      if (!parent || !b.closest('section')) { return; }
      if (!groups.has(parent)) { groups.set(parent, []); }
      groups.get(parent).push(b);
    });
    groups.forEach(function (buttons, parent) {
      if (buttons.length < 2) { return; }
      var withImg = buttons.filter(function (b) { return b.querySelector('img'); }).length;
      var label = txt(parent.previousElementSibling) || txt(parent.parentElement && parent.parentElement.previousElementSibling) || 'Variação';
      if (label.length > 30) { label = 'Variação'; }
      variations.push({
        name: label,
        withImg: withImg,
        options: buttons.map(function (b) {
          var img = b.querySelector('img');
          return { name: (b.getAttribute('aria-label') || txt(b)).trim(), image: img ? shopeeImg(img.currentSrc || img.src, cdn) : '' };
        })
      });
    });
    variations.sort(function (a, b) { return b.withImg - a.withImg; });
    variations.forEach(function (v) { delete v.withImg; });

    var optionImgs = {};
    variations.forEach(function (v) { v.options.forEach(function (o) { optionImgs[o.image] = 1; }); });
    var scope = h1;
    for (var up = 0; scope && up < 6; up++) {
      scope = scope.parentElement;
      if (scope && scope.querySelectorAll('img[src*="susercontent.com/file/"]').length >= 3) { break; }
    }
    var images = scope ? [].slice.call(scope.querySelectorAll('img[src*="susercontent.com/file/"]'))
      .filter(function (img) { return !img.closest('button[aria-label]'); })
      .map(function (img) { return shopeeImg(img.currentSrc || img.src, cdn); })
      .filter(function (u) { return u && !optionImgs[u]; }) : [];

    return { method: 'dom', title: title, description: description, images: uniq(images).slice(0, 15), variations: variations, source_price: null };
  }

  /* IDs do anúncio pela URL: ...-i.<loja>.<item> ou /product/<loja>/<item> */
  function shopeeIds() {
    var m = location.pathname.match(/-i\.(\d+)\.(\d+)/) || location.pathname.match(/\/product\/(\d+)\/(\d+)/);
    return m ? { shop: m[1], item: m[2] } : null;
  }

  /* Avaliações 5 estrelas com texto: API da própria página (mesma origem, sessão do usuário); reserva: o que está visível. */
  async function shopeeReviews(ids, max) {
    var out = [], summary = null, method = '';
    if (ids && ids.shop && ids.item) {
      try {
        for (var offset = 0; offset < 120 && out.length < max; offset += 50) {
          var q = 'filter=0&flag=1&limit=50&offset=' + offset + '&type=5&itemid=' + ids.item + '&shopid=' + ids.shop;
          var res = await fetch('/api/v2/item/get_ratings?' + q, { credentials: 'include', headers: { 'x-api-source': 'pc' } });
          var j = await res.json();
          var d = j && j.data;
          if (!d || !Array.isArray(d.ratings)) { break; }
          if (!summary && d.item_rating_summary && Array.isArray(d.item_rating_summary.rating_count)) {
            var rc = d.item_rating_summary.rating_count, n = 0, sum = 0;
            for (var k = 0; k < 5; k++) { n += rc[k] || 0; sum += (rc[k] || 0) * (k + 1); }
            if (n) { summary = { average: Math.round(sum / n * 10) / 10, count: d.item_rating_summary.rating_total || n }; }
          }
          d.ratings.forEach(function (r) {
            var text = String(r.comment || '').trim();
            if (out.length >= max || r.rating_star !== 5 || !text) { return; }
            out.push({
              author: r.anonymous ? 'Comprador' : String(r.author_username || 'Comprador'),
              rating: 5,
              text: text,
              date: r.ctime ? new Date(r.ctime * 1000).toISOString() : '',
              variation: (r.product_items && r.product_items[0] && r.product_items[0].model_name) || ''
            });
          });
          if (d.ratings.length < 50) { break; }
        }
        if (out.length) { method = 'api'; }
      } catch (e) { out = []; }
    }
    if (!out.length) {
      [].slice.call(document.querySelectorAll('.shopee-product-rating')).forEach(function (el) {
        var stars = el.querySelectorAll('.icon-rating-solid--active, .shopee-product-rating__rating .icon-rating-solid').length;
        var text = txt(el.querySelector('.shopee-product-rating__content, [class*="rating__content"]'));
        if (out.length < max && stars === 5 && text) {
          out.push({ author: txt(el.querySelector('.shopee-product-rating__author-name')) || 'Comprador', rating: 5, text: text, date: '', variation: '' });
        }
      });
      if (out.length) { method = 'dom'; }
    }
    return { reviews: out, summary: summary, method: method };
  }

  async function shopee() {
    var it = null;
    try { it = shopeeReactItem(); } catch (e) { it = null; }
    var data = it ? shopeeFromItem(it) : shopeeFromDom();
    if (it && !data.description) { data.description = shopeeFromDom().description; }
    data.source = 'shopee';
    var ids = (data.ids && data.ids.item) ? data.ids : shopeeIds();
    delete data.ids;
    var rv = await shopeeReviews(ids, 20);
    data.reviews = rv.reviews;
    data.reviews_method = rv.method;
    data.rating_summary = data.rating_summary || rv.summary;
    return data;
  }

  /* ================= Genérico: JSON-LD (schema.org) + Open Graph ================= */

  function generic() {
    var nodes = [];
    [].slice.call(document.querySelectorAll('script[type="application/ld+json"]')).forEach(function (s) {
      try {
        var j = JSON.parse(s.textContent);
        (Array.isArray(j) ? j : [j]).forEach(function (n) { nodes.push(n); if (n && n['@graph']) { nodes = nodes.concat(n['@graph']); } });
      } catch (e) {}
    });
    function typeIs(n, t) { var x = n && n['@type']; return x === t || (Array.isArray(x) && x.indexOf(t) > -1); }
    function imgs(v) {
      if (!v) { return []; }
      return (Array.isArray(v) ? v : [v]).map(function (x) { return abs(typeof x === 'string' ? x : (x.url || x.contentUrl || '')); }).filter(Boolean);
    }
    var group = nodes.filter(function (n) { return typeIs(n, 'ProductGroup'); })[0];
    var prod = group || nodes.filter(function (n) { return typeIs(n, 'Product'); })[0];
    var meta = function (p) { var m = document.querySelector('meta[property="' + p + '"], meta[name="' + p + '"]'); return m ? m.content : ''; };

    var data = {
      source: location.hostname.replace(/^www\./, ''),
      method: prod ? 'json-ld' : 'open-graph',
      title: String((prod && prod.name) || meta('og:title') || txt(document.querySelector('h1')) || document.title).trim(),
      description: strip((prod && prod.description) || meta('og:description') || meta('description')).trim(),
      images: imgs(prod && prod.image),
      variations: [],
      source_price: null
    };

    var variants = (prod && (prod.hasVariant || prod.model)) || [];
    if (variants.length) {
      var options = variants.map(function (v) {
        var name = v.color || v.name || '';
        if (!v.color && data.title && name.indexOf(data.title) === 0) { name = name.slice(data.title.length).replace(/^[\s\-–|:,]+/, ''); }
        return { name: String(name).trim(), image: imgs(v.image)[0] || '' };
      }).filter(function (o) { return o.name; });
      var byName = {};
      options = options.filter(function (o) { if (byName[o.name]) { return false; } byName[o.name] = 1; return true; });
      if (options.length) { data.variations.push({ name: 'Cor', options: options }); }
    }
    var offer = prod && prod.offers && (Array.isArray(prod.offers) ? prod.offers[0] : prod.offers);
    if (offer && (offer.price || offer.lowPrice)) { data.source_price = parseFloat(offer.price || offer.lowPrice) || null; }

    if (!data.images.length) {
      var og = meta('og:image');
      data.images = og ? [abs(og)] : [];
      [].slice.call(document.images).forEach(function (img) {
        if (img.naturalWidth >= 500 && img.naturalHeight >= 500) { data.images.push(img.currentSrc || img.src); }
      });
    }
    data.images = uniq(data.images).slice(0, 15);

    var agg = prod && prod.aggregateRating;
    if (agg && agg.ratingValue) {
      data.rating_summary = { average: Math.round(parseFloat(agg.ratingValue) * 10) / 10, count: parseInt(agg.reviewCount || agg.ratingCount, 10) || 0 };
    }
    var revs = prod && prod.review ? (Array.isArray(prod.review) ? prod.review : [prod.review]) : [];
    data.reviews = revs.map(function (r) {
      var rr = r.reviewRating || {};
      var best = parseFloat(rr.bestRating) || 5;
      return {
        author: String((r.author && (r.author.name || r.author)) || 'Comprador'),
        rating: Math.round((parseFloat(rr.ratingValue) || 0) / best * 5),
        text: strip(r.reviewBody || r.description || '').trim(),
        date: r.datePublished || '',
        variation: ''
      };
    }).filter(function (r) { return r.rating === 5 && r.text; }).slice(0, 20);
    data.reviews_method = data.reviews.length ? 'json-ld' : '';
    return data;
  }

  /* ================= Envio ================= */

  run();

  async function run() {
  /* A aba é aberta já no clique (antes de buscar as avaliações), senão o bloqueador de pop-up a impede. */
  var win = window.open('about:blank', '_blank');
  try { if (win) { win.document.title = 'Importando…'; win.document.body.innerHTML = '<p style="font:16px sans-serif;padding:24px">Lendo o produto…</p>'; } } catch (e) {}
  var data;
  try {
    data = /(^|\.)shopee\./.test(location.hostname) ? await shopee() : generic();
  } catch (err) {
    alert('Importador: não foi possível ler esta página (' + err.message + ').');
    if (win) { win.close(); }
    return;
  }
  data.v = 1;
  data.url = location.href;

  if (!data.title && !data.images.length) {
    alert('Importador: nenhum produto encontrado nesta página. Espere a página carregar por completo e tente de novo.');
    if (win) { win.close(); }
    return;
  }

  var json = JSON.stringify(data);
  try { if (navigator.clipboard) { navigator.clipboard.writeText(json).catch(function () {}); } } catch (e) {}

  var target = TARGET + '#qpi=' + encodeURIComponent(json);
  if (win) { try { win.location.href = target; } catch (e) { win = window.open(target, '_blank'); } }
  if (!win) {
    alert('Importador: o navegador bloqueou a nova aba. Os dados foram copiados; cole na tela "Importar de link".');
  }
  }
})();

/**
 * Tela de importação: lê os dados do #qpi= (vindos do bookmarklet) ou colados,
 * monta a prévia editável e envia o resultado revisado ao servidor.
 */
(function () {
  const $ = (s, el = document) => el.querySelector(s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const form = $('#qpi-form');
  const preview = $('#qpi-preview');
  const start = $('#qpi-start');
  if (!form || !preview) return;

  /* ---------- Entrada ---------- */
  function readHash() {
    const m = location.hash.match(/^#qpi=(.+)$/s);
    if (!m) return null;
    history.replaceState(null, '', location.pathname + location.search); // limpa o hash
    try { return JSON.parse(decodeURIComponent(m[1])); } catch (e) { alert('Não foi possível ler os dados recebidos.'); return null; }
  }

  $('#qpi-paste-load')?.addEventListener('click', () => {
    try { load(JSON.parse($('#qpi-paste').value.trim())); } catch (e) { alert('O texto colado não é válido.'); }
  });

  /* ---------- Prévia ---------- */
  let state = null;

  function load(data) {
    if (!data || typeof data !== 'object') return;
    state = {
      url: data.url || '',
      source: data.source || '',
      method: data.method || '',
      sourcePrice: data.source_price,
      title: data.title || '',
      description: data.description || '',
      images: (data.images || []).filter(Boolean),
      variations: (data.variations || []).filter((v) => v && v.options && v.options.length),
      tier: 0,
      reviews: (data.reviews || []).filter((r) => r && r.text),
      reviewsMethod: data.reviews_method || '',
      ratingSummary: data.rating_summary || null,
    };
    // Prefere a variação que tem fotos (geralmente a de cor).
    const withImg = state.variations.findIndex((v) => v.options.some((o) => o.image));
    if (withImg > 0) state.tier = withImg;
    render();
    checkDuplicate();
  }

  function render() {
    const s = state;
    const tier = s.variations[s.tier];
    const cats = (QPI.categories || []).map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
    const terms = (QPI.terms || []).map((t) => `<option value="${esc(t)}">`).join('');

    preview.innerHTML = `
      <div class="qpi-head">
        <h2>Prévia da importação</h2>
        <p class="description">
          Origem: <a href="${esc(s.url)}" target="_blank" rel="noopener noreferrer">${esc(s.source || s.url)}</a>
          · leitura: ${esc(s.method)}
          ${s.sourcePrice ? ` · preço na origem: <strong>R$ ${Number(s.sourcePrice).toFixed(2).replace('.', ',')}</strong>` : ''}
        </p>
        <div id="qpi-dup"></div>
      </div>

      <div class="qpi-grid">
        <div class="qpi-main">
          <p><label>Título<br><input type="text" class="large-text" data-k="title" value="${esc(s.title)}" required></label></p>
          <p><label>Descrição <span class="description">(texto da origem — vale reescrever com a voz da loja)</span><br>
            <textarea class="large-text" rows="10" data-k="description">${esc(s.description)}</textarea></label></p>
        </div>
        <div class="qpi-side">
          <p><label>Preço (R$)<br><input type="text" inputmode="decimal" data-k="price" placeholder="149,90" required></label></p>
          <p><label>Preço promocional (opcional)<br><input type="text" inputmode="decimal" data-k="sale_price"></label></p>
          <p><label>Categoria<br><select data-k="category"><option value="">— sem categoria —</option>${cats}</select></label></p>
          <p><label>Status<br><select data-k="status">
            <option value="draft"${QPI.status === 'draft' ? ' selected' : ''}>Rascunho</option>
            <option value="publish"${QPI.status === 'publish' ? ' selected' : ''}>Publicado</option>
          </select></label></p>
        </div>
      </div>

      <h3>Variações</h3>
      ${s.variations.length > 1 ? `<p><label>Usar como cor: <select id="qpi-tier">${s.variations.map((v, i) => `<option value="${i}"${i === s.tier ? ' selected' : ''}>${esc(v.name)} (${v.options.length})</option>`).join('')}</select></label>
        <span class="description">As demais variações da origem são ignoradas.</span></p>` : ''}
      ${tier ? `
        <p class="description">Desmarque o que não quiser e ajuste os nomes (a lista sugere as cores já cadastradas). Com uma só opção marcada, o produto é criado como simples.</p>
        <datalist id="qpi-terms">${terms}</datalist>
        <table class="widefat striped qpi-options">
          <thead><tr><th class="check-column"><input type="checkbox" id="qpi-all" checked></th><th>Foto</th><th>Nome da cor</th><th>Na origem</th></tr></thead>
          <tbody>${tier.options.map((o, i) => `
            <tr>
              <th class="check-column"><input type="checkbox" data-opt="${i}" checked></th>
              <td>${o.image ? `<img src="${esc(o.image)}" alt="" loading="lazy">` : '<span class="qpi-noimg">sem foto</span>'}</td>
              <td><input type="text" data-name="${i}" value="${esc(cleanName(o.name))}" list="qpi-terms"></td>
              <td class="description">${esc(o.name)}</td>
            </tr>`).join('')}
          </tbody>
        </table>
        <p><label><input type="checkbox" data-k="colors_in_gallery" checked> Incluir as fotos das cores na galeria</label></p>`
      : '<p class="description">Nenhuma variação encontrada: o produto será criado como simples.</p>'}

      <h3>Galeria</h3>
      ${s.images.length ? `
        <p class="description">A primeira foto marcada vira a imagem principal.</p>
        <ul class="qpi-gallery">${s.images.map((u, i) => `
          <li><label><input type="checkbox" data-img="${i}" checked><img src="${esc(u)}" alt="" loading="lazy"></label></li>`).join('')}
        </ul>` : '<p class="description">Nenhuma foto geral encontrada (serão usadas as fotos das cores).</p>'}

      <h3>Avaliações ${esc(sourceLabel(s.source))}</h3>
      ${s.ratingSummary ? `<p>Nota do anúncio na origem: <strong>${String(s.ratingSummary.average).replace('.', ',')}</strong> de 5 · ${Number(s.ratingSummary.count).toLocaleString('pt-BR')} avaliações
        <span class="description">(exibida como "avaliações na ${esc(sourceLabel(s.source))}", separada da nota da loja)</span></p>` : ''}
      ${s.reviews.length ? `
        <p class="description">Entram na aba "Avaliações" com o selo "Avaliação feita na ${esc(sourceLabel(s.source))}" e não contam na nota da loja. Leitura: ${esc(s.reviewsMethod)}.</p>
        <table class="widefat striped qpi-reviews">
          <thead><tr><th class="check-column"><input type="checkbox" id="qpi-rv-all" checked></th><th>Avaliação</th></tr></thead>
          <tbody>${s.reviews.map((r, i) => `
            <tr>
              <th class="check-column"><input type="checkbox" data-rv="${i}" checked></th>
              <td><strong>${esc(r.author)}</strong> · ${'★'.repeat(r.rating || 5)}${r.date ? ` · ${esc(new Date(r.date).toLocaleDateString('pt-BR'))}` : ''}${r.variation ? ` · <span class="description">${esc(r.variation)}</span>` : ''}
                <div class="qpi-rv-text">${esc(r.text)}</div></td>
            </tr>`).join('')}
          </tbody>
        </table>` : '<p class="description">Nenhuma avaliação 5 estrelas com texto foi encontrada nesta página.</p>'}

      <p class="qpi-submit">
        <button type="submit" class="button button-primary button-large">Criar produto</button>
        <button type="button" class="button button-link" id="qpi-cancel">Cancelar</button>
        <span class="qpi-wait" hidden><span class="spinner is-active"></span> Baixando as fotos e criando o produto… pode levar um minuto.</span>
      </p>`;

    form.hidden = false;
    start.hidden = true;

    $('#qpi-tier', preview)?.addEventListener('change', (e) => { s.tier = +e.target.value; render(); checkDuplicate(); });
    $('#qpi-all', preview)?.addEventListener('change', (e) => {
      preview.querySelectorAll('[data-opt]').forEach((c) => { c.checked = e.target.checked; });
    });
    $('#qpi-rv-all', preview)?.addEventListener('change', (e) => {
      preview.querySelectorAll('[data-rv]').forEach((c) => { c.checked = e.target.checked; });
    });
    $('#qpi-cancel', preview).addEventListener('click', () => { form.hidden = true; start.hidden = false; preview.innerHTML = ''; state = null; });
  }

  function sourceLabel(src) {
    const map = { shopee: 'Shopee', 'mercadolivre.com.br': 'Mercado Livre', 'amazon.com.br': 'Amazon' };
    return map[src] || src || 'origem';
  }

  /* "Preto-01", "COR: preto" → "Preto" (sugestão; o usuário pode editar) */
  function cleanName(name) {
    let n = String(name || '').replace(/^(cor|color|colour)\s*[:\-]\s*/i, '').replace(/(?:[\-_]\d+|\s+\d{2,})$/, '').trim();
    const term = (QPI.terms || []).find((t) => t.toLowerCase() === n.toLowerCase());
    if (term) return term;
    return n ? n.charAt(0).toUpperCase() + n.slice(1) : String(name || '');
  }

  async function checkDuplicate() {
    if (!state?.url) return;
    try {
      const body = new URLSearchParams({ action: 'qpi_check', _ajax_nonce: QPI.nonce, url: state.url });
      const res = await (await fetch(QPI.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' })).json();
      if (res?.success && res.data) {
        $('#qpi-dup', preview).innerHTML = `<div class="notice notice-warning inline"><p>Este link já foi importado: <a href="${esc(res.data.edit)}">${esc(res.data.title)}</a>. Criar de novo gera um produto duplicado.</p></div>`;
      }
    } catch (e) { /* sem aviso, segue */ }
  }

  /* ---------- Envio ---------- */
  form.addEventListener('submit', (e) => {
    if (!state) { e.preventDefault(); return; }
    const val = (k) => preview.querySelector(`[data-k="${k}"]`);
    /* "1.299,90" / "149,90" / "149.90" → "149.90" */
    const num = (v) => { v = String(v).trim().replace(/[^\d.,]/g, ''); return v.includes(',') ? v.replace(/\./g, '').replace(',', '.') : v; };
    const price = num(val('price').value);
    const sale = num(val('sale_price').value);
    if (!(parseFloat(price) > 0)) { e.preventDefault(); alert('Informe o preço.'); val('price').focus(); return; }

    const tier = state.variations[state.tier];
    const options = tier ? tier.options.map((o, i) => ({
      name: preview.querySelector(`[data-name="${i}"]`).value.trim(),
      image: o.image,
      on: preview.querySelector(`[data-opt="${i}"]`).checked,
    })).filter((o) => o.on && o.name).map(({ name, image }) => ({ name, image })) : [];

    const gallery = state.images.filter((_, i) => preview.querySelector(`[data-img="${i}"]`)?.checked);

    $('#qpi-payload').value = JSON.stringify({
      url: state.url,
      title: val('title').value.trim(),
      description: val('description').value,
      price,
      sale_price: sale,
      category: val('category').value,
      status: val('status').value,
      attribute_label: tier ? tier.name : 'Cor',
      options,
      gallery,
      colors_in_gallery: !!val('colors_in_gallery')?.checked,
      review_source: sourceLabel(state.source),
      rating_summary: state.ratingSummary,
      reviews: state.reviews.filter((_, i) => preview.querySelector(`[data-rv="${i}"]`)?.checked),
    });

    preview.querySelector('[type="submit"]').disabled = true;
    preview.querySelector('.qpi-wait').hidden = false;
  });

  load(readHash());
})();

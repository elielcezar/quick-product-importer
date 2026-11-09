# Importador Rápido de Produtos

Plugin WordPress/WooCommerce que importa produtos de outras lojas a partir do próprio navegador: título, descrição, fotos, variações de cor e avaliações.

## Como funciona

1. Em **Produtos > Importar de link**, arraste o botão **Importar para a loja** para a barra de favoritos.
2. Abra o anúncio (Shopee ou outra loja) e clique no favorito. O botão lê a página no seu navegador e abre a tela de importação com os dados.
3. Na prévia, revise o título, a descrição, o preço, as cores (nome e foto), a galeria e as avaliações.
4. **Criar produto**: o plugin baixa as fotos, cria o produto (variável por cor, ou simples) como rascunho e liga cada foto à variação da cor dela.

Toda vez que o plugin for atualizado, arraste o botão de novo: o código do extrator vai embutido no favorito.

## Fontes suportadas

- **Shopee**: lê os dados do anúncio pela página (props do React) e, se não conseguir, pelo que está visível na tela. As avaliações vêm da API da própria página, usando a sessão do usuário.
- **Outras lojas**: usa os dados estruturados (JSON-LD `Product`/`ProductGroup`) e, na falta deles, Open Graph e as imagens grandes da página.

## Avaliações importadas

- Entram na aba "Avaliações" com o selo **"Avaliação feita na &lt;origem&gt;"**.
- **Não contam** na nota da loja: média, total, contagem por estrela e dados estruturados (Google) consideram só as avaliações feitas na loja.
- A nota real do anúncio de origem fica salva no produto. O tema pode exibi-la, identificada, com `qpi_get_source_rating( $product_id )`, que retorna `{ average, count, source }`.

## Configurações

No fim da tela de importação:

- **Atributo das variações**: um atributo global (ex.: `pa_cor`) ou um atributo próprio de cada produto.
- **Campo para o link de origem**: campo ACF ou meta. O link limpo é acrescentado ao conteúdo que já existe.
- **Status padrão**: rascunho ou publicado.

## Estrutura

```
quick-product-importer.php      bootstrap
includes/class-qpi-admin.php    tela, bookmarklet, ações e avisos
includes/class-qpi-importer.php criação do produto, imagens, atributo, origem
includes/class-qpi-reviews.php  avaliações importadas e nota da loja sem elas
assets/extractor.js             extrator (vira o bookmarklet)
assets/admin.js / admin.css     prévia editável
```

Requisitos: WordPress 6.4+, WooCommerce, PHP 8.0+.

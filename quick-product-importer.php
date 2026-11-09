<?php
/**
 * Plugin Name:       Importador Rápido de Produtos
 * Description:       Importa produtos de outras lojas (Shopee e lojas com dados estruturados) para o WooCommerce a partir do próprio navegador: título, descrição, fotos e variações de cor. Um botão nos favoritos lê a página que você está vendo e abre uma prévia editável no admin.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * Author:            Eliel Cezar
 * Text Domain:       quick-product-importer
 *
 * Fluxo:
 *  1. O "bookmarklet" (assets/extractor.js) roda na página do produto, no navegador
 *     do usuário, extrai os dados e abre a tela de importação com eles no #hash.
 *  2. A tela (assets/admin.js) mostra uma prévia editável: título, descrição,
 *     preço, cores (nome + foto) e galeria.
 *  3. "Criar produto" envia para admin-post.php → QPI_Importer cria o produto
 *     (variável por cor, ou simples), baixa as imagens e liga cada foto à sua cor.
 *  4. Avaliações da origem entram identificadas e fora da nota da loja (QPI_Reviews).
 */

defined( 'ABSPATH' ) || exit;

define( 'QPI_VERSION', '0.2.0' );
define( 'QPI_FILE', __FILE__ );
define( 'QPI_DIR', plugin_dir_path( __FILE__ ) );
define( 'QPI_URL', plugin_dir_url( __FILE__ ) );

require_once QPI_DIR . 'includes/class-qpi-importer.php';
require_once QPI_DIR . 'includes/class-qpi-admin.php';
require_once QPI_DIR . 'includes/class-qpi-reviews.php';

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	QPI_Admin::init();
	QPI_Reviews::init();
} );

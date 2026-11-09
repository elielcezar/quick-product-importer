<?php
/**
 * Cria o produto no WooCommerce a partir dos dados revisados na prévia.
 */

defined( 'ABSPATH' ) || exit;

class QPI_Importer {

	/** @var string[] avisos não fatais (imagem que falhou etc.) */
	public array $warnings = [];

	private array $settings;
	private string $title = '';

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/* ---------- Configurações ---------- */

	public static function defaults(): array {
		$tax = taxonomy_exists( 'pa_cor' ) ? 'pa_cor' : '';
		return [
			'attribute'   => $tax,         // taxonomia global (pa_*) ou '' = atributo próprio do produto
			'notes_field' => 'anotacoes',  // campo ACF (ou meta) onde salvar o link de origem
			'status'      => 'draft',
		];
	}

	public static function settings(): array {
		return wp_parse_args( (array) get_option( 'qpi_settings', [] ), self::defaults() );
	}

	/** URL de origem sem parâmetros de rastreio (usada para detectar duplicados). */
	public static function clean_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return esc_url_raw( $url );
		}
		return esc_url_raw( ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( $parts['path'] ?? '' ) );
	}

	/** Produto já importado desta origem, se houver. */
	public static function find_existing( string $url ): int {
		$ids = get_posts( [
			'post_type'   => 'product',
			'post_status' => 'any',
			'fields'      => 'ids',
			'numberposts' => 1,
			'meta_key'    => '_qpi_source',
			'meta_value'  => self::clean_url( $url ),
		] );
		return (int) ( $ids[0] ?? 0 );
	}

	/* ---------- Importação ---------- */

	/**
	 * @param array $data {
	 *   title, description, price, sale_price, category, status, url,
	 *   attribute_label, options: [{name, image}], gallery: [url], colors_in_gallery: bool,
	 *   review_source, rating_summary: {average, count}, reviews: [{author, rating, text, date, variation}]
	 * }
	 * @return int|WP_Error ID do produto
	 */
	public function import( array $data ) {
		$this->title = sanitize_text_field( $data['title'] ?? '' );
		$price       = wc_format_decimal( $data['price'] ?? '' );
		$sale        = wc_format_decimal( $data['sale_price'] ?? '' );

		if ( $this->title === '' ) {
			return new WP_Error( 'qpi_title', 'Informe o título.' );
		}
		if ( $price === '' || (float) $price <= 0 ) {
			return new WP_Error( 'qpi_price', 'Informe o preço.' );
		}
		if ( $sale !== '' && (float) $sale >= (float) $price ) {
			$sale = '';
			$this->warnings[] = 'O preço promocional era maior ou igual ao preço normal e foi ignorado.';
		}

		$options = [];
		foreach ( (array) ( $data['options'] ?? [] ) as $opt ) {
			$name = sanitize_text_field( $opt['name'] ?? '' );
			if ( $name !== '' && ! isset( $options[ mb_strtolower( $name ) ] ) ) {
				$options[ mb_strtolower( $name ) ] = [ 'name' => $name, 'image' => esc_url_raw( $opt['image'] ?? '' ) ];
			}
		}
		$options  = array_values( $options );
		$variable = count( $options ) > 1;

		$product = $variable ? new WC_Product_Variable() : new WC_Product_Simple();
		$product->set_name( $this->title );
		$product->set_description( $this->format_description( (string) ( $data['description'] ?? '' ) ) );
		$product->set_status( in_array( $data['status'] ?? '', [ 'draft', 'publish', 'pending', 'private' ], true ) ? $data['status'] : 'draft' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->set_reviews_allowed( true );
		if ( ! empty( $data['category'] ) ) {
			$product->set_category_ids( [ (int) $data['category'] ] );
		}
		if ( ! $variable ) {
			$product->set_regular_price( $price );
			$product->set_sale_price( $sale );
		}

		// Atributo de cor (antes de salvar, para as variações terem a quem se referir).
		$attr_map = [];
		if ( $variable ) {
			[ $attribute, $attr_key, $attr_map ] = $this->build_attribute( $options, (string) ( $data['attribute_label'] ?? 'Cor' ) );
			$product->set_attributes( [ $attribute ] );
			$product->set_default_attributes( [ $attr_key => reset( $attr_map ) ] );
		}

		$product_id = $product->save();
		if ( ! $product_id ) {
			return new WP_Error( 'qpi_save', 'Não foi possível salvar o produto.' );
		}

		// Galeria: fotos gerais (+ fotos das cores, se marcado). A 1ª vira a imagem principal.
		$gallery_urls = array_map( 'esc_url_raw', (array) ( $data['gallery'] ?? [] ) );
		if ( ! empty( $data['colors_in_gallery'] ) ) {
			$gallery_urls = array_merge( $gallery_urls, array_column( $options, 'image' ) );
		}
		$gallery_ids = [];
		foreach ( array_unique( array_filter( $gallery_urls ) ) as $i => $url ) {
			$id = $this->sideload( $url, $product_id, count( $gallery_ids ) + 1 );
			if ( $id ) {
				$gallery_ids[] = $id;
			}
		}

		// Variações: uma por cor, com a foto da cor.
		if ( $variable ) {
			foreach ( $options as $n => $opt ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product_id );
				$variation->set_attributes( [ $attr_key => $attr_map[ $opt['name'] ] ] );
				$variation->set_regular_price( $price );
				$variation->set_sale_price( $sale );
				$variation->set_manage_stock( false );
				$variation->set_stock_status( 'instock' );
				$variation->set_menu_order( $n );
				if ( $opt['image'] ) {
					$img = $this->sideload( $opt['image'], $product_id, $opt['name'] );
					if ( $img ) {
						$variation->set_image_id( $img );
					}
				}
				$variation->save();
			}
		}

		$product = wc_get_product( $product_id );
		if ( ! $gallery_ids && $variable ) {
			// Sem fotos gerais: usa a foto da primeira cor como principal.
			$first = $product->get_children() ? wc_get_product( $product->get_children()[0] ) : null;
			if ( $first && $first->get_image_id() ) {
				$gallery_ids[] = (int) $first->get_image_id();
			}
		}
		if ( $gallery_ids ) {
			$product->set_image_id( array_shift( $gallery_ids ) );
			$product->set_gallery_image_ids( $gallery_ids );
		}
		$product->save();
		if ( $variable ) {
			WC_Product_Variable::sync( $product_id );
		}

		$this->save_source( $product_id, (string) ( $data['url'] ?? '' ) );

		// Avaliações da origem: identificadas e fora da nota da loja (class-qpi-reviews.php).
		$source = sanitize_text_field( $data['review_source'] ?? '' );
		QPI_Reviews::save_summary( $product_id, $data['rating_summary'] ?? null, $source );
		$imported = QPI_Reviews::import( $product_id, (array) ( $data['reviews'] ?? [] ), $source );
		if ( $imported ) {
			$this->warnings[] = sprintf( '%d avaliações importadas (identificadas como da %s, fora da nota da loja).', $imported, $source ?: 'origem' );
		}

		return $product_id;
	}

	/* ---------- Partes ---------- */

	/**
	 * Monta o atributo de variação. Usa a taxonomia global configurada (ex.: pa_cor),
	 * criando os termos que faltarem; sem taxonomia, cria um atributo próprio do produto.
	 *
	 * @return array [ WC_Product_Attribute, chave do atributo na variação, [ nome => valor ] ]
	 */
	private function build_attribute( array $options, string $label ): array {
		$tax       = $this->settings['attribute'];
		$attribute = new WC_Product_Attribute();
		$map       = [];

		if ( $tax && taxonomy_exists( $tax ) ) {
			$term_ids = [];
			foreach ( $options as $opt ) {
				$term = $this->find_term( $opt['name'], $tax );
				if ( ! $term ) {
					$created = wp_insert_term( $opt['name'], $tax );
					if ( is_wp_error( $created ) ) {
						$this->warnings[] = sprintf( 'Cor "%s" não pôde ser criada: %s', $opt['name'], $created->get_error_message() );
						continue;
					}
					$term = get_term( $created['term_id'], $tax );
				}
				$term_ids[]          = (int) $term->term_id;
				$map[ $opt['name'] ] = $term->slug;
			}
			$attribute->set_id( wc_attribute_taxonomy_id_by_name( $tax ) );
			$attribute->set_name( $tax );
			$attribute->set_options( $term_ids );
			$key = $tax;
		} else {
			$label = sanitize_text_field( $label ) ?: 'Cor';
			$attribute->set_id( 0 );
			$attribute->set_name( $label );
			$attribute->set_options( array_column( $options, 'name' ) );
			foreach ( $options as $opt ) {
				$map[ $opt['name'] ] = $opt['name'];
			}
			$key = sanitize_title( $label );
		}

		$attribute->set_visible( true );
		$attribute->set_variation( true );
		return [ $attribute, $key, $map ];
	}

	/** Termo pelo nome, sem diferenciar maiúsculas/acentos de forma simples. */
	private function find_term( string $name, string $tax ): ?WP_Term {
		$term = get_term_by( 'name', $name, $tax );
		if ( ! $term ) {
			$term = get_term_by( 'slug', sanitize_title( $name ), $tax );
		}
		return $term instanceof WP_Term ? $term : null;
	}

	/** Texto puro vira parágrafos; HTML é filtrado. */
	private function format_description( string $text ): string {
		$text = trim( $text );
		if ( $text === '' ) {
			return '';
		}
		if ( $text !== wp_strip_all_tags( $text ) ) {
			return wp_kses_post( $text );
		}
		return wpautop( esc_html( $text ) );
	}

	/**
	 * Baixa a imagem e cria o anexo. Reaproveita se a mesma URL já foi baixada.
	 *
	 * @param int|string $suffix usado no nome do arquivo
	 */
	private function sideload( string $url, int $product_id, $suffix ): int {
		if ( ! $url ) {
			return 0;
		}
		$existing = get_posts( [
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'fields'      => 'ids',
			'numberposts' => 1,
			'meta_key'    => '_qpi_source_image',
			'meta_value'  => $url,
		] );
		if ( $existing ) {
			return (int) $existing[0];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 45 );
		if ( is_wp_error( $tmp ) ) {
			$this->warnings[] = 'Imagem não baixada (' . $tmp->get_error_message() . '): ' . $url;
			return 0;
		}

		// URLs de CDN costumam vir sem extensão: define pelo tipo real do arquivo.
		$mime = wp_get_image_mime( $tmp );
		$ext  = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif' ][ $mime ] ?? '';
		if ( ! $ext ) {
			@unlink( $tmp ); // phpcs:ignore
			$this->warnings[] = 'Arquivo não é uma imagem válida: ' . $url;
			return 0;
		}

		$name = sanitize_title( $this->title . '-' . $suffix ) . '.' . $ext;
		$id   = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], $product_id, $this->title );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore
			$this->warnings[] = 'Imagem não salva (' . $id->get_error_message() . '): ' . $url;
			return 0;
		}
		update_post_meta( $id, '_qpi_source_image', $url );
		update_post_meta( $id, '_wp_attachment_image_alt', $this->title . ( is_string( $suffix ) ? ' — ' . $suffix : '' ) );
		return (int) $id;
	}

	/** Guarda o link de origem: meta interna (duplicados) + campo de anotações. */
	private function save_source( int $product_id, string $url ): void {
		if ( ! $url ) {
			return;
		}
		$clean = self::clean_url( $url );
		update_post_meta( $product_id, '_qpi_source', $clean );

		$field = sanitize_key( $this->settings['notes_field'] );
		if ( ! $field ) {
			return;
		}
		$line = 'Origem: ' . $clean;
		if ( function_exists( 'update_field' ) && function_exists( 'acf_get_field' ) && acf_get_field( $field ) ) {
			$current = (string) get_field( $field, $product_id, false );
			update_field( $field, trim( $current . "\n" . $line ), $product_id );
		} else {
			$current = (string) get_post_meta( $product_id, $field, true );
			update_post_meta( $product_id, $field, trim( $current . "\n" . $line ) );
		}
	}
}

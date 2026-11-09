<?php
/**
 * Avaliações importadas de outra loja (ex.: Shopee).
 *
 * Transparência: elas aparecem na aba "Avaliações" com o selo
 * "Avaliação feita na <origem>", mas NÃO entram na nota/contagem da loja
 * (média, total, estrelas dos cards, dados estruturados para o Google).
 * A nota real do anúncio de origem fica em _qpi_source_rating e pode ser
 * exibida pelo tema como "4,9 · 12 mil avaliações na Shopee" (qpi_get_source_rating()).
 */

defined( 'ABSPATH' ) || exit;

class QPI_Reviews {

	const META_SOURCE  = '_qpi_source';        // no comentário: nome da origem
	const META_VAR     = '_qpi_variation';     // no comentário: variação comprada na origem
	const META_HAS     = '_qpi_reviews';       // no produto: tem avaliações importadas
	const META_RATING  = '_qpi_source_rating'; // no produto: { average, count, source }

	private static array $cache = [];

	public static function init(): void {
		foreach ( [ 'average_rating', 'rating_counts', 'review_count' ] as $prop ) {
			add_filter( 'woocommerce_product_get_' . $prop, function ( $value, $product ) use ( $prop ) {
				return self::filter_prop( $prop, $value, $product );
			}, 10, 2 );
		}
		add_action( 'woocommerce_review_after_comment_text', [ __CLASS__, 'badge' ] );
		add_filter( 'woocommerce_product_tabs', [ __CLASS__, 'tab_title' ], 99 );
		add_filter( 'woocommerce_structured_data_product', [ __CLASS__, 'structured_data' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'style' ] );
		// Avaliação nova/alterada na mesma requisição: recalcula.
		foreach ( [ 'wp_insert_comment', 'wp_update_comment_count', 'added_comment_meta', 'updated_comment_meta', 'deleted_comment', 'wp_set_comment_status' ] as $hook ) {
			add_action( $hook, function () { self::$cache = []; } );
		}
	}

	/* ---------- Importação ---------- */

	/**
	 * @param array $reviews [{author, rating, text, date, variation}]
	 * @return int quantidade importada
	 */
	public static function import( int $product_id, array $reviews, string $source ): int {
		$source = $source ?: 'origem';
		$count  = 0;
		foreach ( array_slice( $reviews, 0, 50 ) as $r ) {
			$text = sanitize_textarea_field( $r['text'] ?? '' );
			if ( $text === '' ) {
				continue;
			}
			$rating = max( 1, min( 5, (int) ( $r['rating'] ?? 5 ) ) );
			$time   = ! empty( $r['date'] ) ? strtotime( (string) $r['date'] ) : false;
			$gmt    = $time ? gmdate( 'Y-m-d H:i:s', $time ) : current_time( 'mysql', true );

			$id = wp_insert_comment( [
				'comment_post_ID'      => $product_id,
				'comment_author'       => sanitize_text_field( $r['author'] ?? '' ) ?: 'Comprador',
				'comment_author_email' => '',
				'comment_author_url'   => '',
				'comment_content'      => $text,
				'comment_type'         => 'review',
				'comment_approved'     => 1,
				'comment_date_gmt'     => $gmt,
				'comment_date'         => get_date_from_gmt( $gmt ),
				'user_id'              => 0,
				'comment_meta'         => [
					'rating'          => $rating,
					'verified'        => 0,
					self::META_SOURCE => $source,
					self::META_VAR    => sanitize_text_field( $r['variation'] ?? '' ),
				],
			] );
			if ( $id ) {
				$count++;
			}
		}
		if ( $count ) {
			update_post_meta( $product_id, self::META_HAS, 1 );
		}
		return $count;
	}

	public static function save_summary( int $product_id, $summary, string $source ): void {
		if ( ! is_array( $summary ) || empty( $summary['average'] ) ) {
			return;
		}
		update_post_meta( $product_id, self::META_RATING, [
			'average' => round( min( 5, max( 0, (float) $summary['average'] ) ), 1 ),
			'count'   => max( 0, (int) ( $summary['count'] ?? 0 ) ),
			'source'  => $source ?: 'origem',
		] );
	}

	/* ---------- Nota da loja sem as importadas ---------- */

	private static function filter_prop( string $prop, $value, $product ) {
		if ( ! $product instanceof WC_Product || ! get_post_meta( $product->get_id(), self::META_HAS, true ) ) {
			return $value;
		}
		$stats = self::native_stats( $product->get_id() );
		return $stats[ $prop ];
	}

	/** Média, contagem por estrela e total só das avaliações feitas na loja. */
	public static function native_stats( int $product_id ): array {
		if ( isset( self::$cache[ $product_id ] ) ) {
			return self::$cache[ $product_id ];
		}
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT r.meta_value FROM {$wpdb->comments} c
			 LEFT JOIN {$wpdb->commentmeta} r ON r.comment_id = c.comment_ID AND r.meta_key = 'rating'
			 LEFT JOIN {$wpdb->commentmeta} s ON s.comment_id = c.comment_ID AND s.meta_key = %s
			 WHERE c.comment_post_ID = %d AND c.comment_approved = '1' AND c.comment_type = 'review'
			   AND c.comment_parent = 0 AND s.meta_id IS NULL",
			self::META_SOURCE,
			$product_id
		) );
		$counts = [];
		$sum    = 0;
		$rated  = 0;
		foreach ( $rows as $v ) {
			$v = (int) $v;
			if ( $v > 0 ) {
				$counts[ $v ] = ( $counts[ $v ] ?? 0 ) + 1;
				$sum         += $v;
				$rated++;
			}
		}
		ksort( $counts );
		return self::$cache[ $product_id ] = [
			'average_rating' => $rated ? wc_format_decimal( $sum / $rated, 2, false ) : 0,
			'rating_counts'  => $counts,
			'review_count'   => count( $rows ),
		];
	}

	/* ---------- Exibição ---------- */

	public static function badge( $comment ): void {
		$source = get_comment_meta( $comment->comment_ID, self::META_SOURCE, true );
		if ( ! $source ) {
			return;
		}
		$variation = get_comment_meta( $comment->comment_ID, self::META_VAR, true );
		printf(
			'<p class="qpi-review-source">Avaliação feita na %s%s</p>',
			esc_html( $source ),
			$variation ? ' · ' . esc_html( $variation ) : ''
		);
	}

	/** O título da aba conta tudo o que está listado nela (as importadas estão identificadas). */
	public static function tab_title( $tabs ) {
		global $product;
		if ( isset( $tabs['reviews'] ) && $product instanceof WC_Product && get_post_meta( $product->get_id(), self::META_HAS, true ) ) {
			$total = (int) get_comments( [ 'post_id' => $product->get_id(), 'status' => 'approve', 'type' => 'review', 'parent' => 0, 'count' => true ] );
			$tabs['reviews']['title'] = sprintf( 'Avaliações (%d)', $total );
		}
		return $tabs;
	}

	/** Google: nada de avaliações de terceiros nos dados estruturados do produto. */
	public static function structured_data( $markup, $product ) {
		if ( $product instanceof WC_Product && get_post_meta( $product->get_id(), self::META_HAS, true ) ) {
			unset( $markup['review'] );
		}
		return $markup;
	}

	public static function style(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		wp_register_style( 'qpi-front', false, [], QPI_VERSION );
		wp_enqueue_style( 'qpi-front' );
		wp_add_inline_style( 'qpi-front', '.qpi-review-source{margin:6px 0 0;font-size:12px;opacity:.65}' );
	}
}

/**
 * Nota do anúncio na loja de origem, para o tema exibir separada da nota da loja.
 *
 * @return array|null { average: float, count: int, source: string }
 */
function qpi_get_source_rating( int $product_id ): ?array {
	$r = get_post_meta( $product_id, QPI_Reviews::META_RATING, true );
	return is_array( $r ) && ! empty( $r['average'] ) ? $r : null;
}

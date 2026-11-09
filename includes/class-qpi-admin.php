<?php
/**
 * Tela "Produtos > Importar de link": bookmarklet, prévia editável e configurações.
 */

defined( 'ABSPATH' ) || exit;

class QPI_Admin {

	const SLUG = 'qpi-import';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'admin_post_qpi_create', [ __CLASS__, 'handle_create' ] );
		add_action( 'admin_post_qpi_settings', [ __CLASS__, 'handle_settings' ] );
		add_action( 'wp_ajax_qpi_check', [ __CLASS__, 'ajax_check' ] );
		add_action( 'admin_notices', [ __CLASS__, 'notices' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( QPI_FILE ), function ( $links ) {
			array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">Importar</a>' );
			return $links;
		} );
	}

	public static function page_url(): string {
		return admin_url( 'edit.php?post_type=product&page=' . self::SLUG );
	}

	public static function menu(): void {
		add_submenu_page( 'edit.php?post_type=product', 'Importar de link', 'Importar de link', 'edit_products', self::SLUG, [ __CLASS__, 'render' ] );
	}

	public static function assets( $hook ): void {
		if ( ( $_GET['page'] ?? '' ) !== self::SLUG ) { // phpcs:ignore
			return;
		}
		$s = QPI_Importer::settings();
		wp_enqueue_style( 'qpi-admin', QPI_URL . 'assets/admin.css', [], filemtime( QPI_DIR . 'assets/admin.css' ) );
		wp_enqueue_script( 'qpi-admin', QPI_URL . 'assets/admin.js', [], filemtime( QPI_DIR . 'assets/admin.js' ), true );

		$terms = $s['attribute'] && taxonomy_exists( $s['attribute'] )
			? get_terms( [ 'taxonomy' => $s['attribute'], 'hide_empty' => false, 'fields' => 'names' ] )
			: [];
		wp_localize_script( 'qpi-admin', 'QPI', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'qpi_check' ),
			'terms'      => is_array( $terms ) ? array_values( $terms ) : [],
			'status'     => $s['status'],
			'categories' => array_map(
				fn( $t ) => [ 'id' => $t->term_id, 'name' => $t->name ],
				get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] ) ?: []
			),
		] );
	}

	/** O código do bookmarklet, com a URL desta tela embutida. */
	private static function bookmarklet(): string {
		$src = (string) file_get_contents( QPI_DIR . 'assets/extractor.js' );
		$src = preg_replace( '#/\*.*?\*/#s', '', $src );   // o extrator só usa comentários /* */
		$src = preg_replace( '/\s*\n\s*/', ' ', $src );     // uma linha
		$src = str_replace( '"__QPI_TARGET__"', wp_json_encode( self::page_url(), JSON_UNESCAPED_SLASHES ), $src );
		return 'javascript:' . rawurlencode( trim( $src ) );
	}

	public static function render(): void {
		$s = QPI_Importer::settings();
		?>
		<div class="wrap qpi">
			<h1>Importar produto de link</h1>

			<div id="qpi-start" class="qpi-card">
				<h2>1. Instale o botão (uma vez)</h2>
				<p>Arraste o botão abaixo para a barra de favoritos do navegador:</p>
				<p><a class="button button-primary button-hero qpi-bookmarklet" href="<?php echo esc_attr( self::bookmarklet() ); ?>" onclick="alert('Arraste este botão para a barra de favoritos. Depois, clique nele na página do produto.'); return false;">⇪ Importar para a loja</a></p>
				<p class="description">Barra de favoritos escondida? <kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>B</kbd>. Se você atualizar o plugin, arraste o botão de novo.</p>

				<h2>2. Use na página do produto</h2>
				<p>Abra o anúncio (Shopee ou outra loja), espere carregar as fotos e clique em <strong>Importar para a loja</strong> nos favoritos. Esta tela abre com a prévia para revisar.</p>

				<details class="qpi-paste">
					<summary>Ou cole os dados manualmente</summary>
					<p class="description">O botão também copia os dados para a área de transferência. Se a janela não abrir (bloqueador de pop-up), cole aqui:</p>
					<textarea id="qpi-paste" rows="4" class="large-text code"></textarea>
					<p><button type="button" class="button" id="qpi-paste-load">Carregar prévia</button></p>
				</details>
			</div>

			<form id="qpi-form" class="qpi-card" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
				<?php wp_nonce_field( 'qpi_create' ); ?>
				<input type="hidden" name="action" value="qpi_create">
				<textarea name="payload" id="qpi-payload" hidden></textarea>
				<div id="qpi-preview"></div>
			</form>

			<details class="qpi-card qpi-settings">
				<summary><strong>Configurações</strong></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'qpi_settings' ); ?>
					<input type="hidden" name="action" value="qpi_settings">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="qpi-attr">Atributo das variações</label></th>
							<td>
								<select name="attribute" id="qpi-attr">
									<option value="">Atributo próprio de cada produto</option>
									<?php foreach ( wc_get_attribute_taxonomies() as $a ) : $tax = wc_attribute_taxonomy_name( $a->attribute_name ); ?>
										<option value="<?php echo esc_attr( $tax ); ?>" <?php selected( $s['attribute'], $tax ); ?>><?php echo esc_html( $a->attribute_label ); ?> (<?php echo esc_html( $tax ); ?>)</option>
									<?php endforeach; ?>
								</select>
								<p class="description">Com um atributo global (ex.: Cor), as cores novas entram na lista de termos e podem ser usadas em filtros.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="qpi-notes">Campo para o link de origem</label></th>
							<td>
								<input type="text" name="notes_field" id="qpi-notes" value="<?php echo esc_attr( $s['notes_field'] ); ?>" class="regular-text">
								<p class="description">Nome do campo ACF (ou meta) do produto. O link é acrescentado ao conteúdo existente.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="qpi-status">Status padrão</label></th>
							<td>
								<select name="status" id="qpi-status">
									<option value="draft" <?php selected( $s['status'], 'draft' ); ?>>Rascunho</option>
									<option value="publish" <?php selected( $s['status'], 'publish' ); ?>>Publicado</option>
								</select>
							</td>
						</tr>
					</table>
					<?php submit_button( 'Salvar configurações', 'secondary' ); ?>
				</form>
			</details>
		</div>
		<?php
	}

	/* ---------- Ações ---------- */

	public static function handle_create(): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'qpi_create' );
		@set_time_limit( 0 ); // phpcs:ignore — muitas imagens para baixar

		$data = json_decode( wp_unslash( $_POST['payload'] ?? '' ), true ); // phpcs:ignore
		if ( ! is_array( $data ) ) {
			self::flash( 'error', 'Dados da importação inválidos.' );
			wp_safe_redirect( self::page_url() );
			exit;
		}

		$importer = new QPI_Importer( QPI_Importer::settings() );
		$result   = $importer->import( $data );

		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
			wp_safe_redirect( self::page_url() );
			exit;
		}

		$msg = 'Produto importado. Revise e publique quando estiver pronto.';
		if ( $importer->warnings ) {
			$msg .= '<br><strong>Avisos:</strong><br>' . implode( '<br>', array_map( 'esc_html', $importer->warnings ) );
		}
		self::flash( $importer->warnings ? 'warning' : 'success', $msg );
		wp_safe_redirect( get_edit_post_link( $result, 'url' ) );
		exit;
	}

	public static function handle_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'qpi_settings' );
		$attr = sanitize_key( wp_unslash( $_POST['attribute'] ?? '' ) );
		update_option( 'qpi_settings', [
			'attribute'   => $attr && taxonomy_exists( $attr ) ? $attr : '',
			'notes_field' => sanitize_key( wp_unslash( $_POST['notes_field'] ?? '' ) ),
			'status'      => ( $_POST['status'] ?? '' ) === 'publish' ? 'publish' : 'draft',
		] );
		self::flash( 'success', 'Configurações salvas.' );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/** A prévia pergunta se essa origem já foi importada. */
	public static function ajax_check(): void {
		check_ajax_referer( 'qpi_check' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error();
		}
		$id = QPI_Importer::find_existing( (string) wp_unslash( $_POST['url'] ?? '' ) );
		wp_send_json_success( $id ? [ 'id' => $id, 'title' => get_the_title( $id ), 'edit' => get_edit_post_link( $id, 'url' ) ] : null );
	}

	/* ---------- Avisos ---------- */

	private static function flash( string $type, string $html ): void {
		set_transient( 'qpi_notice_' . get_current_user_id(), [ $type, $html ], 120 );
	}

	public static function notices(): void {
		$key    = 'qpi_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice[0] ), wp_kses_post( $notice[1] ) );
	}
}

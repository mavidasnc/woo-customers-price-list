<?php
/**
 * Updates Page
 *
 * Renderizza la tab "Aggiornamenti" nella pagina admin del plugin.
 * Mostra la versione installata, un pulsante per il check manuale e
 * un'area risultati popolata via AJAX.
 *
 * Il funzionamento automatico (cron WordPress ogni 12h) è gestito dalla
 * classe Updater indipendentemente da questa tab.
 *
 * @package WDPLP\Admin
 */

namespace WDPLP\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class UpdatesPage
 */
class UpdatesPage {

	/**
	 * Renderizza il contenuto della tab Aggiornamenti.
	 *
	 * @return void
	 */
	public function render_content(): void {
		?>
		<div class="wdplp-updates-wrap">

			<h2><?php esc_html_e( 'Updates', WDPLP_TEXT_DOMAIN ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'This plugin updates itself directly from its GitHub releases. Use the button below to check for a new version on demand; WordPress also checks automatically in the background.', WDPLP_TEXT_DOMAIN ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Installed version', WDPLP_TEXT_DOMAIN ); ?></th>
						<td><code><?php echo esc_html( WDPLP_VERSION ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Latest version', WDPLP_TEXT_DOMAIN ); ?></th>
						<td><code id="wdplp-latest-version">—</code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Repository', WDPLP_TEXT_DOMAIN ); ?></th>
						<td>
							<a href="https://github.com/mavidasnc/woo-dynamic-pricelist-pro" target="_blank" rel="noopener noreferrer">
								github.com/mavidasnc/woo-dynamic-pricelist-pro
							</a>
						</td>
					</tr>
				</tbody>
			</table>

			<p style="margin-top:10px;">
				<button type="button" class="button button-primary" id="wdplp-check-update">
					<?php esc_html_e( 'Check for updates', WDPLP_TEXT_DOMAIN ); ?>
				</button>
				<span id="wdplp-update-status" style="margin-left:10px;"></span>
			</p>

			<div id="wdplp-update-result"></div>

		</div>
		<?php
	}
}

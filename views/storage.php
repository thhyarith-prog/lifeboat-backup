<?php
/**
 * Cloud storage screen.
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

$enabled  = (array) Lifeboat_Settings::get( 'destinations' );
$post_url = admin_url( 'admin-post.php' );
$redirect = Lifeboat_OAuth_Destination::redirect_uri();
?>
<p class="lb-intro"><?php esc_html_e( 'Backups on the same server as your site are lost if the server is. Connect at least one cloud storage — Lifeboat uploads in resumable chunks, so large backups and slow connections are fine.', 'lifeboat-backup' ); ?></p>

<div class="lb-dests">
<?php foreach ( Lifeboat_Destination::registry() as $id => $d ) : ?>
	<?php
	$connected = $d->is_connected();
	$oauth     = $d instanceof Lifeboat_OAuth_Destination;
	?>
	<section class="lb-card lb-dest lb-dest--<?php echo esc_attr( $id ); ?> <?php echo $connected ? 'is-connected' : ''; ?>">
		<header class="lb-dest__head">
			<span class="lb-dest__logo" aria-hidden="true"><?php echo esc_html( substr( $d->label(), 0, 1 ) ); ?></span>
			<h2><?php echo esc_html( $d->label() ); ?></h2>
			<?php if ( $connected ) : ?>
				<span class="lb-badge lb-badge--ok"><?php esc_html_e( 'Connected', 'lifeboat-backup' ); ?></span>
			<?php endif; ?>
		</header>

		<?php if ( $connected ) : ?>
			<p class="lb-dest__status" aria-live="polite"><?php echo 'mega' === $id ? esc_html( $d->get_value( 'email' ) ) : ''; ?></p>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="lb-inline">
				<input type="hidden" name="action" value="lifeboat_save_dest"><input type="hidden" name="dest" value="<?php echo esc_attr( $id ); ?>"><input type="hidden" name="enabled_present" value="1">
				<?php wp_nonce_field( 'lifeboat_dest_' . $id ); ?>
				<label class="lb-check"><input type="checkbox" name="enabled" value="1" <?php checked( in_array( $id, $enabled, true ) ); ?>> <?php esc_html_e( 'Send automatic backups here', 'lifeboat-backup' ); ?></label>
				<button class="button button-small lifeboat-enabled-save"><?php esc_html_e( 'Save', 'lifeboat-backup' ); ?></button>
			</form>
			<div class="lb-inline">
				<button type="button" class="button" data-test="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Test connection', 'lifeboat-backup' ); ?></button>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline" data-pd-confirm="<?php echo esc_attr( sprintf( __( 'Disconnect %s? Backups already there stay, but new ones won\'t be sent until you connect again.', 'lifeboat-backup' ), $d->label() ) ); ?>">
					<input type="hidden" name="action" value="lifeboat_disconnect"><input type="hidden" name="dest" value="<?php echo esc_attr( $id ); ?>">
					<?php wp_nonce_field( 'lifeboat_disconnect_' . $id ); ?>
					<button class="button button-link-delete"><?php esc_html_e( 'Disconnect', 'lifeboat-backup' ); ?></button>
				</form>
			</div>
		<?php elseif ( $oauth ) : ?>
			<?php $relay = $d->relay_available(); ?>
			<?php if ( $relay ) : ?>
				<p class="lb-muted"><?php echo esc_html( sprintf( __( 'Sign in with your %s account and allow access. Lifeboat can only see the backup files it creates.', 'lifeboat-backup' ), 'gdrive' === $id ? 'Google' : ( 'onedrive' === $id ? 'Microsoft' : $d->label() ) ) ); ?></p>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>">
					<input type="hidden" name="action" value="lifeboat_connect"><input type="hidden" name="dest" value="<?php echo esc_attr( $id ); ?>">
					<?php wp_nonce_field( 'lifeboat_connect_' . $id ); ?>
					<button class="button button-primary lb-connect"><?php echo esc_html( sprintf( __( 'Connect %s', 'lifeboat-backup' ), $d->label() ) ); ?></button>
				</form>
			<?php endif; ?>
			<details class="lb-setup" <?php echo ! $relay && ! $d->has_app() ? 'open' : ''; ?>>
				<summary><?php echo $relay ? esc_html__( 'Advanced: use your own app keys instead', 'lifeboat-backup' ) : esc_html__( 'One-time setup (about 5 minutes)', 'lifeboat-backup' ); ?></summary>
				<ol>
					<?php foreach ( $d->setup_steps() as $step ) : ?>
						<li><?php echo esc_html( $step ); ?></li>
					<?php endforeach; ?>
				</ol>
				<p><a href="<?php echo esc_url( $d->console_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open the developer console', 'lifeboat-backup' ); ?> ↗</a></p>
				<label class="lb-label" for="lb-redirect-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Redirect URI', 'lifeboat-backup' ); ?></label>
				<div class="lb-copy"><input type="text" readonly id="lb-redirect-<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $redirect ); ?>" class="large-text code"><button type="button" class="button" data-copy="lb-redirect-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Copy', 'lifeboat-backup' ); ?></button></div>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="lb-dest__form">
					<input type="hidden" name="action" value="lifeboat_save_dest"><input type="hidden" name="dest" value="<?php echo esc_attr( $id ); ?>">
					<?php wp_nonce_field( 'lifeboat_dest_' . $id ); ?>
					<label><?php esc_html_e( 'Client ID', 'lifeboat-backup' ); ?><input type="text" name="client_id" value="<?php echo esc_attr( $d->get_value( 'client_id' ) ); ?>" class="large-text" autocomplete="off"></label>
					<label><?php esc_html_e( 'Client secret', 'lifeboat-backup' ); ?><input type="password" name="client_secret" value="" placeholder="<?php echo $d->has_app() ? esc_attr__( '(saved — leave empty to keep)', 'lifeboat-backup' ) : ''; ?>" class="large-text" autocomplete="new-password"></label>
					<button class="button"><?php esc_html_e( 'Save', 'lifeboat-backup' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>">
					<input type="hidden" name="action" value="lifeboat_connect"><input type="hidden" name="dest" value="<?php echo esc_attr( $id ); ?>"><input type="hidden" name="own_app" value="1">
					<?php wp_nonce_field( 'lifeboat_connect_' . $id ); ?>
					<button class="button <?php echo $relay ? '' : 'button-primary'; ?>" <?php disabled( ! $d->has_app() ); ?>><?php echo esc_html( $relay ? __( 'Connect with my own keys', 'lifeboat-backup' ) : sprintf( __( 'Connect %s', 'lifeboat-backup' ), $d->label() ) ); ?></button>
				</form>
			</details>
		<?php else : ?>
			<ul class="lb-notes">
				<?php foreach ( $d->setup_steps() as $step ) : ?>
					<li><?php echo esc_html( $step ); ?></li>
				<?php endforeach; ?>
			</ul>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="lb-dest__form" autocomplete="off">
				<input type="hidden" name="action" value="lifeboat_mega_connect">
				<?php wp_nonce_field( 'lifeboat_connect_mega' ); ?>
				<label><?php esc_html_e( 'Email', 'lifeboat-backup' ); ?><input type="email" name="email" class="large-text" autocomplete="off"></label>
				<label><?php esc_html_e( 'Password', 'lifeboat-backup' ); ?><input type="password" name="password" class="large-text" autocomplete="current-password"></label>
				<label><?php esc_html_e( 'Two-factor code (if enabled)', 'lifeboat-backup' ); ?><input type="text" name="mfa" inputmode="numeric" class="regular-text" autocomplete="one-time-code"></label>
				<button class="button button-primary"><?php esc_html_e( 'Connect MEGA', 'lifeboat-backup' ); ?></button>
			</form>
		<?php endif; ?>
	</section>
<?php endforeach; ?>
</div>

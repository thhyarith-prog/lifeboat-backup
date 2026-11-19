<?php
/**
 * Schedule & settings screen.
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

$s        = Lifeboat_Settings::all();
$weekdays = array( __( 'Sunday', 'lifeboat-backup' ), __( 'Monday', 'lifeboat-backup' ), __( 'Tuesday', 'lifeboat-backup' ), __( 'Wednesday', 'lifeboat-backup' ), __( 'Thursday', 'lifeboat-backup' ), __( 'Friday', 'lifeboat-backup' ), __( 'Saturday', 'lifeboat-backup' ) );
$select   = function ( $name, array $options, $value, $aria = '' ) {
	echo '<select name="lb[' . esc_attr( $name ) . ']" id="lb-' . esc_attr( $name ) . '"' . ( $aria ? ' aria-label="' . esc_attr( $aria ) . '"' : '' ) . '>';
	foreach ( $options as $k => $label ) {
		printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( (string) $value, (string) $k, false ), esc_html( $label ) );
	}
	echo '</select>';
};
$hours = array();
for ( $h = 0; $h < 24; $h++ ) {
	$hours[ $h ] = wp_date( get_option( 'time_format' ), gmmktime( $h, 0, 0, 1, 1, 2020 ), new DateTimeZone( 'UTC' ) );
}
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="lifeboat_save_settings">
	<?php wp_nonce_field( 'lifeboat_save_settings' ); ?>

	<div class="lb-grid">
		<section class="lb-card">
			<h2><?php esc_html_e( 'Automatic backups', 'lifeboat-backup' ); ?></h2>
			<div class="lb-field">
				<label for="lb-schedule"><?php esc_html_e( 'Full backup', 'lifeboat-backup' ); ?></label>
				<div class="lb-inline">
					<?php $select( 'schedule', array( 'off' => __( 'Off', 'lifeboat-backup' ), 'daily' => __( 'Every day', 'lifeboat-backup' ), 'weekly' => __( 'Every week', 'lifeboat-backup' ), 'monthly' => __( 'Every month', 'lifeboat-backup' ) ), $s['schedule'] ); ?>
					<span class="lb-when lb-when--weekly"><?php esc_html_e( 'on', 'lifeboat-backup' ); ?> <?php $select( 'schedule_weekday', $weekdays, $s['schedule_weekday'], __( 'Day of the week', 'lifeboat-backup' ) ); ?></span>
					<span class="lb-when lb-when--monthly"><?php esc_html_e( 'on day', 'lifeboat-backup' ); ?> <?php $select( 'schedule_monthday', array_combine( range( 1, 28 ), range( 1, 28 ) ), $s['schedule_monthday'], __( 'Day of the month', 'lifeboat-backup' ) ); ?></span>
					<span><?php esc_html_e( 'at', 'lifeboat-backup' ); ?> <?php $select( 'schedule_hour', $hours, $s['schedule_hour'], __( 'Time', 'lifeboat-backup' ) ); ?></span>
				</div>
				<p class="description"><?php echo esc_html( sprintf( __( 'Times are in the site timezone (%s). Pick a quiet hour.', 'lifeboat-backup' ), wp_timezone_string() ) ); ?></p>
			</div>
			<div class="lb-field">
				<label for="lb-db_schedule"><?php esc_html_e( 'Extra database-only backups', 'lifeboat-backup' ); ?></label>
				<?php $select( 'db_schedule', array( 'off' => __( 'Off', 'lifeboat-backup' ), 'twicedaily' => __( 'Twice a day', 'lifeboat-backup' ), 'daily' => __( 'Every day', 'lifeboat-backup' ), 'weekly' => __( 'Every week', 'lifeboat-backup' ) ), $s['db_schedule'] ); ?>
				<p class="description"><?php esc_html_e( 'Posts, orders and comments change often; the database is small and quick to back up.', 'lifeboat-backup' ); ?></p>
			</div>
			<div class="lb-field">
				<label class="lb-check"><input type="checkbox" name="lb[pre_update_snapshot]" value="1" <?php checked( $s['pre_update_snapshot'] ); ?>> <strong><?php esc_html_e( 'Save a copy before each plugin or theme update', 'lifeboat-backup' ); ?></strong></label>
				<p class="description"><?php esc_html_e( 'If an update breaks something, put the previous version back in one click (Backups → Update snapshots, or "Roll back" on the Plugins screen). The last 3 versions of each are kept.', 'lifeboat-backup' ); ?></p>
			</div>
			<div class="lb-field">
				<label class="lb-check"><input type="checkbox" name="lb[after_update]" value="1" <?php checked( $s['after_update'] ); ?>> <strong><?php esc_html_e( 'Back up automatically after updates', 'lifeboat-backup' ); ?></strong></label>
				<p class="description"><?php esc_html_e( 'Starts 5 minutes after WordPress, plugin or theme updates finish (manual or automatic), so a bulk update makes one backup.', 'lifeboat-backup' ); ?></p>
				<?php $select( 'after_update_scope', array( 'quick' => __( 'Database, plugins and themes (fast)', 'lifeboat-backup' ), 'full' => __( 'Everything selected below', 'lifeboat-backup' ) ), $s['after_update_scope'], __( 'What to back up after updates', 'lifeboat-backup' ) ); ?>
			</div>
			<div class="lb-field">
				<span class="lb-label"><?php esc_html_e( 'What a full backup includes', 'lifeboat-backup' ); ?></span>
				<div class="lb-checks">
					<?php foreach ( Lifeboat_Settings::COMPONENTS as $c ) : ?>
						<label class="lb-pick"><input type="checkbox" name="lb[components][]" value="<?php echo esc_attr( $c ); ?>" <?php checked( in_array( $c, (array) $s['components'], true ) ); ?>><span><?php echo esc_html( Lifeboat_Backup::label( $c ) ); ?></span></label>
					<?php endforeach; ?>
				</div>
				<p class="description"><?php esc_html_e( 'WordPress core can be downloaded again any time, so it is off by default.', 'lifeboat-backup' ); ?></p>
			</div>
		</section>

		<section class="lb-card">
			<h2><?php esc_html_e( 'Background processing', 'lifeboat-backup' ); ?></h2>
			<div class="lb-mode-explain">
				<div><strong><?php esc_html_e( 'Easy', 'lifeboat-backup' ); ?></strong><p><?php esc_html_e( 'Works in 4-second bursts with light compression, caps disk and upload speed, and pauses while your server is busy. Visitors never feel it. Best for scheduled backups.', 'lifeboat-backup' ); ?></p></div>
				<div><strong><?php esc_html_e( 'Rush', 'lifeboat-backup' ); ?></strong><p><?php esc_html_e( 'Uses long bursts and full speed. Finishes several times faster; may slow the site a little on small hosting. Best before making big changes.', 'lifeboat-backup' ); ?></p></div>
			</div>
			<div class="lb-field">
				<label for="lb-mode_scheduled"><?php esc_html_e( 'Scheduled and after-update backups', 'lifeboat-backup' ); ?></label>
				<?php $select( 'mode_scheduled', array( 'easy' => __( 'Easy', 'lifeboat-backup' ), 'rush' => __( 'Rush', 'lifeboat-backup' ) ), $s['mode_scheduled'] ); ?>
			</div>
			<div class="lb-field">
				<label for="lb-mode_manual"><?php esc_html_e( 'Default for "Back up now"', 'lifeboat-backup' ); ?></label>
				<?php $select( 'mode_manual', array( 'rush' => __( 'Rush', 'lifeboat-backup' ), 'easy' => __( 'Easy', 'lifeboat-backup' ) ), $s['mode_manual'] ); ?>
			</div>

			<h2 class="lb-sub"><?php esc_html_e( 'Keep', 'lifeboat-backup' ); ?></h2>
			<div class="lb-field lb-inline">
				<label><?php esc_html_e( 'On this server', 'lifeboat-backup' ); ?> <input type="number" class="small-text" min="0" max="100" name="lb[keep_local]" value="<?php echo (int) $s['keep_local']; ?>"></label>
				<label><?php esc_html_e( 'In each cloud', 'lifeboat-backup' ); ?> <input type="number" class="small-text" min="1" max="1000" name="lb[keep_remote]" value="<?php echo (int) $s['keep_remote']; ?>"></label>
			</div>
			<p class="description"><?php esc_html_e( 'Counted separately for full and database-only backups. 0 on this server = delete local files once the cloud upload succeeds.', 'lifeboat-backup' ); ?></p>

			<h2 class="lb-sub"><?php esc_html_e( 'Notifications', 'lifeboat-backup' ); ?></h2>
			<div class="lb-field lb-inline">
				<?php $select( 'notify', array( 'failures' => __( 'Email me when something fails', 'lifeboat-backup' ), 'always' => __( 'Email me after every backup', 'lifeboat-backup' ), 'never' => __( 'Never email', 'lifeboat-backup' ) ), $s['notify'], __( 'When to email', 'lifeboat-backup' ) ); ?>
				<input type="email" name="lb[notify_email]" aria-label="<?php esc_attr_e( 'Email address for notifications', 'lifeboat-backup' ); ?>" value="<?php echo esc_attr( $s['notify_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="regular-text">
			</div>
		</section>
	</div>

	<details class="lb-card lb-advanced">
		<summary><h2><?php esc_html_e( 'Advanced', 'lifeboat-backup' ); ?></h2></summary>
		<div class="lb-field lb-inline">
			<label><?php esc_html_e( 'Split archives larger than', 'lifeboat-backup' ); ?> <input type="number" class="small-text" min="25" max="4000" name="lb[split_mb]" value="<?php echo (int) $s['split_mb']; ?>"> MB</label>
			<label><?php esc_html_e( 'Skip single files larger than', 'lifeboat-backup' ); ?> <input type="number" class="small-text" min="0" name="lb[max_file_mb]" value="<?php echo (int) $s['max_file_mb']; ?>"> MB <small>(<?php esc_html_e( '0 = no limit', 'lifeboat-backup' ); ?>)</small></label>
		</div>
		<div class="lb-field">
			<label for="lb-exclude"><?php esc_html_e( 'Exclude', 'lifeboat-backup' ); ?></label>
			<textarea id="lb-exclude" name="lb[exclude]" rows="6" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $s['exclude'] ) ); ?></textarea>
			<p class="description"><?php esc_html_e( 'One per line. A folder path from the site root (wp-content/cache), a folder name anywhere (node_modules), or a wildcard (*.log).', 'lifeboat-backup' ); ?></p>
		</div>
		<div class="lb-field">
			<label for="lb-relay_url"><?php esc_html_e( 'Sign-in relay address', 'lifeboat-backup' ); ?></label>
			<input type="url" id="lb-relay_url" name="lb[relay_url]" value="<?php echo esc_attr( $s['relay_url'] ); ?>" class="regular-text code" placeholder="<?php echo esc_attr( LIFEBOAT_RELAY_URL ? LIFEBOAT_RELAY_URL : 'https://auth.example.com' ); ?>">
			<p class="description"><?php esc_html_e( 'Lets Google Drive, OneDrive, Dropbox and Box connect with one click. Leave empty to use the built-in default.', 'lifeboat-backup' ); ?></p>
		</div>
		<label class="lb-check"><input type="checkbox" name="lb[delete_on_uninstall]" value="1" <?php checked( $s['delete_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete settings and local backups when the plugin is uninstalled', 'lifeboat-backup' ); ?></label>
	</details>

	<p class="lb-save"><button class="button button-primary button-large"><?php esc_html_e( 'Save changes', 'lifeboat-backup' ); ?></button></p>
</form>

<?php
$lb_vault_on   = Lifeboat_Vault::is_on();
$lb_vault_lock = Lifeboat_Vault::needs_password();
$lb_vault_cfg  = Lifeboat_Vault::config();
?>
<section class="lb-card lb-encryption" id="lb-encryption">
	<h2>
		<span class="dashicons dashicons-lock" aria-hidden="true"></span>
		<?php esc_html_e( 'Encryption', 'lifeboat-backup' ); ?>
		<?php if ( $lb_vault_on && ! $lb_vault_lock ) : ?>
			<span class="lb-badge lb-badge--ok"><?php esc_html_e( 'On', 'lifeboat-backup' ); ?></span>
		<?php elseif ( $lb_vault_lock ) : ?>
			<span class="lb-badge lb-badge--bad"><?php esc_html_e( 'Password needed', 'lifeboat-backup' ); ?></span>
		<?php else : ?>
			<span class="lb-badge"><?php esc_html_e( 'Off', 'lifeboat-backup' ); ?></span>
		<?php endif; ?>
	</h2>

	<?php if ( ! Lifeboat_Vault::available() ) : ?>
		<p class="lb-muted"><?php esc_html_e( 'This server lacks OpenSSL AES-256-GCM support, so backups cannot be encrypted here. Ask your host to enable the PHP OpenSSL extension.', 'lifeboat-backup' ); ?></p>
	<?php elseif ( $lb_vault_lock ) : ?>
		<p><?php esc_html_e( 'Encryption is on, but this site can no longer unlock its key (this happens after moving the site or changing the security keys in wp-config.php). Backups are paused until you enter the encryption password.', 'lifeboat-backup' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lb-inline" autocomplete="off">
			<input type="hidden" name="action" value="lifeboat_encryption"><input type="hidden" name="do" value="unlock">
			<?php wp_nonce_field( 'lifeboat_encryption' ); ?>
			<input type="password" name="password" class="regular-text" autocomplete="current-password" aria-label="<?php esc_attr_e( 'Encryption password', 'lifeboat-backup' ); ?>" required>
			<button class="button button-primary"><?php esc_html_e( 'Unlock', 'lifeboat-backup' ); ?></button>
		</form>
	<?php elseif ( $lb_vault_on ) : ?>
		<p><?php echo esc_html( sprintf( __( 'Every backup made since %s is encrypted with AES-256 before it leaves this server. Cloud storage only ever receives encrypted files.', 'lifeboat-backup' ), wp_date( get_option( 'date_format' ), (int) $lb_vault_cfg['since'] ) ) ); ?></p>
		<p class="lb-muted"><?php esc_html_e( 'Restoring on this site needs no password. Restoring on a new site, or with the decrypt tool, needs the encryption password.', 'lifeboat-backup' ); ?></p>
		<details class="lb-setup">
			<summary><?php esc_html_e( 'Change password', 'lifeboat-backup' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lb-dest__form lb-enc-form" autocomplete="off">
				<input type="hidden" name="action" value="lifeboat_encryption"><input type="hidden" name="do" value="change">
				<?php wp_nonce_field( 'lifeboat_encryption' ); ?>
				<label><?php esc_html_e( 'Current password', 'lifeboat-backup' ); ?><input type="password" name="current" class="regular-text" autocomplete="current-password" required></label>
				<label><?php esc_html_e( 'New password', 'lifeboat-backup' ); ?><input type="password" name="password" class="regular-text" autocomplete="new-password" minlength="<?php echo (int) Lifeboat_Vault::MIN_PASS; ?>" required></label>
				<label><?php esc_html_e( 'New password again', 'lifeboat-backup' ); ?><input type="password" name="confirm" class="regular-text" autocomplete="new-password" required></label>
				<label class="lb-check"><input type="checkbox" name="understand" value="1" required> <?php esc_html_e( 'I have saved the new password somewhere safe. Older backups keep their old password.', 'lifeboat-backup' ); ?></label>
				<button class="button"><?php esc_html_e( 'Change password', 'lifeboat-backup' ); ?></button>
			</form>
		</details>
		<details class="lb-setup">
			<summary><?php esc_html_e( 'Turn encryption off', 'lifeboat-backup' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lb-dest__form" autocomplete="off">
				<input type="hidden" name="action" value="lifeboat_encryption"><input type="hidden" name="do" value="off">
				<?php wp_nonce_field( 'lifeboat_encryption' ); ?>
				<p class="lb-muted"><?php esc_html_e( 'New backups will not be encrypted. Existing encrypted backups stay encrypted and still need their password.', 'lifeboat-backup' ); ?></p>
				<label><?php esc_html_e( 'Current password', 'lifeboat-backup' ); ?><input type="password" name="password" class="regular-text" autocomplete="current-password" required></label>
				<button class="button button-link-delete"><?php esc_html_e( 'Turn off encryption', 'lifeboat-backup' ); ?></button>
			</form>
		</details>
	<?php else : ?>
		<p><?php esc_html_e( 'Encrypt backups with a password before they leave this server, so nobody with access to your cloud storage can read your database, customer data or files.', 'lifeboat-backup' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lb-dest__form lb-enc-form" autocomplete="off">
			<input type="hidden" name="action" value="lifeboat_encryption"><input type="hidden" name="do" value="on">
			<?php wp_nonce_field( 'lifeboat_encryption' ); ?>
			<label><?php esc_html_e( 'Encryption password', 'lifeboat-backup' ); ?><input type="password" name="password" class="regular-text" autocomplete="new-password" minlength="<?php echo (int) Lifeboat_Vault::MIN_PASS; ?>" required></label>
			<label><?php esc_html_e( 'Password again', 'lifeboat-backup' ); ?><input type="password" name="confirm" class="regular-text" autocomplete="new-password" required></label>
			<p class="lb-warn"><?php esc_html_e( 'Store this password in a password manager. If you lose it and this site is gone, your encrypted backups cannot be opened by anyone, including Lifeboat support.', 'lifeboat-backup' ); ?></p>
			<label class="lb-check"><input type="checkbox" name="understand" value="1" required> <?php esc_html_e( 'I have saved this password somewhere safe.', 'lifeboat-backup' ); ?></label>
			<button class="button button-primary"><?php esc_html_e( 'Turn on encryption', 'lifeboat-backup' ); ?></button>
		</form>
	<?php endif; ?>
</section>

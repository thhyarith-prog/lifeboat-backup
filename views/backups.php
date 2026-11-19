<?php
/**
 * Backups screen.
 *
 * @package Lifeboat_Backup
 */

defined( 'ABSPATH' ) || exit;

$history   = Lifeboat_History::all();
$settings  = Lifeboat_Settings::all();
$dests     = Lifeboat_Destination::registry();
$connected = array_filter(
	$dests,
	function ( $d ) {
		return $d->is_connected();
	}
);
$last      = get_option( 'lifeboat_last_backup' );
$next_full = wp_next_scheduled( Lifeboat_Scheduler::FULL );
$next_db   = wp_next_scheduled( Lifeboat_Scheduler::DB );
$fmt       = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
?>
<div class="lb-grid">
	<section class="lb-card lb-hero">
		<div>
			<h2><?php esc_html_e( 'Back up now', 'lifeboat-backup' ); ?></h2>
			<?php Lifeboat_Admin::last_backup_status(); ?>
			<p class="lb-muted">
				<?php
				if ( $next_full ) {
					echo esc_html( sprintf( __( 'Next full backup: %s.', 'lifeboat-backup' ), wp_date( $fmt, $next_full ) ) ) . ' ';
				}
				if ( $next_db ) {
					echo esc_html( sprintf( __( 'Next database backup: %s.', 'lifeboat-backup' ), wp_date( $fmt, $next_db ) ) );
				}
				?>
			</p>
			<?php if ( Lifeboat_Runner::loopback_blocked() ) : ?>
				<p class="lb-warn"><?php esc_html_e( 'A firewall or bot check (for example Cloudflare\'s "Just a moment…" page) blocks this site\'s requests to itself, so large backups move forward only on the background schedule and while this screen is open. They still finish, just more slowly. To speed them up, let requests from your own server reach wp-admin/admin-ajax.php.', 'lifeboat-backup' ); ?></p>
			<?php endif; ?>
		</div>
		<form id="lb-backup-form" class="lb-backup-form">
			<fieldset>
				<legend><?php esc_html_e( 'Include', 'lifeboat-backup' ); ?></legend>
				<?php foreach ( Lifeboat_Settings::COMPONENTS as $c ) : ?>
					<label class="lb-pick"><input type="checkbox" name="components[]" value="<?php echo esc_attr( $c ); ?>" <?php checked( in_array( $c, (array) $settings['components'], true ) ); ?>><span><?php echo esc_html( Lifeboat_Backup::label( $c ) ); ?></span></label>
				<?php endforeach; ?>
			</fieldset>
			<fieldset>
				<legend><?php esc_html_e( 'Send to', 'lifeboat-backup' ); ?></legend>
				<?php if ( ! $connected ) : ?>
					<span class="lb-muted"><?php esc_html_e( 'This server only.', 'lifeboat-backup' ); ?> <a href="<?php echo esc_url( Lifeboat_Admin::url( 'storage' ) ); ?>"><?php esc_html_e( 'Connect cloud storage', 'lifeboat-backup' ); ?></a></span>
				<?php endif; ?>
				<?php foreach ( $connected as $id => $d ) : ?>
					<label class="lb-pick"><input type="checkbox" name="destinations[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, (array) $settings['destinations'], true ) ); ?>><span><?php echo esc_html( $d->label() ); ?></span></label>
				<?php endforeach; ?>
			</fieldset>
			<fieldset class="lb-modes">
				<legend><?php esc_html_e( 'Speed', 'lifeboat-backup' ); ?></legend>
				<label class="lb-mode"><input type="radio" name="mode" value="rush" <?php checked( 'rush', $settings['mode_manual'] ); ?>><span><strong><?php esc_html_e( 'Rush', 'lifeboat-backup' ); ?></strong><small><?php esc_html_e( 'As fast as your server allows', 'lifeboat-backup' ); ?></small></span></label>
				<label class="lb-mode"><input type="radio" name="mode" value="easy" <?php checked( 'easy', $settings['mode_manual'] ); ?>><span><strong><?php esc_html_e( 'Easy', 'lifeboat-backup' ); ?></strong><small><?php esc_html_e( 'Gentle, visitors won\'t notice', 'lifeboat-backup' ); ?></small></span></label>
			</fieldset>
			<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Start backup', 'lifeboat-backup' ); ?></button>
		</form>
	</section>

	<section class="lb-card lb-job" id="lb-job" hidden aria-labelledby="lb-job-title">
		<div class="lb-job__head">
			<span class="lb-job__icon" aria-hidden="true" hidden></span>
			<h2 class="lb-job__title" id="lb-job-title"></h2>
			<button type="button" class="button lb-job__cancel"><?php esc_html_e( 'Stop', 'lifeboat-backup' ); ?></button>
			<button type="button" class="button-link lb-job__dismiss" aria-label="<?php esc_attr_e( 'Dismiss', 'lifeboat-backup' ); ?>" hidden>&times;</button>
		</div>
		<div class="lb-progress"><span></span></div>
		<p class="lb-job__msg" role="status" aria-live="polite"></p>
		<div class="lb-job__result" hidden></div>
		<p class="lb-muted lb-job__hint"><?php esc_html_e( 'Runs in the background — you can close this page.', 'lifeboat-backup' ); ?></p>
		<ul class="lb-job__warnings"></ul>
	</section>
</div>

<section class="lb-card">
	<h2><?php esc_html_e( 'Your backups', 'lifeboat-backup' ); ?></h2>
	<?php if ( ! $history ) : ?>
		<p class="lb-empty"><?php esc_html_e( 'No backups yet. Start your first one above — it runs in the background.', 'lifeboat-backup' ); ?></p>
	<?php else : ?>
		<div class="lb-table-wrap">
		<table class="lb-table">
			<thead><tr><th><?php esc_html_e( 'Date', 'lifeboat-backup' ); ?></th><th><?php esc_html_e( 'Contents', 'lifeboat-backup' ); ?></th><th><?php esc_html_e( 'Size', 'lifeboat-backup' ); ?></th><th><?php esc_html_e( 'Stored on', 'lifeboat-backup' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $history as $id => $e ) : ?>
				<?php
				$where = array();
				if ( ! empty( $e['local'] ) ) {
					$where['local'] = __( 'This server', 'lifeboat-backup' );
				}
				foreach ( array_keys( (array) $e['remote'] ) as $d ) {
					if ( isset( $dests[ $d ] ) ) {
						$where[ $d ] = $dests[ $d ]->label();
					}
				}
				$comps  = array_values( array_unique( wp_list_pluck( $e['files'], 'component' ) ) );
				$enc    = ! empty( $e['encryption'] );
				$locked = $enc && ! Lifeboat_Vault::can_open( (array) $e['encryption'] );
				?>
				<tr data-backup="<?php echo esc_attr( $id ); ?>" data-encrypted="<?php echo $enc ? '1' : ''; ?>" data-locked="<?php echo $locked ? '1' : ''; ?>" data-components="<?php echo esc_attr( wp_json_encode( $comps ) ); ?>" data-sources="<?php echo esc_attr( wp_json_encode( $where ) ); ?>">
					<td>
						<strong><?php echo esc_html( wp_date( $fmt, $e['time'] ) ); ?></strong>
						<br><span class="lb-badge lb-badge--<?php echo esc_attr( $e['trigger'] ); ?>"><?php echo esc_html( Lifeboat_Admin::trigger_label( $e['trigger'] ) ); ?></span>
						<?php if ( 'done' !== $e['status'] ) : ?>
							<span class="lb-badge lb-badge--warn"><?php esc_html_e( 'Incomplete', 'lifeboat-backup' ); ?></span>
						<?php elseif ( ! empty( $e['failed'] ) ) : ?>
							<span class="lb-badge lb-badge--warn" title="<?php echo esc_attr( sprintf( __( 'Not uploaded to: %s', 'lifeboat-backup' ), implode( ', ', array_map( function ( $d ) use ( $dests ) { return isset( $dests[ $d ] ) ? $dests[ $d ]->label() : $d; }, $e['failed'] ) ) ) ); ?>">! <?php esc_html_e( 'Partial', 'lifeboat-backup' ); ?></span>
						<?php else : ?>
							<span class="lb-badge lb-badge--ok">&#10003; <?php esc_html_e( 'Successful', 'lifeboat-backup' ); ?></span>
						<?php endif; ?>
						<?php if ( $enc ) : ?>
							<span class="lb-badge lb-badge--enc" title="<?php echo esc_attr( $locked ? __( 'Encrypted with a different password than this site uses: restoring asks for it.', 'lifeboat-backup' ) : __( 'Encrypted with AES-256. This site can restore it without asking for the password.', 'lifeboat-backup' ) ); ?>"><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e( 'Encrypted', 'lifeboat-backup' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php foreach ( $comps as $c ) : ?><span class="lb-chip"><?php echo esc_html( Lifeboat_Backup::label( $c ) ); ?></span><?php endforeach; ?></td>
					<td><?php echo esc_html( Lifeboat_Util::bytes( $e['size'] ) ); ?></td>
					<td class="lb-where">
						<?php foreach ( $where as $label ) : ?>
							<span class="lb-place"><span class="lb-tick" aria-hidden="true">&#10003;</span> <?php echo esc_html( $label ); ?></span>
						<?php endforeach; ?>
						<?php foreach ( isset( $e['failed'] ) ? (array) $e['failed'] : array() as $d ) : ?>
							<span class="lb-place is-bad"><span class="lb-tick" aria-hidden="true">&#10007;</span> <?php echo esc_html( isset( $dests[ $d ] ) ? $dests[ $d ]->label() : $d ); ?><span class="screen-reader-text"> <?php esc_html_e( '(upload failed)', 'lifeboat-backup' ); ?></span></span>
						<?php endforeach; ?>
						<?php echo $where || ! empty( $e['failed'] ) ? '' : '—'; ?>
					</td>
					<td class="lb-actions">
						<button type="button" class="button button-small" data-act="restore"><?php esc_html_e( 'Restore', 'lifeboat-backup' ); ?></button>
						<?php if ( ! empty( $e['local'] ) ) : ?>
							<details class="lb-dl">
								<summary class="button button-small"><?php esc_html_e( 'Download', 'lifeboat-backup' ); ?></summary>
								<div>
									<?php foreach ( $e['files'] as $name => $f ) : ?>
										<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'lifeboat_download', 'backup' => $id, 'file' => $name ), admin_url( 'admin-post.php' ) ), 'lifeboat_download' ) ); ?>"><?php echo esc_html( $name ); ?> <small><?php echo esc_html( Lifeboat_Util::bytes( $f['size'] ) ); ?></small></a>
									<?php endforeach; ?>
								</div>
							</details>
						<?php endif; ?>
						<button type="button" class="button button-small button-link-delete" data-act="delete"><?php esc_html_e( 'Delete', 'lifeboat-backup' ); ?></button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	<?php endif; ?>
</section>

<?php $lb_snaps = Lifeboat_Snapshots::all(); ?>
<section class="lb-card" id="lb-snapshots">
	<h2><?php esc_html_e( 'Update snapshots', 'lifeboat-backup' ); ?></h2>
	<p class="lb-muted"><?php esc_html_e( 'The version of each plugin or theme that was installed just before it was updated. If an update breaks your site, put the previous version back. Only that plugin\'s or theme\'s files are changed; the database is not.', 'lifeboat-backup' ); ?></p>
	<?php if ( ! $lb_snaps ) : ?>
		<p class="lb-empty"><?php echo Lifeboat_Settings::get( 'pre_update_snapshot' ) ? esc_html__( 'No snapshots yet. One is saved automatically the next time a plugin or theme updates.', 'lifeboat-backup' ) : esc_html__( 'Update snapshots are off (Schedule & settings).', 'lifeboat-backup' ); ?></p>
	<?php else : ?>
		<div class="lb-table-wrap"><table class="lb-table">
			<thead><tr><th><?php esc_html_e( 'Plugin or theme', 'lifeboat-backup' ); ?></th><th><?php esc_html_e( 'Saved version', 'lifeboat-backup' ); ?></th><th><?php esc_html_e( 'Installed now', 'lifeboat-backup' ); ?></th><th><?php esc_html_e( 'Saved', 'lifeboat-backup' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $lb_snaps as $sn ) : ?>
				<?php $now = Lifeboat_Snapshots::installed_version( $sn ); ?>
				<tr>
					<td><strong><?php echo esc_html( $sn['name'] ); ?></strong><br><span class="lb-badge"><?php echo 'plugin' === $sn['type'] ? esc_html__( 'Plugin', 'lifeboat-backup' ) : esc_html__( 'Theme', 'lifeboat-backup' ); ?></span><?php if ( 'rollback' === $sn['reason'] ) : ?> <span class="lb-badge lb-badge--warn"><?php esc_html_e( 'Saved before a rollback', 'lifeboat-backup' ); ?></span><?php endif; ?></td>
					<td><code><?php echo esc_html( $sn['version'] ? $sn['version'] : '?' ); ?></code></td>
					<td><?php echo $now ? '<code>' . esc_html( $now ) . '</code>' : '<span class="lb-muted">' . esc_html__( 'not installed', 'lifeboat-backup' ) . '</span>'; ?><?php if ( $now && $now === $sn['version'] ) : ?> <span class="lb-badge lb-badge--ok"><?php esc_html_e( 'Same', 'lifeboat-backup' ); ?></span><?php endif; ?></td>
					<td><?php echo esc_html( sprintf( __( '%s ago', 'lifeboat-backup' ), human_time_diff( $sn['time'] ) ) ); ?><br><span class="lb-muted"><?php echo esc_html( Lifeboat_Util::bytes( $sn['size'] ) ); ?></span></td>
					<td class="lb-actions">
						<?php if ( Lifeboat_Snapshots::can_roll_back( $sn ) && $now !== $sn['version'] ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( sprintf( __( 'Put %1$s %2$s back? The current version is saved first, so you can undo this.', 'lifeboat-backup' ), $sn['name'], $sn['version'] ) ) ); ?>)">
								<input type="hidden" name="action" value="lifeboat_rollback"><input type="hidden" name="snapshot" value="<?php echo esc_attr( $sn['id'] ); ?>">
								<?php wp_nonce_field( 'lifeboat_rollback_' . $sn['id'] ); ?>
								<button class="button button-small button-primary"><?php echo esc_html( sprintf( __( 'Roll back to %s', 'lifeboat-backup' ), $sn['version'] ) ); ?></button>
							</form>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" data-pd-confirm="<?php echo esc_attr( sprintf( __( 'Delete the saved copy of %1$s %2$s?', 'lifeboat-backup' ), $sn['name'], $sn['version'] ) ); ?>">
							<input type="hidden" name="action" value="lifeboat_snapshot_delete"><input type="hidden" name="snapshot" value="<?php echo esc_attr( $sn['id'] ); ?>">
							<?php wp_nonce_field( 'lifeboat_snapshot_delete_' . $sn['id'] ); ?>
							<button class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'lifeboat-backup' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
	<?php endif; ?>
</section>

<dialog id="lb-restore" class="lb-dialog" aria-labelledby="lb-restore-title">
	<form method="dialog" class="lb-restore-form">
		<h2 id="lb-restore-title"><?php esc_html_e( 'Restore backup', 'lifeboat-backup' ); ?></h2>
		<p class="lb-restore-date lb-muted"></p>
		<fieldset class="lb-restore-parts"><legend><?php esc_html_e( 'What to restore', 'lifeboat-backup' ); ?></legend></fieldset>
		<fieldset class="lb-restore-source"><legend><?php esc_html_e( 'Restore from', 'lifeboat-backup' ); ?></legend></fieldset>
		<label class="lb-password" hidden><span class="lb-label"><?php esc_html_e( 'Encryption password for this backup', 'lifeboat-backup' ); ?></span><input type="password" name="password" class="regular-text" autocomplete="current-password"><small class="lb-muted"><?php esc_html_e( 'This backup was encrypted with a password this site does not have.', 'lifeboat-backup' ); ?></small></label>
		<label class="lb-keep"><input type="checkbox" name="keep_url" value="1" checked> <?php esc_html_e( 'Keep this site\'s current address (recommended when restoring a backup from another domain)', 'lifeboat-backup' ); ?></label>
		<p class="lb-muted"><?php esc_html_e( 'The database is rebuilt in temporary tables and swapped in at the end, so your site keeps working until the restore finishes. wp-config.php and Lifeboat itself are never overwritten.', 'lifeboat-backup' ); ?></p>
		<p class="lb-dialog__actions"><button value="cancel" class="button"><?php esc_html_e( 'Cancel', 'lifeboat-backup' ); ?></button> <button value="ok" class="button button-primary"><?php esc_html_e( 'Restore now', 'lifeboat-backup' ); ?></button></p>
	</form>
</dialog>

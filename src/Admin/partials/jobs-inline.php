<?php
// Jobs page template.
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Queue Jobs', 'redis-queue-demo' ); ?></h1>
	<div class="purge-buttons" style="margin-top:10px; margin-bottom:15px;">
		<strong><?php esc_html_e( 'Purge Jobs:', 'redis-queue-demo' ); ?></strong>
		<label for="purge-days" style="margin-left:8px;">
			<?php esc_html_e( 'Older Than (days):', 'redis-queue-demo' ); ?>
			<input type="number" id="purge-days" value="7" min="1" style="width:70px;" />
		</label>
		<button type="button" class="button" data-purge-scope="completed"
			id="purge-completed-jobs"><?php esc_html_e( 'Completed', 'redis-queue-demo' ); ?></button>
		<button type="button" class="button" data-purge-scope="failed"
			id="purge-failed-jobs"><?php esc_html_e( 'Failed', 'redis-queue-demo' ); ?></button>
		<button type="button" class="button" data-purge-scope="older"
			id="purge-older-jobs"><?php esc_html_e( 'Older Than N', 'redis-queue-demo' ); ?></button>
		<button type="button" class="button button-danger" data-purge-scope="all"
			id="purge-all-jobs"><?php esc_html_e( 'All (Danger)', 'redis-queue-demo' ); ?></button>
		<div id="purge-result" style="margin-top:10px;"></div>
	</div>
	<div class="tablenav top">
		<form method="get" action="">
			<input type="hidden" name="page" value="redis-queue-jobs">
			<select name="status">
				<option value=""><?php esc_html_e( 'All Statuses', 'redis-queue-demo' ); ?></option>
				<option value="queued" <?php selected( $status_filter, 'queued' ); ?>>
					<?php esc_html_e( 'Queued', 'redis-queue-demo' ); ?></option>
				<option value="processing" <?php selected( $status_filter, 'processing' ); ?>>
					<?php esc_html_e( 'Processing', 'redis-queue-demo' ); ?></option>
				<option value="completed" <?php selected( $status_filter, 'completed' ); ?>>
					<?php esc_html_e( 'Completed', 'redis-queue-demo' ); ?></option>
				<option value="failed" <?php selected( $status_filter, 'failed' ); ?>>
					<?php esc_html_e( 'Failed', 'redis-queue-demo' ); ?></option>
				<option value="cancelled" <?php selected( $status_filter, 'cancelled' ); ?>>
					<?php esc_html_e( 'Cancelled', 'redis-queue-demo' ); ?></option>
			</select>
			<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'redis-queue-demo' ); ?>">
		</form>
	</div>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Job ID', 'redis-queue-demo' ); ?></th>
				<th><?php esc_html_e( 'Type', 'redis-queue-demo' ); ?></th>
				<th><?php esc_html_e( 'Queue', 'redis-queue-demo' ); ?></th>
				<th><?php esc_html_e( 'Status', 'redis-queue-demo' ); ?></th>
				<th><?php esc_html_e( 'Priority', 'redis-queue-demo' ); ?></th>
				<th><?php esc_html_e( 'Attempts', 'redis-queue-demo' ); ?></th>
				<th><?php esc_html_e( 'Created', 'redis-queue-demo' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'redis-queue-demo' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $jobs ) ) : ?>
				<tr>
					<td colspan="8"><?php esc_html_e( 'No jobs found.', 'redis-queue-demo' ); ?></td>
				</tr>
			<?php else :
				foreach ( $jobs as $job ) : ?>
					<tr>
						<td><?php echo esc_html( substr( $job[ 'job_id' ], 0, 8 ) . '...' ); ?></td>
						<td><?php echo esc_html( $job[ 'job_type' ] ); ?></td>
						<td><?php echo esc_html( $job[ 'queue_name' ] ); ?></td>
						<td><span
								class="status-badge status-<?php echo esc_attr( $job[ 'status' ] ); ?>"><?php echo esc_html( ucfirst( $job[ 'status' ] ) ); ?></span>
						</td>
						<td><?php echo esc_html( $job[ 'priority' ] ); ?></td>
						<td><?php echo esc_html( $job[ 'attempts' ] . '/' . $job[ 'max_attempts' ] ); ?></td>
						<td><?php echo esc_html( mysql2date( 'Y-m-d H:i:s', $job[ 'created_at' ] ) ); ?></td>
						<td><a href="#" class="view-job"
								data-job-id="<?php echo esc_attr( $job[ 'job_id' ] ); ?>"><?php esc_html_e( 'View', 'redis-queue-demo' ); ?></a><?php if ( in_array( $job[ 'status' ], [ 'queued', 'failed' ], true ) ) : ?>
								| <a href="#" class="cancel-job"
									data-job-id="<?php echo esc_attr( $job[ 'job_id' ] ); ?>"><?php esc_html_e( 'Cancel', 'redis-queue-demo' ); ?></a><?php endif; ?>
						</td>
					</tr>
				<?php endforeach; endif; ?>
		</tbody>
	</table>
	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav bottom">
			<?php echo paginate_links( [ 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'prev_text' => __( '&laquo; Previous', 'redis-queue-demo' ), 'next_text' => __( 'Next &raquo;', 'redis-queue-demo' ), 'total' => $total_pages, 'current' => $current_page ] ); ?>
		</div>
	<?php endif; ?>
</div>
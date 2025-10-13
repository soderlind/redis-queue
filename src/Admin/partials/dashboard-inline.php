<?php
// Dashboard template (ported from legacy admin/class-admin-interface.php)
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Redis Queue Dashboard', 'redis-queue' ); ?></h1>
	<div class="redis-queue-health-status <?php echo $health[ 'overall' ] ? 'healthy' : 'unhealthy'; ?>">
		<h2><?php esc_html_e( 'System Health', 'redis-queue' ); ?> <span class="status-indicator"></span></h2>
		<div class="health-details">
			<div class="health-item"><span
					class="label"><?php esc_html_e( 'Redis Connection:', 'redis-queue' ); ?></span><span
					class="value <?php echo $health[ 'redis' ] ? 'connected' : 'disconnected'; ?>"><?php echo $health[ 'redis' ] ? esc_html__( 'Connected', 'redis-queue' ) : esc_html__( 'Disconnected', 'redis-queue' ); ?></span>
			</div>
			<div class="health-item"><span
					class="label"><?php esc_html_e( 'Database:', 'redis-queue' ); ?></span><span
					class="value <?php echo $health[ 'database' ] ? 'ok' : 'error'; ?>"><?php echo $health[ 'database' ] ? esc_html__( 'OK', 'redis-queue' ) : esc_html__( 'Error', 'redis-queue' ); ?></span>
			</div>
		</div>
	</div>
	<div class="redis-queue-stats">
		<div class="stat-box">
			<h3><?php esc_html_e( 'Queued Jobs', 'redis-queue' ); ?></h3>
			<div class="stat-number" id="queued-jobs"><?php echo esc_html( $flat_stats[ 'queued' ] ?? 0 ); ?></div>
		</div>
		<div class="stat-box">
			<h3><?php esc_html_e( 'Processing', 'redis-queue' ); ?></h3>
			<div class="stat-number" id="processing-jobs"><?php echo esc_html( $flat_stats[ 'processing' ] ?? 0 ); ?>
			</div>
		</div>
		<div class="stat-box">
			<h3><?php esc_html_e( 'Completed', 'redis-queue' ); ?></h3>
			<div class="stat-number" id="completed-jobs"><?php echo esc_html( $flat_stats[ 'completed' ] ?? 0 ); ?></div>
		</div>
		<div class="stat-box">
			<h3><?php esc_html_e( 'Failed', 'redis-queue' ); ?></h3>
			<div class="stat-number" id="failed-jobs"><?php echo esc_html( $flat_stats[ 'failed' ] ?? 0 ); ?></div>
		</div>
	</div>
	<div class="redis-queue-controls">
		<h2><?php esc_html_e( 'Worker Controls', 'redis-queue' ); ?></h2>
		<div class="control-buttons">
			<button type="button" class="button button-primary"
				id="trigger-worker"><?php esc_html_e( 'Trigger Worker', 'redis-queue' ); ?></button>
			<button type="button" class="button"
				id="refresh-stats"><?php esc_html_e( 'Refresh Stats', 'redis-queue' ); ?></button>
			<button type="button" class="button button-secondary"
				id="run-diagnostics"><?php esc_html_e( 'Run Diagnostics', 'redis-queue' ); ?></button>
			<button type="button" class="button button-secondary"
				id="debug-test"><?php esc_html_e( 'Full Debug Test', 'redis-queue' ); ?></button>
			<button type="button" class="button button-secondary"
				id="reset-stuck-jobs"><?php esc_html_e( 'Reset Stuck Jobs', 'redis-queue' ); ?></button>
		</div>
		<div id="diagnostics-result" style="margin-top:15px;"></div>
		<div id="debug-test-result" style="margin-top:15px;"></div>
		<div id="reset-result" style="margin-top:15px;"></div>
		<div class="redis-queue-overview">
			<h2><?php esc_html_e( 'Queue Overview', 'redis-queue' ); ?></h2>
			<div id="queue-stats-container"></div>
		</div>
	</div>
</div>
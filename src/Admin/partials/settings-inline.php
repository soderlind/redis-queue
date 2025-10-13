<?php
// Settings page template.
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Redis Queue Settings', 'redis-queue' ); ?></h1>
	<form method="post" action="">
		<?php wp_nonce_field( 'redis_queue_settings' ); ?>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Redis Host', 'redis-queue' ); ?></th>
				<td><input type="text" name="redis_host" value="<?php echo esc_attr( $options[ 'redis_host' ] ); ?>"
						class="regular-text" />
					<p class="description">
						<?php esc_html_e( 'Redis server hostname or IP address.', 'redis-queue' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Redis Port', 'redis-queue' ); ?></th>
				<td><input type="number" name="redis_port" value="<?php echo esc_attr( $options[ 'redis_port' ] ); ?>"
						class="small-text" />
					<p class="description"><?php esc_html_e( 'Redis server port number.', 'redis-queue' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Redis Database', 'redis-queue' ); ?></th>
				<td><input type="number" name="redis_database"
						value="<?php echo esc_attr( $options[ 'redis_database' ] ); ?>" class="small-text" min="0"
						max="15" />
					<p class="description"><?php esc_html_e( 'Redis database number (0-15).', 'redis-queue' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Redis Password', 'redis-queue' ); ?></th>
				<td><input type="password" name="redis_password"
						value="<?php echo esc_attr( $options[ 'redis_password' ] ); ?>" class="regular-text" />
					<p class="description">
						<?php esc_html_e( 'Redis server password (leave empty if no password).', 'redis-queue' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Worker Timeout', 'redis-queue' ); ?></th>
				<td><input type="number" name="worker_timeout"
						value="<?php echo esc_attr( $options[ 'worker_timeout' ] ); ?>" class="small-text" min="5"
						max="300" />
					<p class="description">
						<?php esc_html_e( 'Maximum time in seconds for job execution.', 'redis-queue' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Max Retries', 'redis-queue' ); ?></th>
				<td><input type="number" name="max_retries" value="<?php echo esc_attr( $options[ 'max_retries' ] ); ?>"
						class="small-text" min="0" max="10" />
					<p class="description">
						<?php esc_html_e( 'Maximum number of retry attempts for failed jobs.', 'redis-queue' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Retry Delay', 'redis-queue' ); ?></th>
				<td><input type="number" name="retry_delay" value="<?php echo esc_attr( $options[ 'retry_delay' ] ); ?>"
						class="small-text" min="10" max="3600" />
					<p class="description">
						<?php esc_html_e( 'Base delay in seconds before retrying failed jobs.', 'redis-queue' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Batch Size', 'redis-queue' ); ?></th>
				<td><input type="number" name="batch_size" value="<?php echo esc_attr( $options[ 'batch_size' ] ); ?>"
						class="small-text" min="1" max="100" />
					<p class="description">
						<?php esc_html_e( 'Number of jobs to process in a single batch.', 'redis-queue' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'API Token', 'redis-queue' ); ?></th>
				<td><?php if ( ! empty( $options[ 'api_token' ] ) ) : ?><code
							style="display:inline-block; padding:2px 4px; background:#f0f0f0; user-select:all; max-width:500px; overflow-wrap:anywhere;"><?php echo esc_html( $options[ 'api_token' ] ); ?></code><br /><label
							style="display:inline-block; margin-top:6px;"><input type="checkbox" name="clear_api_token"
								value="1" />
							<?php esc_html_e( 'Clear token on save', 'redis-queue' ); ?></label><?php else : ?><em><?php esc_html_e( 'No token set.', 'redis-queue' ); ?></em><?php endif; ?>
					<p class="description" style="margin-top:6px;">
						<?php esc_html_e( 'Use this token to authenticate to the plugin REST API without WordPress cookies. Send it as "Authorization: Bearer <token>" or "X-Redis-Queue-Token: <token>". Possession grants the same access as an admin for these endpoints; keep it secret.', 'redis-queue' ); ?>
					</p>
					<p style="margin-top:8px;"><button type="submit" name="generate_api_token"
							class="button"><?php echo empty( $options[ 'api_token' ] ) ? esc_html__( 'Generate Token', 'redis-queue' ) : esc_html__( 'Regenerate Token', 'redis-queue' ); ?></button>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Token Scope', 'redis-queue' ); ?></th>
				<td><select name="api_token_scope">
						<option value="worker" <?php selected( $options[ 'api_token_scope' ], 'worker' ); ?>>
							<?php esc_html_e( 'Worker Only (trigger endpoint)', 'redis-queue' ); ?></option>
						<option value="full" <?php selected( $options[ 'api_token_scope' ], 'full' ); ?>>
							<?php esc_html_e( 'Full Access (all endpoints)', 'redis-queue' ); ?></option>
					</select>
					<p class="description">
						<?php esc_html_e( 'Limit what the API token can call. "Worker Only" restricts to /workers/trigger.', 'redis-queue' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Rate Limit (per minute)', 'redis-queue' ); ?></th>
				<td><input type="number" name="rate_limit_per_minute"
						value="<?php echo esc_attr( $options[ 'rate_limit_per_minute' ] ); ?>" class="small-text" min="1"
						max="1000" />
					<p class="description">
						<?php esc_html_e( 'Maximum token-authenticated requests per minute. Applies per token.', 'redis-queue' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Request Logging', 'redis-queue' ); ?></th>
				<td><label><input type="checkbox" name="enable_request_logging" value="1" <?php checked( $options[ 'enable_request_logging' ], 1 ); ?> />
						<?php esc_html_e( 'Enable logging of API requests (namespace: redis-queue/v1)', 'redis-queue' ); ?></label>
					<p class="description">
						<?php esc_html_e( 'Logs contain timestamp, route, status, auth method, and IP. Stored in uploads/redis-queue-logs/', 'redis-queue' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Log Rotation', 'redis-queue' ); ?></th>
				<td><label><?php esc_html_e( 'Max File Size (KB):', 'redis-queue' ); ?> <input type="number"
							name="log_rotate_size_kb" value="<?php echo esc_attr( $options[ 'log_rotate_size_kb' ] ); ?>"
							class="small-text" min="8" max="16384" /></label> <label
						style="margin-left:12px;"><?php esc_html_e( 'Max Files:', 'redis-queue' ); ?> <input
							type="number" name="log_max_files"
							value="<?php echo esc_attr( $options[ 'log_max_files' ] ); ?>" class="small-text" min="1"
							max="50" /></label>
					<p class="description">
						<?php esc_html_e( 'When size exceeded, file is rotated with timestamp. Oldest files removed beyond max files.', 'redis-queue' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
	<div class="redis-queue-connection-test">
		<h2><?php esc_html_e( 'Connection Test', 'redis-queue' ); ?></h2><button type="button" class="button"
			id="test-redis-connection"><?php esc_html_e( 'Test Redis Connection', 'redis-queue' ); ?></button>
		<div id="connection-test-result"></div>
	</div>
</div>
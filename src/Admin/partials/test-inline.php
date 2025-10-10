<?php
// Test Jobs page template.
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Test Jobs', 'redis-queue-demo' ); ?></h1>
	<p><?php esc_html_e( 'Create test jobs to verify the queue system is working correctly.', 'redis-queue-demo' ); ?>
	</p>
	<?php wp_nonce_field( 'wp_rest', '_wpnonce' ); ?>
	<div class="redis-queue-test-forms">
		<div class="test-form-section">
			<h2><?php esc_html_e( 'Test Email Job', 'redis-queue-demo' ); ?></h2>
			<form id="test-email-job" class="test-job-form">
				<table class="form-table">
					<tr>
						<th><label for="email-type"><?php esc_html_e( 'Email Type:', 'redis-queue-demo' ); ?></label>
						</th>
						<td><select id="email-type" name="email_type">
								<option value="single"><?php esc_html_e( 'Single Email', 'redis-queue-demo' ); ?>
								</option>
								<option value="bulk"><?php esc_html_e( 'Bulk Email', 'redis-queue-demo' ); ?></option>
								<option value="newsletter"><?php esc_html_e( 'Newsletter', 'redis-queue-demo' ); ?>
								</option>
							</select></td>
					</tr>
					<tr>
						<th><label for="email-to"><?php esc_html_e( 'To:', 'redis-queue-demo' ); ?></label></th>
						<td><input type="text" id="email-to" name="to"
								value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label for="email-subject"><?php esc_html_e( 'Subject:', 'redis-queue-demo' ); ?></label>
						</th>
						<td><input type="text" id="email-subject" name="subject" value="Test Email from Redis Queue"
								class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="email-message"><?php esc_html_e( 'Message:', 'redis-queue-demo' ); ?></label>
						</th>
						<td><textarea id="email-message" name="message" class="large-text"
								rows="4">This is a test email sent through the Redis queue system.</textarea></td>
					</tr>
				</table>
				<button type="submit"
					class="button button-primary"><?php esc_html_e( 'Queue Email Job', 'redis-queue-demo' ); ?></button>
			</form>
		</div>
		<div class="test-form-section">
			<h2><?php esc_html_e( 'Test Image Processing Job', 'redis-queue-demo' ); ?></h2>
			<form id="test-image-job" class="test-job-form">
				<table class="form-table">
					<tr>
						<th><label
								for="image-operation"><?php esc_html_e( 'Operation:', 'redis-queue-demo' ); ?></label>
						</th>
						<td><select id="image-operation" name="operation">
								<option value="thumbnail">
									<?php esc_html_e( 'Generate Thumbnails', 'redis-queue-demo' ); ?></option>
								<option value="optimize"><?php esc_html_e( 'Optimize Image', 'redis-queue-demo' ); ?>
								</option>
								<option value="watermark"><?php esc_html_e( 'Add Watermark', 'redis-queue-demo' ); ?>
								</option>
							</select></td>
					</tr>
					<tr>
						<th><label
								for="attachment-id"><?php esc_html_e( 'Attachment ID:', 'redis-queue-demo' ); ?></label>
						</th>
						<td><input type="number" id="attachment-id" name="attachment_id" value="1" class="small-text">
							<p class="description">
								<?php esc_html_e( 'Enter a valid attachment ID from your media library.', 'redis-queue-demo' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<button type="submit"
					class="button button-primary"><?php esc_html_e( 'Queue Image Job', 'redis-queue-demo' ); ?></button>
			</form>
		</div>
		<div class="test-form-section">
			<h2><?php esc_html_e( 'Test API Sync Job', 'redis-queue-demo' ); ?></h2>
			<form id="test-api-job" class="test-job-form">
				<table class="form-table">
					<tr>
						<th><label for="api-operation"><?php esc_html_e( 'Operation:', 'redis-queue-demo' ); ?></label>
						</th>
						<td><select id="api-operation" name="operation">
								<option value="social_media_post">
									<?php esc_html_e( 'Social Media Post', 'redis-queue-demo' ); ?></option>
								<option value="crm_sync"><?php esc_html_e( 'CRM Sync', 'redis-queue-demo' ); ?></option>
								<option value="webhook"><?php esc_html_e( 'Webhook', 'redis-queue-demo' ); ?></option>
							</select></td>
					</tr>
					<tr>
						<th><label for="api-url"><?php esc_html_e( 'API URL:', 'redis-queue-demo' ); ?></label></th>
						<td><input type="url" id="api-url" name="api_url" value="https://httpbin.org/post"
								class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="api-data"><?php esc_html_e( 'Data (JSON):', 'redis-queue-demo' ); ?></label>
						</th>
						<td><textarea id="api-data" name="data" class="large-text"
								rows="4">{"message": "Test API sync from Redis Queue"}</textarea></td>
					</tr>
				</table>
				<button type="submit"
					class="button button-primary"><?php esc_html_e( 'Queue API Job', 'redis-queue-demo' ); ?></button>
			</form>
		</div>
	</div>
	<div id="test-results" class="test-results" style="display:none;">
		<h3><?php esc_html_e( 'Test Results', 'redis-queue-demo' ); ?></h3>
		<div id="test-output"></div>
	</div>
</div>
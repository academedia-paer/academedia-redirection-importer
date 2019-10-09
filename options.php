<?php

/** Step 2 (from text above). */
if ( is_admin() ){ // admin actions
	add_action( 'admin_menu', 'redirection_importer_menu' );
	add_action( 'admin_init', 'register_redirection_importer_settings' );
} else {
	// non-admin enqueues, actions, and filters
}

function register_redirection_importer_settings() {
	register_setting( 'redirection_importer_settings', 'google_sheet_key' );
}

/** Step 1. */
function redirection_importer_menu() {
	add_options_page( 'Redirection importer', 'Redirection importer', 'manage_options', 'redirection-importer-identifier', 'redirection_importer_options' );
}



/** Step 3. */
function redirection_importer_options() {

	if ( !current_user_can( 'manage_options' ) )  {
		wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	}
	?>

	<div class="wrap">
	<h1>Redirection Importer</h1>
	
	<form method="post" action="options.php">
		<?php
			settings_fields('redirection_importer_settings');
			do_settings_sections('redirection_importer_settings');
		?>
		<label>
			<strong>Google sheet key</strong><br/>
			<input type="text" name="google_sheet_key" value="<?php echo esc_attr( get_option('google_sheet_key') ); ?>" />
		</label>
		<br/>
		<?php submit_button(); ?>
	</form>

	<button class="import_redirections" type="button">Import</button>

	</div>
	<?php

	$htaccess = file_get_contents(plugin_dir_path( __FILE__ ) . '../../../.htaccess');

	preg_match('/\#[\s]*\[generated\-redirects\][\s]*[\n]/', $htaccess, $matches, PREG_OFFSET_CAPTURE);

	// if file does not contain already generated redirects
	if (empty($matches)) {
		$contained_already_generated_redirects = false;
	} else {
		$contained_already_generated_redirects = true;
		$generated_start_pos = $matches[0][1];
	}

	if ($contained_already_generated_redirects) {
		preg_match('/\[end\-of\-generated\-redirects\][\s]*([\n]|)/', $htaccess, $matches, PREG_OFFSET_CAPTURE);
		$generated_end_pos = $matches[0][1] + strlen('[end-of-generated-redirects]');

		// remove generated first to avoid doubles 
		$generated_segment = substr($htaccess, $generated_start_pos, $generated_end_pos - $generated_start_pos);
	}

	// Find all "Redirects 301"
	preg_match_all("/^Redirect[\s]+301.*$/m", $generated_segment, $all_current_redirects_, PREG_OFFSET_CAPTURE);

	echo "<div class='redirection_feedback'>";

		echo "<h3>Redirects already imported from the sheet</h3>" . PHP_EOL;

			echo "<ul>";
			if (!empty($all_current_redirects_)) {
				foreach ($all_current_redirects_[0] as $redir) {
					echo "<li>" . $redir[0] . "</li>";
				}
			} else {
				echo "<li>None</li>";
			}
			echo "</ul>";

		// Find commented out "Redirects 301"
		preg_match_all("/^#[\s]*Redirect[\s]+301.*$/m", $htaccess, $commented_redirects_, PREG_OFFSET_CAPTURE);

		echo "<h3>Redirects commented out</h3>" . PHP_EOL;

			echo "<ul>";
			if (!empty($commented_redirects_)) {
				foreach ($commented_redirects_[0] as $redir) {
					echo "<li>" . $redir[0] . "</li>";
				}
			} else {
				echo "<li>None</li>";
			}
			echo "</ul>";

	echo "</div>"; ?>

	<script>
		var import_button = document.querySelector('button.import_redirections');
		var redirection_feedback = document.querySelector('.redirection_feedback');

		import_button.addEventListener('click', function() {
			_import();
		});

		function _import() {

			import_button.disabled = true;
			redirection_feedback.innerHTML = '<p>Preparing htaccess ...</p><p>Please try to stay koo!</p>';
			
			fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
				method: 'POST',
				credentials: 'same-origin',
				headers: new Headers({'Content-Type': 'application/x-www-form-urlencoded'}),
				body: 'action=import_redirections'
			})
			.then((resp) => resp.text())
			.then(function(response) {
				import_button.disabled = false;
				redirection_feedback.innerHTML = response;
			})
			.catch(function(error) {
				console.log(JSON.stringify(error));
			});

		}
	</script>

<?php }

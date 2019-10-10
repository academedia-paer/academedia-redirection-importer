<?php
/**
* Plugin Name: Redirection importer 
* Plugin URI: https://paer-henriksson.com 
* Description: Imports redirections from a google sheet 
* Version: 0.0
* Author: Pär Henriksson, academedia
* Author URI: https://paer-henriksson.com
**/
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

defined( 'ABSPATH' ) or die( 'Forbidden' );

function google_api_for_sheets() {
    wp_enqueue_script( 'google-api-for-reading-sheets', "https://maps.googleapis.com/maps/api/js?key=AIzaSyDKoeTNHC8cQM3rGw4hCdiz8smaLfqkL7Y" );
}
add_action('admin_enqueue_scripts', 'google_api_for_sheets');

include( dirname(__FILE__) . '/' . 'options.php');

// setup ajax 
add_action( 'wp_ajax_import_redirections', 'redirecion_importer_ajax' );

function redirecion_importer_ajax() {

    /**
     * Returns an authorized API client.
     * @return Google_Client the authorized client object
     */
    function getClient() {
        $client = new Google_Client();
        $client->setApplicationName('Google Sheets API PHP Quickstart');
        $client->setScopes(Google_Service_Sheets::SPREADSHEETS_READONLY);
        $client->setAuthConfig(__DIR__ . '/credentials.json');
        $client->setAccessType('offline');

        return $client;

        /*
        $client->setAuthConfig(__DIR__ . '/credentials.json');
        $client->setAccessType('offline');
        */
        // $client->setPrompt('select_account consent');
 
        // Load previously authorized token from a file, if it exists.
        // The file token.json stores the user's access and refresh tokens, and is
        // created automatically when the authorization flow completes for the first
        // time.
        $tokenPath = __DIR__ . '/token.json';
        if (file_exists($tokenPath)) {
            $accessToken = json_decode(file_get_contents($tokenPath), true);
            $client->setAccessToken($accessToken);
        }
        
        // If there is no previous token or it's expired.
        if ($client->isAccessTokenExpired()) {
            // Refresh the token if possible, else fetch a new one.
            if ($client->getRefreshToken()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            } else {
                // Request authorization from the user.
                $authUrl = $client->createAuthUrl();
                printf("Open the following link in your browser:\n%s\n", $authUrl);
                print 'Enter verification code: ';
                $authCode = trim(fgets(STDIN));

                // Exchange authorization code for an access token.
                $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
                $client->setAccessToken($accessToken);

                // Check to see if there was an error.
                if (array_key_exists('error', $accessToken)) {
                    throw new Exception(join(', ', $accessToken));
                }
            }
            // Save the token to a file.
            if (!file_exists(dirname($tokenPath))) {
                mkdir(dirname($tokenPath), 0700, true);
            }
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        }
        return $client;
    }

    // get sheet ID from options 
    $sheet_id = get_option('google_sheet_key');

    // Get the API client and construct the service object.
    $client = getClient();

    $service = new Google_Service_Sheets($client);

    $generated_301_array = [];
    $generated_redirects = '';
    $generated_redirects .= '# [generated-redirects]' . PHP_EOL;
    $generated_redirects .= '# Testing htaccess' . PHP_EOL;
    $generated_redirects .= 'Redirect 301 /wp-content/plugins/academedia-redirection-importer/htaccess_test-redirected.html ' . plugin_dir_url(__FILE__) . 'htaccess_test.html' . PHP_EOL;
    $generated_redirects .= '# END OF Testing htaccess' . PHP_EOL;

    $columns = $service->spreadsheets_values->get($sheet_id, '!A1:I');

    foreach($columns as $col) {
        $generated_redirects .= 'Redirect 301 ' . parse_url(trim($col[0]), PHP_URL_PATH) . ' ' . trim($col[1]) . PHP_EOL;
        $generated_301_array[] = [
            'from' => trim($col[0]),
            'to' => trim($col[1])
        ];
    }
    
    $generated_redirects .= '# [end-of-generated-redirects]' . PHP_EOL;
    
    $htaccess = file_get_contents(plugin_dir_path( __FILE__ ) . '../../../.htaccess');
    $original_htaccess = $htaccess;

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
        $htaccess = substr_replace($htaccess, '', $generated_start_pos, $generated_end_pos - $generated_start_pos);
    }

    // Find all other "Redirects 301"
    preg_match_all("/^Redirect[\s]+301.*$/m", $htaccess, $all_other_redirects_, PREG_OFFSET_CAPTURE);

    // normalize array 
    $all_other_redirects = [];
    $found_conflicts = [];
    foreach ($all_other_redirects_[0] as $key => $redirect) {

        $array = preg_split("/[\s]+/", preg_replace('/Redirect[\s]+301[\s]+/', '', $redirect[0]));
        
        $all_other_redirects[] = [
            'from' => trim($array[0]),
            'to' => trim($array[1])
        ];
        // if a conflicting redirect
        if (has_redirect(trim($array[0]),$generated_301_array)) {
            unset($all_other_redirects[$key]);
            $found_conflicts[] = $redirect[0];
            // comment out line in the document
            $htaccess = substr_replace($htaccess, '# ', $redirect[1], 0);
        }
    }

    // append to end of file if no generated redirects was already in the file
    if ($contained_already_generated_redirects) {
        // replace already generated redirects
        $htaccess = substr_replace($htaccess, $generated_redirects, $generated_start_pos, 0);
    } else {
        // append to end
        $htaccess = $htaccess . $generated_redirects;
    }

    //write the htaccess-file
    file_put_contents(plugin_dir_path( __FILE__ ) . '../../../.htaccess', trim($htaccess));

    // testing the htaccess-file 
    $http = curl_init(plugin_dir_url( __DIR__ ). '/htaccess_test-redirected.html');
    $result = curl_exec($http);
    $curl_code = curl_getinfo($http, CURLINFO_HTTP_CODE);

    if ($curl_code == 301) {
        $htaccess_test_result = '<p style="font-size:26px;color:#1fb800;">The .htaccess is working, the import was successful but please test all URLs to make sure</p>';
    } else {
        file_put_contents(plugin_dir_path( __FILE__ ) . '../../../.htaccess', $original_htaccess);
        $htaccess_test_result = '<p style="font-size:22px;color:#a80a0a;">Warning, htaccess was detected to be broken, the previous version was put back in place</p>';
        echo $htaccess_test_result;
        wp_die();
    }

    echo $htaccess_test_result;
    echo "<h3>Redirects already in the file</h3>" . PHP_EOL;

			echo "<ul>";
			if (!empty($all_other_redirects_)) {
				foreach ($all_other_redirects_[0] as $redir) {
					echo "<li>" . $redir[0] . "</li>";
				}
			} else {
				echo "<li>None</li>";
			}
			echo "</ul>";

		// Find commented out "Redirects 301"
		preg_match_all("/^#[\s]*Redirect[\s]+301.*$/m", $htaccess, $commented_redirects_, PREG_OFFSET_CAPTURE);

		echo "<h3>Redirects commented out because of it being present in the sheet</h3>" . PHP_EOL;
		
			echo "<ul>";
			if (!empty($commented_redirects_)) {
				foreach ($commented_redirects_[0] as $redir) {
					echo "<li>" . $redir[0] . "</li>";
				}
			} else {
				echo "<li>None</li>";
			}
            echo "</ul>";
            
        echo "<h3>Redirects added from the sheet</h3>" . PHP_EOL;
		
			echo "<ul>";
			if (!empty($generated_301_array)) {
				foreach ($generated_301_array as $redir) {
					echo "<li>Redirect 301 " . $redir['from'] . " " . $redir['to'] .  "</li>";
				}
			} else {
				echo "<li>None</li>";
			}
			echo "</ul>";

	wp_die();
}

function has_redirect($from,$hay) {
    foreach ($hay as $redirect) {
        if ($from == $redirect['from']) {
            return true;
        }
    }
    return false;
}



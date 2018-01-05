<?php
class Scaleway {
	public $settings = array(
		'orderform_vars' => array(
			'domain', // User configurable eg: server1.your-domain.com
			'region', // par1, ams1
			'server_model', // C1, VC1[S|M|L], C2[S|M|L]
			'image', // Image UUID to install
			'enable_ipv6', // Yes | No
			
		) ,
		'description' => 'Automate the creation of servers via Scaleway.',
	);
	public $ch; // curl handle
	public $error;
	private $ch_log; // curl verbose log
	private $curl_header;
	function curl($region, $action, $expected_response = 200, $postfields = array() , $method = 'GET') {
		$this->error = null;
		if ($this->ch === null) {
			$this->ch = curl_init();
			$this->ch_log = fopen('php://temp', 'w+');
			curl_setopt_array($this->ch, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_ENCODING => "",
				CURLOPT_USERAGENT => "Curl/Billic",
				CURLOPT_AUTOREFERER => true,
				CURLOPT_CONNECTTIMEOUT => 30,
				CURLOPT_TIMEOUT => 60,
				CURLOPT_MAXREDIRS => 5,
				CURLOPT_SSL_VERIFYHOST => false,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_HTTPHEADER => array(
					'Content-Type: application/json',
					'X-Auth-Token: ' . get_config('Scaleway_APIToken') ,
				) ,
				CURLOPT_VERBOSE => true,
				CURLOPT_STDERR => $this->ch_log,
			));
		}
		if (empty($postfields)) {
			curl_setopt_array($this->ch, array(
				CURLOPT_POST => false,
				CURLOPT_CUSTOMREQUEST => $method,
				CURLOPT_POSTFIELDS => $postfields,
			));
		} else {
			if ($method == 'GET') $method = 'POST';
			curl_setopt_array($this->ch, array(
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $postfields,
				CURLOPT_CUSTOMREQUEST => $method,
			));
		}
		curl_setopt($this->ch, CURLOPT_URL, 'https://cp-' . $region . '.scaleway.com/' . $action);
		$data = curl_exec($this->ch);
		/*rewind($this->ch_log);
		$verboseLog = stream_get_contents($this->ch_log);
		echo "Verbose information:\n<pre>", htmlspecialchars($verboseLog), "</pre>\n";*/
		if (curl_errno($this->ch) > 0) {
			$this->error = 'Curl error: ' . curl_error($this->ch);
			return false;
		}
		$actual_response = curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE);
		if ($actual_response != $expected_response) {
			$this->error = 'API returned HTTP status ' . $actual_response . ' but expected ' . $expected_response . ' - ' . strip_tags($data);
			return false;
		}
		$header_size = curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
		$this->curl_header = trim(substr($data, 0, $header_size));
		$body = trim(substr($data, $header_size));
		return $body;
	}
	function user_cp($a) {
		global $billic, $db;
		$info = $this->curl($a['vars']['region'], 'servers/' . $a['service']['username'], 200);
		if (!$info) {
			die('Error retrieving server status');
		}
		$info = json_decode($info, true);
		//echo '<pre>'.htmlentities(var_export($info, true)).'</pre>';
		echo '<dl class="dl-horizontal">';
		$status = $info['server']['state'];
		$status2 = $info['server']['state_detail'];
		echo '	<dt>Status</dt><dd>' . ucwords($status) . ' (' . ucwords($status2) . ')</dd>';
		$os = $info['server']['image']['name'];
		echo '	<dt>Operating System</dt><dd>' . $os . '</dd>';
		$kernel = $info['server']['bootscript']['title'];
		echo '	<dt>Kernel</dt><dd>' . $kernel . '</dd>';
		$arch = $info['server']['arch'];
		echo '	<dt>Architecture</dt><dd>' . $arch . '</dd>';
		$public_ip = $info['server']['public_ip']['address'];
		if (!empty($public_ip)) {
			echo '	<dt>Public IP</dt><dd>' . $public_ip . '</dd>';
		}
		$private_ip = $info['server']['private_ip'];
		echo '	<dt>Private IP</dt><dd>' . $private_ip . '</dd>';
		$ipv6 = $info['server']['ipv6'];
		if (!empty($ipv6['address'])) {
			echo '	<dt>IPv6 Address</dt><dd>' . $ipv6['address'] . '/' . $ipv6['netmask'] . '</dd>';
			echo '	<dt>IPv6 Gateway</dt><dd>' . $ipv6['gateway'] . '</dd>';
		}
		echo '</dl>';
		foreach ($info['server']['volumes'] as $i => $disk) {
			/*'size' => 50000000000,
			     'name' => 'x86_64-debian-jessie-2016-04-06_15:26',
			     'modification_date' => '2016-11-01T18:10:30.868187+00:00',
			     'organization' => 'd16a33de-ff1d-4018-a7d0-d9c2d66d9cd4',
			     'export_uri' => NULL,
			     'creation_date' => '2016-11-01T18:10:30.868187+00:00',
			     'id' => '4a0da4eb-66bd-4992-9e73-f043a3835871',
			     'volume_type' => 'l_ssd',
			     'server' => 
			     array (
			       'id' => '8d020ab9-01f8-4d81-939e-2f5d47ceec86',
			       'name' => 'Service #29341',
			     ),*/
			echo $disk['name'] . ' [' . $disk['size'] . ']<br>';
		}
		/*$actions = $this->curl($a['vars']['region'], 'servers/'.$a['service']['username'].'/action', 200);
		if (!$actions) {
			die('Error retrieving server actions');
		}
		$actions = json_decode($actions, true);
		echo '<pre>'.htmlentities(var_export($actions, true)).'</pre>';
		0 => 'poweron',
		1 => 'poweroff',
		2 => 'reboot',
		3 => 'terminate',
		*/
		if (isset($_POST['poweron'])) {
			if (!$this->server_action($a['vars']['region'], $a['service']['username'], 'poweron', 202)) {
				die('<div class="alert alert-danger" role="alert">Power On Failed: ' . $this->error . '</div>');
			}
			echo '<div class="alert alert-success" role="alert">Server successfully started.</div>';
			exit;
		} else if (isset($_POST['reboot'])) {
			if (!$this->server_action($a['vars']['region'], $a['service']['username'], 'reboot', 202)) {
				die('<div class="alert alert-danger" role="alert">Reboot Failed: ' . $this->error . '</div>');
			}
			echo '<div class="alert alert-success" role="alert">Server successfully rebooted.</div>';
			exit;
		}
		echo '<form method="POST">';
		if ($status == 'stopped') {
			echo '<button type="submit" name="poweron" class="btn btn-success">Start</button>';
		} else {
			echo '<button type="submit" name="reboot" class="btn btn-success">Reboot</button>';
		}
		echo '</form>';
	}
	function server_action($region, $uuid, $action, $expected_response = 200) {
		if (!$this->curl($region, 'servers/' . $uuid . '/action', $expected_response, json_encode(array(
			'action' => $action
		)))) {
			return false;
		}
		return true;
	}
	function create($a) {
		global $billic, $db;
		if (!empty($a['service']['username'])) {
			return 'This service already has a username. Make sure the old server is really deleted before creating a new one!';
		}
		$post = array(
			'name' => 'Service #' . $a['service']['id'],
			'organization' => get_config('Scaleway_OrgID') ,
			'image' => $a['vars']['image'],
			'commercial_type' => $a['vars']['server_model'],
			'enable_ipv6' => ($a['vars']['enable_ipv6'] == 'Yes' ? true : false) ,
		);
		if (!empty(get_config('Scaleway_Tags'))) {
			$tags = get_config('Scaleway_Tags');
			$tags = explode(' ', $tags);
			if (count($tags) == 1) {
				$tags = explode(',', $tags[0]);
			}
			$post['tags'] = $tags;
		}
		if (get_config('Scaleway_RemovePublicIP') == 1) {
			$post['dynamic_ip_required'] = false;
		}
		$api = $this->curl($a['vars']['region'], 'servers', 201, json_encode($post));
		if (!$api) {
			return $this->error;
		}
		// Parse the server ID from the location header
		preg_match('/[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}/i', $this->curl_header, $match);
		$server_uuid = basename($match[0]);
		if (empty($server_uuid)) {
			// TODO: Alert incase a server is actually created
			return 'Failed to parse server UUID from create command';
		}
		$db->q('UPDATE `services` SET `username` = ? WHERE `id` = ?', $server_uuid, $a['service']['id']);
		$this->server_action($a['vars']['region'], $server_uuid, 'poweron', 202);
		return true;
	}
	function suspend($a) {
		global $billic, $db;
		return 'TODO';
	}
	function unsuspend($a) {
		global $billic, $db;
		return 'TODO';
	}
	function terminate($a) {
		global $billic, $db;
		$api = $this->server_action($a['vars']['region'], $a['service']['username'], 'terminate', 202);
		if (!$api) {
			return 'Failed to terminate server: ' . $this->error;
		}
		return true;
	}
	function ordercheck($array) {
		global $billic, $db;
		$vars = $array['vars'];
		if (!(preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i", $vars['domain']) // valid chars check
		 && preg_match("/^.{1,253}$/", $vars['domain']) // overall length check
		 && preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $vars['domain']) // length of each label
		)) {
			$billic->error('Invalid Domain. It should be something like server1.your-domain.com', 'domain');
		}
		return $vars['domain']; // return the domain for the service to be called
		
	}
	function settings($array) {
		global $billic, $db;
		if (empty($_POST['update'])) {
			echo '<form method="POST"><input type="hidden" name="billic_ajax_module" value="Scaleway"><table class="table table-striped">';
			echo '<tr><th>Setting</th><th>Value</th></tr>';
			echo '<tr><td>Scaleway API Token</td><td><input type="text" class="form-control" name="Scaleway_APIToken" value="' . safe(get_config('Scaleway_APIToken')) . '" style="width: 100%"></td></tr>';
			echo '<tr><td>Scaleway Organization ID</td><td><input type="text" class="form-control" name="Scaleway_OrgID" value="' . safe(get_config('Scaleway_OrgID')) . '" style="width: 100%"><a href="https://www.scaleway.com/docs/retrieve-my-organization-id-throught-the-api/" target="_new">https://www.scaleway.com/docs/retrieve-my-organization-id-throught-the-api/</a></td></tr>';
			echo '<tr><td>Tags to assign to new servers</td><td><input type="text" class="form-control" name="Scaleway_Tags" value="' . safe(get_config('Scaleway_Tags')) . '" style="width: 100%"></td></tr>';
			echo '<tr><td>Remove Public IP</td><td><input type="checkbox" name="Scaleway_RemovePublicIP" value="1"' . (get_config('Scaleway_RemovePublicIP') == 1 ? ' checked' : '') . '></td></tr>';
			echo '<tr><td colspan="2" align="center"><input type="submit" class="btn btn-default" name="update" value="Update &raquo;"></td></tr>';
			echo '</table></form>';
		} else {
			if (empty($billic->errors)) {
				set_config('Scaleway_OrgID', $_POST['Scaleway_OrgID']);
				set_config('Scaleway_APIToken', $_POST['Scaleway_APIToken']);
				set_config('Scaleway_Tags', $_POST['Scaleway_Tags']);
				set_config('Scaleway_RemovePublicIP', $_POST['Scaleway_RemovePublicIP']);
				$billic->status = 'updated';
			}
		}
	}
}

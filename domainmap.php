<?php

/**
 * DomainMap
 *
 * Dynamic host selection depending on the domainpart of username
 *
 * @author Takayuki Hama
 *
 * Copyright (C) 2015 Takayuki Hama
 */

class domainmap extends rcube_plugin
{
	private $rc;
	private $map;

	/**
	 * Plugin initialization
	 */
	public function init()
	{
		$this->rc = rcube::get_instance();

		// add hook
		$this->add_hook('authenticate', array($this, 'authenticate'));
		$this->add_hook('user_create', array($this, 'user_create'));
		$this->add_hook('storage_connect', array($this, 'storage_connect'));
		$this->add_hook('smtp_connect', array($this, 'smtp_connect'));

		// add plugins
		if ($this->rc->task != 'login') {
			$plugins = $this->_add_plugins();
		}
	}

	/**
	 * Load additional plugins
	 */
	private function _add_plugins()
	{
		$plugins = $_SESSION['domainmap']['plugins'];
		$plugins_global = $this->rc->config->get('plugins');
		$plugins = array_diff($plugins, $plugins_global);

		foreach (array_unique($plugins) as $plugin) {
			if (!empty($plugin)) {
				$this->rc->plugins->load_plugin($plugin);
			}
		}

		$plugins_loaded = $this->rc->plugins->loaded_plugins();

		if (in_array('managesieve', $plugins_loaded)) {
			$this->add_hook('managesieve_connect',
				array($this, 'managesieve_connect'));
		}

		if (in_array('sieverules', $plugins_loaded)) {
			$this->add_hook('sieverules_connect',
				array($this, 'sieverules_connect'));
		}

		return;
	}

	/**
	 * Callback for 'authenticate' hook
	 */
	public function authenticate($args)
	{
		$this->add_texts('localization/');
		$this->load_config();
		$this->map = $this->rc->config->get('domainmap', null);

		list($local, $domain) = explode('@', $args['user'], 2);

		// check host
		if (empty($domain)) {
			$args['abort'] = true;
			$args['error'] = $this->gettext('nodomain');
		}
		elseif (!array_key_exists($domain, $this->map)) {
			$args['abort'] = true;
			$args['error'] = $this->gettext('nohost');
		}
		elseif (empty($this->map[$domain]['host'])) {
			$args['abort'] = true;
			$args['error'] = $this->gettext('nohost');
		}
		else {
			$args['host'] = 'localhost';
		}

		// set session params
		$this->_set_session($local, $domain);

		return $args;
	}

	/**
	 * set session params
	 */
	private function _set_session($local, $domain)
	{
		$_SESSION['domainmap']['local'] = $local;
		$_SESSION['domainmap']['domain'] = $domain;

		// username for login to imap
		$_SESSION['domainmap']['user'] = "$local@$domain";

		if ($this->map[$domain]['username_style'] == 'local') {
			$_SESSION['domainmap']['user'] = $local;
		}

		// connection settings
		$a_host = parse_url($this->map[$domain]['host']);

		$_SESSION['domainmap']['host'] = $a_host['host'];
		$_SESSION['domainmap']['secure'] = null;
		$_SESSION['domainmap']['port'] = 143;

		// ssl
		if (in_array($a_host['scheme'], array('ssl', 'imaps'))) {
			$_SESSION['domainmap']['secure'] = $a_host['scheme'];
			$_SESSION['domainmap']['port'] = 993;
		}
		elseif ($a_host['scheme'] == 'tls') {
			$_SESSION['domainmap']['secure'] = 'tls';
		}

		// port
		if (is_int($a_host['port'])) {
			$_SESSION['domainmap']['port'] = $a_host['port'];
		}

		// additional plugins
		$_SESSION['domainmap']['plugins'] = array();

		if (is_array($this->map[$domain]['add_plugins']) &&
			count($this->map[$domain]['add_plugins']) > 0)
		{
			$_SESSION['domainmap']['plugins'] =
				$this->map[$domain]['add_plugins'];
		}

		// smtp server
		$smtp = parse_url($this->map[$domain]['smtp_host']);

		if (!empty($smtp['host'])) {
			$_SESSION['domainmap']['smtp_host'] =
				$this->map[$domain]['smtp_host'];

			if (strlen($smtp['user']) && strlen($smtp['pass'])) {
				$_SESSION['domainmap']['smtp_user'] = $smtp['user'];
				$_SESSION['domainmap']['smtp_pass'] = $smtp['pass'];
			}
		}

		// sieve host
		$sieve = parse_url($this->map[$domain]['sieve_host']);

		if (!empty($sieve['host'])) {
			$_SESSION['domainmap']['sieve_host'] = $sieve['host'];
			$_SESSION['domainmap']['sieve_port'] = 4190;
			$_SESSION['domainmap']['sieve_usetls'] = false;

			// port
			if (is_int($sieve['port'])) {
				$_SESSION['domainmap']['sieve_port'] = $sieve['port'];
			}

			// usetls
			if ($sieve['scheme'] == 'tls') {
				$_SESSION['domainmap']['sieve_usetls'] = true;
			}
		}
	}

	/**
	 * Callback for 'user_create' hook
	 */
	public function user_create($args)
	{
		$args['user_email'] =
			$_SESSION['domainmap']['local']
			. '@'
			. $_SESSION['domainmap']['domain'];

		return $args;
	}

	/**
	 * Callback for 'storage_connect' hook
	 */
	public function storage_connect($args)
	{
		$args['user'] = $_SESSION['domainmap']['user'];
		$args['host'] = $_SESSION['domainmap']['host'];
		$args['port'] = $_SESSION['domainmap']['port'];
		$args['ssl'] = $_SESSION['domainmap']['secure'];
		$args['ssl_mode'] = $_SESSION['domainmap']['secure'];

		return $args;
	}

	/**
	 * Callback for 'smtp_connect' hook
	 */
	public function smtp_connect($args)
	{
		if (array_key_exists('smtp_host', $_SESSION['domainmap'])) {
			$args['smtp_server'] = $_SESSION['domainmap']['smtp_host'];
			$args['smtp_user'] = $_SESSION['domainmap']['smtp_user'];
			$args['smtp_paas'] = $_SESSION['domainmap']['smtp_pass'];
		}

		return $args;
	}

	/**
	 * Callback for 'managesieve_connect' hook
	 */
	public function managesieve_connect($args)
	{
		// set username
		$args['user'] = $_SESSION['domainmap']['user'];

		// set host
		if (array_key_exists('sieve_host', $_SESSION['domainmap'])) {
			$args['host'] = $_SESSION['domainmap']['sieve_host'];
			$args['port'] = $_SESSION['domainmap']['sieve_port'];
			$args['usetls'] = $_SESSION['domainmap']['sieve_usetls'];
		}
		else {
			$args['host'] = $_SESSION['domainmap']['host'];
		}

		return $args;
	}

	/**
	 * Callback for 'sieverules_connect' hook
	 */
	public function sieverules_connect($args)
	{
		// set username
		$args['username'] = $_SESSION['domainmap']['user'];

		// set host
		if (array_key_exists('sieve_host', $_SESSION['domainmap'])) {
			$args['host'] = $_SESSION['domainmap']['sieve_host'];
			$args['port'] = $_SESSION['domainmap']['sieve_port'];
			$args['usetls'] = $_SESSION['domainmap']['sieve_usetls'];
		}
		else {
			$args['host'] = $_SESSION['domainmap']['host'];
		}

		return $args;
	}
}
?>

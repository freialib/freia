<?php namespace freia\autoloader;

use \RecursiveDirectoryIterator;
use \RecursiveIteratorIterator;

/**
 * The freia cascading file system loader.
 *
 * @copyright (c) 2014, freia Team
 * @license BSD-2 <http://freialib.github.io/license.txt>
 * @package freia Library
 */
class SymbolLoader {

	/**
	 * @var string
	 */
	protected $systempath = null;

	/**
	 * @var array
	 */
	protected $env = null;

	/**
	 * @return static
	 */
	static function instance($systempath, $environment) {
		$i = new static;
		$i->systempath = $systempath;
		$i->setup($environment);
		return $i;
	}

	/**
	 * @param string symbol (class, interface, traits, etc)
	 * @param boolean autoload while checking?
	 * @return boolean symbol exists
	 */
	function exists($symbol, $autoload = false) {
		return class_exists($symbol, $autoload)
			|| interface_exists($symbol, $autoload)
			|| trait_exists($symbol, $autoload);
	}

	/**
	 * @return boolean
	 */
	function load($symbol_name) {

		// TODO (freia): remove debug code
		// TODO (freia): add missing functionality
		// TODO (freia): add tests

		// normalize
		$symbol = static::unn($symbol_name);

		// get components
		if (($ns_pos = strripos($symbol, '.')) !== false) {
			$ns = substr($symbol, 0, $ns_pos + 1);
			$name = substr($symbol, $ns_pos + 1);
			$filename = str_replace('_', '/', $name);
			$dirbreak = strlen($name) - strcspn(strrev($name), 'ABCDEFGHJIJKLMNOPQRSTUVWXYZ') - 1;

			if ($dirbreak > 0 && $dirbreak != strlen($name) - 1) {
				$filename = substr($name, $dirbreak).'/'.substr($name, 0, $dirbreak);
			}
			else { // dirbreak == 0
				$filename = $name;
			}

			foreach ($this->env as $module_ns => $conf) {
				if (strripos($module_ns, $ns) !== false) {
					$symbolfile = "{$conf['path']}/src/$filename.php";
					if ($this->file_exists($symbolfile)) {
						$modulesymbol = static::pnn("$module_ns$name");
						if ( ! static::exists($modulesymbol)) {
							$this->requirefile($symbolfile);
						}
						// shorthand namespace?
						if ($module_ns != $ns) {
							class_alias($modulesymbol, static::pnn($symbol));
						}

						return true;
					}
				}
			}
		}
		else { // symbol belongs to global namespace
			return false;
		}

		return false;
	}

	/**
	 * @return boolean success?
	 */
	function register($as_primary_autoloader = true) {
		return spl_autoload_register([$this, 'load'], true, $as_primary_autoloader);
	}

	/**
	 * @return boolean success?
	 */
	function unregister() {
		return spl_autoload_register([$this, 'load']);
	}

	/**
	 * @return array
	 */
	function paths() {
		$paths = [];
		$env_paths = array_map(function ($entry) {
			return $entry['path'];
		}, $this->env);

		foreach ($env_paths as $namespace => $path) {
			$paths[rtrim($namespace, '.')] = $path;
		}

		return $paths;
	}

// ---- Private ---------------------------------------------------------------

	/**
	 * Universal Namespace Name
	 * Accepts both classes, classes with namespace, etc.
	 * Also fixes nonsense from PHP, such as double slashes in namespace.
	 *
	 * @return string normalized namespace
	 */
	protected static function unn($symbol) {
		return trim(preg_replace('#[^a-zA-Z0-9_]#', '.', $symbol), '.');
	}

	/**
	 * PHP Namespace Name
	 * Accepts both classes, classes with namespace, etc.
	 *
	 * @return string from unn back to php
	 */
	protected static function pnn($symbol) {
		return str_replace('.', '\\', $symbol);
	}

	/**
	 * Setup the CFS structure based on the environment.
	 */
	protected function setup($env) {

		if ( ! is_array($env)) {
			throw new Panic('The autoloader can not handle non-array environments.');
		}

		$cfsconfs = [];
		if (isset($env['load'])) {
			foreach ($env['load'] as $path) {
				$loadpath = "{$this->systempath}/$path";
				$files = $this->find_file('composer.json', $loadpath);
				// find the cfs configs
				foreach ($files as $file) {
					$composerjson = json_decode(file_get_contents($file->getRealPath()), true);
					if ($this->composer_has_cfsinfo($composerjson)) {
						$confname = static::unn($composerjson['name']).'.';
						$cfsconfs[$confname] = [];
						$cfsconfs[$confname]['path'] = $file->getPath();
					}
				}
			}
		}

		$this->env = $cfsconfs;
	}

	/**
	 * @return boolean
	 */
	protected function composer_has_cfsinfo($json) {
		return isset($json['type'], $json['name'])
			&& $json['type'] == 'freia-module';
	}

	/**
	 * @return \SplFileInfo[]
	 */
	protected function find_file($searchedfile, $searchpath) {
		$dir = new RecursiveDirectoryIterator($searchpath);
		$i = new RecursiveIteratorIterator($dir);
		$files = [];
		foreach($i as $file) {
			if ($file->getFilename() == $searchedfile) {
				$files[] = $file;
			}
		}

		return $files;
	}

	/**
	 * Testing hook.
	 */
	protected function requirefile($symbolfile) {
		require $symbolfile;
	}

	/**
	 * Testing hook.
	 */
	protected function file_exists($file) {
		return file_exists($file);
	}

} # class

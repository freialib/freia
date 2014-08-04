<?php namespace freia\autoloader;

/**
 * @copyright (c) 2014, freia Team
 * @license BSD-2 <http://freialib.github.io/license.txt>
 * @package freia Library
 */
class Filemap implements \hlin\archetype\Filemap {

	use \hlin\FilemapTrait;

	/**
	 * @return \freia\SymbolLoader
	 */
	protected $symbol_loader = null;

	/**
	 * @return static
	 */
	static function instance(SymbolLoader $symbol_loader) {
		$i = new static;
		$i->symbol_loader = $symbol_loader;
		return $i;
	}

	/**
	 * @return mixed contents or null
	 */
	function file(\hlin\archetype\Filesystem $fs, $path, $metatype = null) {

		$paths = $this->symbol_loader->paths();

		if ($metatype == null) { // null = get top file
			foreach ($paths as $envpath) {
				if ($fs->file_exists("$envpath/$path")) {
					return $fs->file_get_contents("$envpath/$path");
				}
			}

			// file not found
			return null;
		}
		else if ($metatype == 'cfs-config') {
			$found = false;
			$config = [];

			foreach ($paths as $envpath) {
				if ($fs->file_exists("$envpath//$path")) {
					$found = true;
					$this->merge($config, $this->_include("$envpath/$path"));
				}
			}

			if ($found) {
				return $config;
			}
			else { // configuration not found
				return null;
			}
		}
		else if ($metatype == 'cfs-files') {
			$found = false;
			$files = [];

			foreach ($paths as $envpath) {
				if ($fs->file_exists("$envpath//$path")) {
					$found = true;
					$files[] = "$envpath/$path";
				}
			}

			if ($found) {
				return $files;
			}
			else { // configuration not found
				return null;
			}
		}
		else if ($this->understands($metatype)) {
			return $this->processmetatype($fs, $metatype, $path);
		}
		else { // no such metatype
			throw new Panic('The metatype $type is not supported.');
		}
	}

// ---- Private ---------------------------------------------------------------

	/**
	 * Merge configuration arrays.
	 *
	 * This function does not return a new array, the first array is simply
	 * processed directly; for effeciency.
	 *
	 * Behaviour: numeric key arrays are appended to one another, any other key
	 * and the values will overwrite.
	 *
	 * @param array base
	 * @param array overwrite
	 */
	protected function merge(array &$base, array $overwrite) {
		foreach ($overwrite as $key => $value) {
			if (is_int($key)) {
				// add only if it doesn't exist
				if ( ! in_array($overwrite[$key], $base)) {
					$base[] = $overwrite[$key];
				}
			}
			else if (is_array($value)) {
				if (isset($base[$key]) && is_array($base[$key])) {
					$this->merge($base[$key], $value);
				}
				else { # does not exist or it's a non-array
					$base[$key] = $value;
				}
			}
			else { # not an array and not numeric key
				$base[$key] = $value;
			}
		}
	}

	/**
	 * ...
	 */
	protected function _include($filepath) {
		return include $filepath;
	}

} # class

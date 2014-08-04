<?php namespace freia\autoloader;

/**
 * @copyright (c) 2014, freia Team
 * @license BSD-2 <http://freialib.github.io/license.txt>
 * @package freia Library
 */
class Configs implements \hlin\archetype\Configs {

	/**
	 * @var array
	 */
	protected $cache = [];

	/**
	 * @var array
	 */
	protected $filemaps = [];

	/**
	 * Default filemaps will be used if none are specified.
	 *
	 * @return static
	 */
	static function instance(\hlin\archetype\Filesystem $fs, array $filemaps) {
		$i = new static;
		$i->fs = $fs;
		$i->filemaps = $filemaps;
		return $i;
	}

	/**
	 * @return array
	 */
	function read($key) {
		if (isset($this->cache[$key])) {
			return $this->cache[$key];
		}
		else { // cache is empty
			foreach ($this->filemaps as $filemap) {
				$contents = $filemap->file($this->fs, "confs/$key.php", 'cfs-config');
				if ($contents !== null) {
					return $this->cache[$key] = $contents;
				}
			}

			// failed to find file
			throw new Panic("No configuration files for $key");
		}
	}

	/**
	 * ...
	 */
	static function register_default($name, \hlin\archetype\FileMap $filemap) {
		static::$default_filemaps[$name] = $filemap;
	}

	/**
	 * ...
	 */
	static function unregister_default($name) {
		unset(static::$default_filemaps[$name]);
	}

} # class

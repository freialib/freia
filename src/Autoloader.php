<?php namespace freia\autoloader;

/**
 * @copyright (c) 2014, freia Team
 * @license BSD-2 <http://freialib.github.io/license.txt>
 * @package freia Library
 */
class Autoloader extends SymbolLoader implements \hlin\archetype\Autoloader {

	/**
	 * @var SymbolLoader
	 */
	protected $autoloader = null;

	/**
	 * @return static
	 */
	static function wrap(SymbolLoader $autoloader) {
		$i = new static;
		$i->autoloader = $autoloader;
		return $i;
	}

	/**
	 * @param string symbol (class, interface, traits, etc)
	 * @param boolean autoload while checking?
	 * @return boolean symbol exists
	 */
	function exists($symbol, $autoload = false) {
		return $this->autoloader->exists($symbol, $autoload);
	}

	/**
	 * @return boolean
	 */
	function load($symbol_name) {
		return $this->autoloader->load($symbol_name);
	}

	/**
	 * @return array
	 */
	function paths() {
		return $this->autoloader->paths();
	}

} # class

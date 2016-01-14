<?php namespace freia\autoloader;

/**
 * The freia cascading file system loader.
 *
 * @copyright (c) 2014, freia Team
 * @license BSD-2 <http://freialib.github.io/license.txt>
 * @package freia Library
 */
class SymbolLoader {

	/**
	 * @var mixed symbol environment
	 */
	protected $env = null;

	/**
	 * You may set debugMode to true to use debug modules, otherwise if modules
	 * with a debug type in the autoload rules section are found they will be
	 * ignored.
	 *
	 * @return static
	 */
	static function instance($syspath, $conf) {
		$i = new static;
		$i->env = $i->environementInstance($syspath, $conf);

		return $i;
	}

	/**
	 * @return mixed symbol environment object
	 */
	static function environementInstance($syspath, $conf) {
		return \freia\autoloader\SymbolEnvironment::instance($syspath, $conf);
	}

// Main Functions
// ==============

	/**
	 * Maintained for backwards comaptibility.
	 *
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
	 * Load given symbol
	 *
	 * @return boolean
	 */
	function load($symbol) {

		if ($this->env->knownUnknown($symbol)) {
			return false;
		}

		if ($this->env->autoresolve($symbol)) {
			return true;
		}

		$ns_pos = \strripos($symbol, '\\');

		if ($ns_pos !== false && $ns_pos != 0) {
			$ns = \substr($symbol, 0, $ns_pos + 1);

			// Validate Main Segment
			// =====================

			$mainsegment = null;
			$firstslash = \strpos($ns, '\\');
			if ($firstslash !== false || $firstslash == 0) {
				$mainsegment = \substr($ns, 0, $firstslash);
			}
			else { // no \ in namespace
				// the namespace is the main segment itself
				$mainsegment = $ns;
			}

			if ( ! $this->env->knownSegment($mainsegment)) {
				$this->env->unknownSymbol($symbol);
				$this->env->save();
				return false;
			}


			// Continue Loading Process
			// ========================

			$name = \substr($symbol, $ns_pos + 1);
			$filename = \str_replace('_', '/', $name);
			$dirbreak = \strlen($name) - \strcspn(strrev($name), 'ABCDEFGHJIJKLMNOPQRSTUVWXYZ') - 1;

			if ($dirbreak > 0 && $dirbreak != \strlen($name) - 1) {
				$filename = \substr($name, $dirbreak).'/'.\substr($name, 0, $dirbreak);
			}
			else { // dirbreak == 0
				$filename = $name;
			}

			$nextPtr = \strrpos($ns, '\\next\\');

			$resolution = null;
			if ($nextPtr === false) {
				$resolution = $this->env->findFirstFileMatching($ns, $name, $filename);
			}
			else { // "\next\" is present in string
				$resolution = $this->env->findFirstFileMatchingAfterNext($ns, $name, $filename);
			}

			if ($resolution != null) {
				list($targetfile, $targetns, $target) = $resolution;

				if ( ! $this->env->isLoadedSymbol($target)) {
					$this->env->loadSymbol($target, $targetfile);
				}

				if ($targetns != $ns) {
					$this->env->aliasSymbol($target, $symbol);
				}

				$this->env->save();

				return true;
			}

		}
		# else: symbol belongs to global namespace

		$this->env->unknownSymbol($symbol);
		$this->env->save();

		return false; // failed to resolve symbol; pass to next autoloader
	}

	/**
	 * @return array module paths
	 */
	function paths() {
		return $this->env->paths();
	}

	/**
	 * @codeCoverageIgnore
	 * @return boolean success?
	 */
	function register($as_primary_autoloader = true) {
		return spl_autoload_register([$this, 'load'], true, $as_primary_autoloader);
	}

	/**
	 * @codeCoverageIgnore
	 * @return boolean success?
	 */
	function unregister() {
		return spl_autoload_register([$this, 'load']);
	}

// ---- Test Hooks ------------------------------------------------------------

	/**
	 * Testing hook.
	 * @codeCoverageIgnore
	 */
	protected function error_log($message) {
		$unn = trim(preg_replace('#[^a-zA-Z0-9_]#', '.', \get_class()), '.');
		error_log("[$unn] $message");
	}

} # class

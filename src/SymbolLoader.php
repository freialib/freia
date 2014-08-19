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
	 * @var string
	 */
	protected $systempath = null;

	/**
	 * @var array
	 */
	protected $env = null;

	/**
	 * @var array
	 */
	protected $env_names = null;

	/**
	 * @var boolean is the current environment a cache?
	 */
	protected $cachedEnv = false;

	/**
	 * @var string
	 */
	protected $lastsymbol = null;

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

		// normalize
		$symbol = static::unn($symbol_name);
		$this->lastsymbol = null;

		// get components
		if (($ns_pos = strripos($symbol, '.')) !== false) {

			$ns = substr($symbol, 0, $ns_pos + 1);

			// Validate Main Segment
			// ---------------------

			$firstdot = strpos($ns, '.');
			if ($firstdot !== false) {
				$mainsegment = substr($ns, 0, $firstdot);
				if ( ! in_array($mainsegment, $this->env_names)) {
					if ($this->cachedEnv) {
						$this->refreshEnvironment();
						return $this->load($symbol_name);
					}
					else { // not cachedEnv
						// failed due to being unknown namespace
						return false;
					}
				}
			}
			else { // no . in namespace
				// the namespace is the main segment itself
				if ( ! in_array($ns, $this->env_names)) {
					if ($this->cachedEnv) {
						$this->refreshEnvironment();
						return $this->load($symbol_name);
					}
					else { // not cachedEnv
						// failed due to being unknown namespace
						return false;
					}
				}
			}

			// Continue Loading Process
			// ------------------------

			$name = substr($symbol, $ns_pos + 1);
			$filename = str_replace('_', '/', $name);
			$dirbreak = strlen($name) - strcspn(strrev($name), 'ABCDEFGHJIJKLMNOPQRSTUVWXYZ') - 1;

			if ($dirbreak > 0 && $dirbreak != strlen($name) - 1) {
				$filename = substr($name, $dirbreak).'/'.substr($name, 0, $dirbreak);
			}
			else { // dirbreak == 0
				$filename = $name;
			}

			$nextPtr = strrpos($ns, '.next.');
			if ($nextPtr === false) {

				// Regular namespace
				// -----------------

				foreach ($this->env as $module_ns => $conf) {
					$offset = strripos($module_ns, $ns);
					if (strripos($module_ns, $ns) !== false) {
						$symbolfile = "{$conf['path']}/src/$filename.php";
						if ($this->file_exists($symbolfile)) {
							$modulesymbol = static::pnn("$module_ns$name");
							if ( ! static::exists($modulesymbol)) {
								$this->requirefile($symbolfile);
							}

							$this->lastsymbol = $modulesymbol;

							// shorthand namespace?
							if ($module_ns != $ns) {
								$this->class_alias($modulesymbol, static::pnn($symbol));
							}

							return true;
						}
					}
				}
			}
			else { // ".next." is present in string

				// Handling for "next" keyword
				// ---------------------------

				$skipPoint = substr($ns, 0, $nextPtr + 1);
				$targetNs = substr($ns, $nextPtr + 6); // strlen('.next.') = 6

				$skipped = false;
				foreach ($this->env as $module_ns => $conf) {

					if ( ! $skipped) {
						if ($module_ns == $skipPoint) {
							$skipped = true;
						}

						continue;
					}

					if (strripos($module_ns, $targetNs) !== false) {
						$symbolfile = "{$conf['path']}/src/$filename.php";
						if ($this->file_exists($symbolfile)) {
							$modulesymbol = static::pnn("$module_ns$name");
							if ( ! static::exists($modulesymbol)) {
								$this->requirefile($symbolfile);
							}

							$this->lastsymbol = $modulesymbol;

							// shorthand namespace?
							if ($module_ns != $targetNs) {
								$this->class_alias($modulesymbol, static::pnn($symbol));
							}

							return true;
						}
					}

				}
			}
		}
		else { // symbol belongs to global namespace
			return false;
		}

		// are we in a potentially invalid cached system?
		if ($this->cachedEnv) {
			$this->refreshEnvironment();
			return $this->load($symbol_name);
		}

		return false;
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
	 * @return \SplFileInfo[]
	 */
	protected function find_file($searchedfile, $searchpath, $maxdepth = -1) {
		$dirIterator = new \RecursiveDirectoryIterator($searchpath);
		$i = new \RecursiveIteratorIterator($dirIterator);
		$i->setMaxDepth($maxdepth);
		$files = [];
		foreach ($i as $file) {
			if ($file->getFilename() == $searchedfile) {
				$files[] = $file;
			}
		}

		return $files;
	}

	/**
	 * Setup the CFS structure based on the environment.
	 */
	protected function setup($env) {

		if ( ! is_array($env)) {
			throw new Panic('The autoloader can not handle non-array environments.');
		}

		$this->rawEnvironment = $env;

		$cached_settings = $this->retrieveCachedSettings($env);
		if ($cached_settings !== null) {
			$this->env = $cached_settings;
			$this->rebuildValidationData();
			$this->cachedEnv = true;
			return;
		}

		$this->refreshEnvironment();
	}

	/**
	 * Generates validation cache based on current environment.
	 */
	protected function rebuildValidationData() {
		$this->env_names = [];
		foreach (array_keys($this->env) as $module_ns) {
			$dotpos = strpos($module_ns, '.');
			if ($dotpos !== false) {
				$ns = substr($module_ns, 0, $dotpos);
			}
			else { // this should not happen; but just in case
				$ns = $module_ns;
			}
			if ( ! in_array($ns, $this->env_names)) {
				$this->env_names[] = $ns;
			}
		}
	}

	/**
	 * Invalidates the current environment and recalculate.
	 */
	protected function refreshEnvironment() {

		// load in original environment configuration
		$env = $this->rawEnvironment;

		$cfsconfs = [];

		if (isset($env['load'])) {

			if (isset($env['depth'])) {
				$maxdepth = (int) $env['depth'];
			}
			else { // depth not set
				$maxdepth = $this->defaultDepth();
			}

			foreach ($env['load'] as $path) {
				$loadpath = "{$this->systempath}/$path";
				$files = $this->find_file('composer.json', $loadpath, $maxdepth);
				// find the cfs configs
				foreach ($files as $file) {
					$composerjson = json_decode($this->file_get_contents($file->getRealPath()), true);
					if ($this->composer_has_cfsinfo($composerjson)) {
						$confname = static::unn($composerjson['name']).'.';
						$cfsconfs[$confname] = [];
						$cfsconfs[$confname]['path'] = $file->getPath();
					}
				}
			}
		}

		$this->cachedEnv = false;
		$this->env = $cfsconfs;
		$this->rebuildValidationData();
		$this->saveStateToCache($env);
	}

	/**
	 * @return array|null environment or null
	 */
	protected function retrieveCachedSettings($env) {
		$prefix = $this->cachePrefix();
		if (isset($env['cache.dir'])) {
			$cachedir = rtrim($this->systempath.'/'.$env['cache.dir'], '/\\');
			if ($this->file_exists($cachedir)) {

				// Load depth cache
				// ----------------

				$cacheDepth = null;
				$cachefile = "$cachedir/$prefix.meta.cache";
				if ($this->file_exists($cachefile)) {
					$jsonstr = $this->file_get_contents($cachefile);
					if ($jsonstr !== false) {
						$meta = json_decode($jsonstr, true);
						if ( ! empty($meta)) {
							if (isset($meta['depth'])) {
								$cacheDepth = (int) $meta['depth'];
							}
						}
						else { // failed to parse cached settings
							$classKey = $this->unn(get_class());
							$this->error_log("[$classKey] Cached meta could not be parsed");
							// meta cache is REQUIRED for proper cache checks
							return null; # failed to retrieve cache
						}
					}
					else { // failed to read cache file
						$classKey = $this->unn(get_class());
						$this->error_log("[$classKey] Cached meta could not be read");
						// meta cache is REQUIRED for proper cache checks
						return null; # failed to retrieve cache
					}
				}

				// Verify Cache
				// ------------

				if ($cacheDepth !== null) {
					if (isset($env['depth'])) {
						$envDepth = (int) $env['depth'];
					}
					else { // not specified
						$envDepth = $this->defaultDepth();
					}

					if ($cacheDepth != $envDepth) {

						# We need to invalidate a cache if the depth is wrong
						# because if we don't when a cache invalidation happens
						# everything will just "seem" to break randomly due to
						# calculations under the new cache not giving anything
						# close to the old cache; this actually fails both
						# ways due to potential bad overwrites from previously
						# unmatched modules—so both lower and higher are risks

						return null; // controlled cache invalidation
					}
				}

				// Attempt to load environment
				// ---------------------------

				$cachefile = "$cachedir/$prefix.cfsconfs.cache";
				if ($this->file_exists($cachefile)) {
					$jsonstr = $this->file_get_contents($cachefile);
					if ($jsonstr !== false) {
						$cached_settings = json_decode($jsonstr, true);
						if ( ! empty($cached_settings)) {
							return $cached_settings;
						}
						else { // failed to parse cached settings
							$classKey = $this->unn(get_class());
							$this->error_log("[$classKey] Cached settings could not be parsed");
							return null;
						}
					}
					else { // failed to read cache file
						$classKey = $this->unn(get_class());
						$this->error_log("[$classKey] Cached settings could not be read");
						return null;
					}
				}
			}
			else { // mentioned dir does not exist
				$classKey = $this->unn(get_class());
				$this->error_log("[$classKey] Cache dir does not exist: $cachedir");
				return null;
			}
		}
		else { // no cache.dir provided
			$classKey = $this->unn(get_class());
			$this->error_log("[$classKey] It is highly recomended to provide cache.dir key in your environement config.");
			return null;
		}
	}

	/**
	 * Save the current state to cache
	 */
	protected function saveStateToCache($env) {
		$prefix = $this->cachePrefix();
		if (isset($env['cache.dir'])) {
			$cachedir = rtrim($this->systempath.'/'.$env['cache.dir'], '/\\');
			if ($this->file_exists($cachedir)) {

				// Save Meta
				// ---------

				$meta = [];
				if (isset($env['depth'])) {
					$meta['depth'] = $env['depth'];
				}
				else { // depth not set
					$meta['depth'] = $this->defaultDepth();
				}

				$cachefile = "$cachedir/$prefix.meta.cache";
				$jsonstr = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				// does the file exist?
				if ( ! $this->file_exists($cachefile)) {
					// ensure the file exists
					if ( ! ($this->file_put_contents($cachefile, $jsonstr) !== false)) {
						$classKey = $this->unn(get_class());
						$this->error_log("[$classKey] Unable to write: $cachefile");
						return;
					}
					// ensure the permissions are right
					if ( ! $this->chmod($cachefile, $this->filePermission())) {
						$classKey = $this->unn(get_class());
						$this->error_log("[$classKey] Unable to set permissions on cache file: $cachefile");
						return;
					}
				}
				else { // a file already exists
					if ( ! ($this->file_put_contents($cachefile, $jsonstr) !== false)) {
						$classKey = $this->unn(get_class());
						$this->error_log("[$classKey] Unable to writeover: $cachefile");
						return;
					}
				}

				// Save Environment
				// ----------------

				$cachefile = "$cachedir/$prefix.cfsconfs.cache";
				$jsonstr = json_encode($this->env, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				// does the file exist?
				if ( ! $this->file_exists($cachefile)) {
					// ensure the file exists
					if ( ! ($this->file_put_contents($cachefile, $jsonstr) !== false)) {
						$classKey = $this->unn(get_class());
						$this->error_log("[$classKey] Unable to write: $cachefile");
						return;
					}
					// ensure the permissions are right
					if ( ! $this->chmod($cachefile, $this->filePermission())) {
						$classKey = $this->unn(get_class());
						$this->error_log("[$classKey] Unable to set permissions on cache file: $cachefile");
						return;
					}
				}
				else { // a file already exists
					if ( ! ($this->file_put_contents($cachefile, $jsonstr) !== false)) {
						$classKey = $this->unn(get_class());
						$this->error_log("[$classKey] Unable to writeover: $cachefile");
						return;
					}
				}
			}
			else { // mentioned dir does not exist
				$classKey = $this->unn(get_class());
				$this->error_log("[$classKey] Cache dir does not exist: $cachedir");
				return;
			}
		}
	}

	/**
	 * If a depth is specified in the module configuration this value is
	 * overwritten by the environment value. It's important to note that
	 * cache does depend on this value! So a cache for depth 4 will become
	 * invalid imediatly if depth is changed to 5 or 3 or any other number.
	 *
	 * @return int the maximum depth a module search will run to
	 */
	protected function defaultDepth() {
		return 3;
	}

	/**
	 * @return string
	 */
	protected function cachePrefix() {
		return 'freia';
	}

	/**
	 * @return int
	 */
	protected function filePermission() {
		return 0664;
	}

	/**
	 * @return int
	 */
	protected function dirPermission() {
		return 0775;
	}

	/**
	 * @return boolean
	 */
	protected function composer_has_cfsinfo($json) {
		return isset($json['type'], $json['name'])
			&& $json['type'] == 'freia-module';
	}

// ---- Test Hooks ------------------------------------------------------------

	/**
	 * Testing hook.
	 * @codeCoverageIgnore
	 */
	protected function requirefile($symbolfile) {
		require $symbolfile;
	}

	/**
	 * Testing hook.
	 * @codeCoverageIgnore
	 */
	protected function file_exists($file) {
		return file_exists($file);
	}

	/**
	 * Testing hook.
	 * @codeCoverageIgnore
	 * @return mixed
	 */
	protected function file_get_contents($file) {
		return file_get_contents($file);
	}

	/**
	 * Testing hook.
	 * @codeCoverageIgnore
	 * @return int
	 */
	protected function file_put_contents($file, $data, $flags = 0) {
		return file_put_contents($file, $data, $flags);
	}

	/**
	 * Testing hook.
	 * @codeCoverageIgnore
	 */
	protected function error_log($message) {
		error_log($message);
	}

	/**
	 * Testing hook.
	 * @codeCoverageIgnore
	 * @return boolean
	 */
	protected function class_alias($class, $alias) {
		return class_alias($class, $alias);
	}

	/**
	 * Testing hook.
	 * @codeCoverageIgnore
	 * @return boolean
	 */
	protected function chmod($filepath, $mode) {
		return chmod($filepath, $mode);
	}

} # class

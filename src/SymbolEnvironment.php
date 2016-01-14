<?php namespace freia\autoloader;

/**
 * The freia cascading file system loader.
 *
 * @copyright (c) 2014, freia Team
 * @license BSD-2 <http://freialib.github.io/license.txt>
 * @package freia Library
 */
class SymbolEnvironment {

// State Information
// =================

	/**
	 * @var boolean accept debug modules?
	 */
	protected $debugMode = false;

	/**
	 * @var string
	 */
	protected $syspath = null;

	/**
	 * @var string persistent storage system
	 */
	protected $cacheConf = [ 'type' => 'noop' ];

	/**
	 * @var array
	 */
	protected $state = [

		// version represents symbol environment structure version
		// if the system changes the way symbol information is stored
		// the version is changed accordingly and any cache invalidated

		'version' => '1.0.0',

		'known-segments' => [
			// 'freia'
		],

		'modules' => [
			// 'freia/autoloader' => [
			//	'enabled' => true,
			// 	'idx' => -1,
			// 	'path' => __DIR__,
			//  'namespace' => '\\freia\\autoloader',
			// 	'rules' => [
			// 		'identity' => [],
			// 		'matches-before' => []
			// 	]
			// ]
		],

		'symbols' => [
			// '\\freia\\autoloader\\SymbolLoader' => __DIR__.'SymbolLoader.php',
			// '\\freia\\autoloader\\SymbolEnvironment' => __DIR__.'SymbolEnvironment.php'
		],

		'aliases' => [
			// '\\freia\\SymbolLoader' => '\\freia\\autoloader\\SymbolLoader'
		],

		'known-unknowns' => [
			# symbols we know won't resolve
		],

		'loaded' => [
			# symbols we've loaded
		]

	];

// Initialization
// ==============

	/**
	 * @return \freia\autoloader\SymbolEnvironment
	 */
	static function instance($syspath, $conf) {

		$i = new static;

		if (\array_key_exists('debugMode', $conf)) {
			$i->debugMode = $conf['debugMode'];
		}

		$i->syspath = $syspath;

		// backwards compatibility
		if (\array_key_exists('cache.dir', $conf)) {
			$i->cacheConf['type'] = 'file';
			$i->cacheConf['path'] = $conf['cache.dir'];
		}

		if (\array_key_exists('cache', $conf)) {
			$i->cacheConf = $conf['cache'];
		}

		// Validate cache strategy
		// =======================

		if ( ! \in_array($i->cacheConf['type'], ['noop', 'file', 'memcached'])) {
			throw new \Exception('Unknown autoloader caching strategy provided, was: ' . $i->cacheConf['type']);
		}

		if ($i->cacheConf['type'] == 'file' && ! \array_key_exists('path', $i->cacheConf)) {
			throw new \Exception('Missing cache directory [path] parameter in configuration');
		}

		if ($i->cacheConf['type'] == 'memcached') {
			if ( ! \array_key_exists('server', $i->cacheConf)) {
				throw new \Exception('Missing memcached [server] parameter in configuration');
			}

			if ( ! \array_key_exists('port', $i->cacheConf)) {
				throw new \Exception('Missing memcached [port] parameter in configuration');
			}
		}

		// Load in Modules
		// ===============

		if ( ! isset($conf['load'])) {
			throw new \Exception('Missing [load] key in autoloader configuration; the [load] key should specify paths where to search for modules');
		}

		$state = $i->load();

		if ($state != null) {
			$i->state = $state;
		}
		else { // no cached environment
			$i->loadModules($conf['load'], array_key_exists('depth', $conf) ? $conf['depth'] : $i->defaultDepth());
			$i->save(); // save state
		}

		return $i;
	}

	/**
	 * Search given paths and load modules into the current state
	 */
	function loadModules($searchPaths, $maxdepth) {

		$modules = [];

		// Final all modules
		// =================

		$idx = 0; // module order index

		$knownSegments = [];

		foreach ($searchPaths as $path) {
			$loadpath = $this->syspath.'/'.$path;
			$files = $this->find_file('composer.json', $loadpath, $maxdepth);
			foreach ($files as $file) {
				$composerjson = \json_decode($this->file_get_contents($file->getRealPath()), true);
				if ($this->composer_has_cfsinfo($composerjson)) {
					$moduleName = $composerjson['name'];

					// backwards compatibility
					$moduleName = \str_replace('.', '/', $moduleName);

					$this->state['modules'][$moduleName] = [
						'idx' => null,
						'enabled' => true,
						'path' => realpath($file->getPath()),
						'namespace' => \str_replace('/', '\\', $moduleName).'\\',
						'rules' => [
							'identity' => [],
							'matches-before' => []
						]
					];

					$slashPos = \strpos($moduleName, '/');

					if ($slashPos === false || $slashPos == 0) {
						throw new \Exception('Invalid freia module name ['.$moduleName.']. The module name must have at least two segments seperated by a slash');
					}

					$mainsegment = \substr($moduleName, 0, $slashPos);

					if ( ! \in_array($mainsegment, $knownSegments)) {
						$knownSegments[] = $mainsegment;
					}

					// create entry in ordering index for rules reference
					$modules[$moduleName] = $idx += 1;

					if (isset($composerjson['extra'], $composerjson['extra']['freia'], $composerjson['extra']['freia']['rules'])) {
						$this->state['modules'][$moduleName]['rules'] = array_merge($this->state['modules'][$moduleName]['rules'], $composerjson['extra']['freia']['rules']);
						if ( ! $this->debugMode) {
							if (in_array('debug', $this->state['modules'][$moduleName]['rules']['identity'])) {
								$this->state['modules'][$moduleName]['enabled'] = false;
							}
						}
					}
				}
			}
		}

		$this->state['known-segments'] = $knownSegments;

		// Apply Stacking Rules
		// ====================

		$iteration = 0;

		do {

			// Sanity Check
			// ------------

			# it's entirely poissible for recursive rules to be applied, so we
			# need to have a stop mechanism; the system will try to function
			# even if recursion is detected; but a warning will be shown

			$iteration += 1;
			if ($iteration == $this->maxRulesIterations()) {
				$this->error_log("Exceeded maximum rule iteration count; potentially recursive module ruleset");
				throw new \Exception("Exceeded maximum iterations count while trying to apply module rules; potential module recursive rules");
			}

			// Sorter
			// ------

			$ordered = true;
			$ordered_modules = $modules;

			foreach ($this->state['modules'] as $moduleName => $module) {
				foreach ($module['rules']['matches-before'] as $target) {

					# matches-before: this module recieves idx of top
					# matching target -1 and all other modules aside from
					# this module who have idx < target idx recieve -1,
					# all entries with idx > old module idx recieve -1; if
					# target is not found in the modules or if the idx is
					# already smaller then the entire operation is skipped
					# and everyting retains their current idx

					$targetmodulename = static::firstMatchingModule(\array_keys($ordered_modules), $target);

					if ($targetmodulename !== null) {
						$target_idx = $ordered_modules[$targetmodulename];
						if ($target_idx < $ordered_modules[$module]) {

							$updated_modules = $ordered_modules;
							$old_module_idx = $updated_modules[$module];
							$updated_modules[$module] = $updated_modules[$targetmodulename] - 1;

							foreach ($ordered_modules as $k => $idx) {
								if ($idx < $target_idx && $k != $module) {
									$updated_modules[$k] = $idx - 1;
								}
								else if ($idx > $old_module_idx) {
									$updated_modules[$k] = $idx - 1;
								}
							}

							asort($updated_modules);
							$ordered_modules = $updated_modules;
							$ordered = false;
						}
					}
				}
			}

			$modules = $ordered_modules;
		}
		while ( ! $ordered);

		// Integrate Updated Order
		// =======================

		$updatedModules = [];
		foreach ($modules as $moduleName => $idx) {
			$updatedModules[$moduleName] = $this->state['modules'][$moduleName];
			$updatedModules[$moduleName]['idx'] = $idx;
		}

		$this->state['modules'] = $updatedModules;
	}

	/**
	 * [matches-before] rule helper function
	 *
	 * @return string name
	 */
	protected static function firstMatchingModule($module_names, $target) {
		foreach ($module_names as $module) {
			if (stripos($module, $target) !== false) {
				return $module;
			}
		}
	}

	/**
	 * @return string|null symbol path
	 */
	function existingResolution($symbolName) {
		if (\array_key_exists($symbolName, $this->state['symbols'])) {
			return $this->state['symbols'][$symbolName];
		}
		else {
			return null; // couldn't find existing symbol resolution
		}
	}

	/**
	 * @return boolean does the system know of this main segment?
	 */
	function knownSegment($mainsegment) {
		return \in_array($mainsegment, $this->state['known-segments']);
	}

	/**
	 * @return array [ $targetfile, $targetns, $target ]
	 */
	function findFirstFileMatching($ns, $name, $filename) {
		foreach ($this->state['modules'] as $module) {

			if ( ! $module['enabled']) {
				continue;
			}

			if (\strripos($module['namespace'], $ns) !== false) {
				$targetfile = $module['path'].'/src/'.$filename.'.php';
				if ($this->file_exists($targetfile)) {
					$target = $module['namespace'].$name;
					return [ $targetfile, $module['namespace'], $target ];
				}
			}
		}

		return null; // failed to match anything
	}

	/**
	 * Similar to findFirstFileMatching but assumes /next/ in segment and
	 * matches after skipping the everything up to and including the namespace
	 * before the /next/ key segment.
	 *
	 * @return array [ $targetfile, $targetns, $target ]
	 */
	function findFirstFileMatchingAfterNext($ns, $name, $filename) {
		$nextPtr = \strrpos($ns, '\\next\\');

		// due to the way the syntax works, the namespace before the keyword
		// next is implied from the file namespace so it's always a complete
		// namespace rather then a partial one
		$skipNamespace = substr($ns, 0, $nextPtr + 1);

		$targetNs = substr($ns, $nextPtr + 6); // strlen('\\next\\') = 6

		$skipped = false;
		foreach ($this->state['modules'] as $module) {

			if ( ! $skipped) {
				if ($module['namespace'] == $skipNamespace) {
					$skipped = true;
				}

				continue;
			}

			if ( ! $module['enabled']) {
				continue;
			}

			if (\strripos($module['namespace'], $targetNs) !== false) {
				$targetfile = $module['path'].'/src/'.$filename.'.php';
				if ($this->file_exists($targetfile)) {
					$target = $module['namespace'].$name;
					return [ $targetfile, $module['namespace'], $target ];
				}
			}
		}

		return null; // failed to match anything
	}

	/**
	 * ...
	 */
	function loadSymbol($symbol, $filepath) {
		$this->requirefile($filepath);
		$this->state['symbols'][$symbol] = $filepath;
		$this->state['loaded'][] = $symbol;
	}

	/**
	 * ...
	 */
	function aliasSymbol($from, $to) {
		$this->class_alias($from, $to);
		$this->state['aliases'][$to] = $from;
		$this->state['loaded'][] = $to;
	}

	/**
	 * Attempt to auto-resolve a symbol based on past state.
	 */
	function autoresolve($symbol) {

		// is the symbol an alias?
		if (\array_key_exists($symbol, $this->state['aliases'])) {
			// then resolve the alias
			// you can only alias to know symbols so the following will succeed
			$this->autoresolve($this->state['aliases'][$symbol]);
			// and afterwards alias the symbol
			$this->class_alias($this->state['aliases'][$symbol], $symbol);
			return true;
		}

		// do we know of the symbol's path?
		if (\array_key_exists($symbol, $this->state['symbols'])) {
			$this->requirefile($this->state['symbols'][$symbol]);
			return true;
		}

		return false; // none of the above
	}

	/**
	 * @return boolean
	 */
	function isLoadedSymbol($symbol) {
		return \in_array($symbol, $this->state['loaded']);
	}

	/**
	 * Register a known unknown to avoid any costly calculations
	 */
	function unknownSymbol($symbol) {
		$this->state['known-unknowns'][] = $symbol;
	}

	/**
	 * @return boolean is the symbol known to be unknown?
	 */
	function knownUnknown($symbol) {
		return \in_array($symbol, $this->state['known-unknowns']);
	}

	/**
	 * @return array
	 */
	function paths() {
		$paths = [];
		foreach ($this->state['modules'] as $name => $module) {
			$paths[str_replace('/', '.', $name)] = $module['path'];
		}

		return $paths;
	}

// ---- Persistence -----------------------------------------------------------

	/**
	 * Save current state to persistent store
	 */
	function save() {

		if ($this->cacheConf['type'] == 'noop') {
			// do nothing
		}
		else { // non-noop

			$stateCopy = $this->state;
			$stateCopy['loaded'] = [];

			if ($this->cacheConf['type'] == 'file') {
				$username = \get_current_user();
				$cacheDir = realpath($this->syspath.'/'.\trim($this->cacheConf['path'], '\\/'));
				$cachePath = $cacheDir.'/freia.'.(\php_sapi_name() === 'cli' ? 'cli' : 'server').'.'.$username.'.symbols.json';
				if ($this->file_exists($cacheDir)) {
					try {
						$this->file_put_contents($cachePath, \json_encode($stateCopy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
						if ( ! $this->chmod($cachePath, $this->filePermission())) {
							$this->error_log("Unable to set permissions on cache file: $cachePath");
						}
					}
					catch (\Exception $e) {
						$this->error_log('Failed to save state to cache file ['.$cachePath.'] Error: '.$e->getMessage());
					}
				}
				else {
					$this->error_log('Missing cache directory: '.$this->cacheConf['path']);
				}
			}
			else if ($this->cacheConf['type'] == 'memcached') {
				$mc = $this->memcachedInstance();
				$success = $mc->set('freia-symbols', $stateCopy, time() + 604800);
				if ( ! $success) {
					$this->error_log("Memcached failed to store key freia-symbols, code: ".$mc->getResultCode());
				}
			}
		}
	}

	/**
	 * Load state from persistent store
	 *
	 * @return array previously saved state
	 */
	function load() {

		if ($this->cacheConf['type'] == 'noop') {
			return null; // do nothing
		}
		else { // non-noop

			$loadedState = null;

			if ($this->cacheConf['type'] == 'file') {
				$username = \get_current_user();
				$cacheDir = realpath($this->syspath.'/'.\trim($this->cacheConf['path'], '\\/'));
				$cachePath = $cacheDir.'/freia.'.(\php_sapi_name() === 'cli' ? 'cli' : 'server').'.'.$username.'.symbols.json';
				if ($this->file_exists($cachePath)) {
					try {
						$loadedState = \json_decode($this->file_get_contents($cachePath), true);
					}
					catch (\Exception $e) {
						$this->error_log('Failed to load cache file: '.$cachePath);
						return null;
					}
				}
				else { // couldn't find cache file
					return null;
				}
			}
			else if ($this->cacheConf['type'] == 'memcached') {
				$mc = $this->memcachedInstance();
				try {
					$loadedState = $mc->get('freia-symbols');
				}
				catch (\Exception $e) {
					$loadedState = null;
				}
			}

			// Process Loaded State
			// ====================

			if ($loadedState == null) {
				return null;
			}

			if ($loadedState['version'] == $this->state['version']) {
				return $loadedState;
			}
			else { // deprecated state
				return null;
			}
		}
	}

// ---- Caching Servers -------------------------------------------------------

	/**
	 * @var \Memcached
	 */
	protected $memcached = null;

	/**
	 * @return \Memcached
	 */
	function memcachedInstance() {
		if ($this->memcached == null) {

			if ( ! \class_exists('\Memcached', false)) {
				throw new \Exception('Memcached is not enabled on the system');
			}

			$this->memcached = new \Memcached();

			$this->memcached->addServer (
				$this->cacheConf['server'],
				$this->cacheConf['port']
			);
		}

		return $this->memcached;
	}

// ---- Configuration Hooks ---------------------------------------------------

	/**
	 * How many times to try to apply rules before assuming infinite recursion
	 * caused by misconfiguration of modules.
	 *
	 * @return int
	 */
	protected function maxRulesIterations() {
		return 1000;
	}

	/**
	 * Default depth for module loading; depth is relative to load paths.
	 *
	 * @return int the maximum depth
	 */
	protected function defaultDepth() {
		return 3;
	}

	/**
	 * @return int
	 */
	protected function filePermission() {
		return 0666;
	}

	/**
	 * @return boolean
	 */
	protected function composer_has_cfsinfo($json) {
		return isset($json['type'], $json['name'])
			&& $json['type'] == 'freia-module';
	}

// ---- Helpers ---------------------------------------------------------------

	/**
	 * @return \SplFileInfo[]
	 */
	protected static function find_file($searchedfile, $searchpath, $maxdepth = -1) {
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
	 * @return boolean
	 */
	protected function class_alias($class, $alias) {
		return class_alias($class, $alias);
	}

	/**
	 * Testing hook.
	 * @codeCoverageIgnore
	 */
	protected function file_exists($file) {
		return \file_exists($file);
	}

	/**
	 * Testing hook.
	 * @codeCoverageIgnore
	 * @return mixed
	 */
	protected function file_get_contents($file) {
		return \file_get_contents($file);
	}

	/**
	 * Testing hook.
	 * @codeCoverageIgnore
	 * @return int
	 */
	protected function file_put_contents($file, $data, $flags = 0) {
		return \file_put_contents($file, $data, $flags);
	}

	/**
	 * Testing hook.
	 * @codeCoverageIgnore
	 */
	protected function error_log($message) {
		$unn = trim(preg_replace('#[^a-zA-Z0-9_]#', '.', \get_class()), '.');
		\error_log("[$unn] $message");
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

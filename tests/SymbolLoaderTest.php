<?php namespace freia\autoloader\tests;

$srcpath = realpath(__DIR__.'/../src');
require "$srcpath/SymbolLoader.php";

class MockFile {

	protected $path = null;

	static function i($path) {
		$i = new static;
		$i->path = $path;
		return $i;
	}

	function getRealPath() {
		return $this->path;
	}

	function getPath() {
		$ptr = strrpos($this->path, '/');
		return substr($this->path, 0, $ptr);
	}

} # class

class SymbolLoaderTester extends \freia\autoloader\SymbolLoader {

	public $mkExistingSymbol = [
		// empty
	];

	function exists($symbol, $autoload = false) {
		return in_array(static::unn($symbol), $this->mkExistingSymbol);
	}

	public $mkFileExists = [
		'rootpath:/files/cache',
		'rootpath:/package_1/module5.system.super.core.module6.system.core/src/Example1.php',
		'rootpath:/package_1/module0.module2/src/Example1.php',
		'rootpath:/package_1/module1.system.legacysupport/src/Example1.php',
		'rootpath:/package_1/module1.system.core/src/Example1.php',
		'rootpath:/package_1/module1.system.core/src/Example2.php',
		'rootpath:/package_2/module1.tools/src/Example1.php',
		'rootpath:/package_2/module2.system/src/Example1.php',
		'rootpath:/package_2/module3.system/src/Example1.php',
		'rootpath:/package_2/module3.system/src/Example3.php',
	];

	protected function file_exists($file) {
		$res = in_array($file, $this->mkFileExists);
		return $res;
	}

	public $mkFileContents = [
		'rootpath:/package_1/module6.demo/composer.json' =>
			'
				{
					"name": "module6/demo",
					"type": "freia-module"
				}
			',
		'rootpath:/package_1/module5.system.super.core.module6.system.core/composer.json' =>
			'
				{
					"name": "module5/system/super/core/module6/system/core",
					"type": "freia-module"
				}
			',
		'rootpath:/package_1/module0.module2/composer.json' =>
			'
				{
					"name": "module0/module2",
					"type": "freia-module"
				}
			',
		'rootpath:/package_1/module1.system.legacysupport/composer.json' =>
			'
				{
					"name": "module1/system/legacysupport",
					"type": "freia-module"
				}
			',
		'rootpath:/package_1/module1.system.core/composer.json' =>
			'
				{
					"name": "module1/system/core",
					"type": "freia-module"
				}
			',
		'rootpath:/package_2/module1.tools/composer.json' =>
			'
				{
					"name": "module1/tools",
					"type": "freia-module"
				}
			',
		'rootpath:/package_2/module2.system/composer.json' =>
			'
				{
					"name": "module2/system",
					"type": "freia-module"
				}
			',
		'rootpath:/package_2/module3.system/composer.json' =>
			'
				{
					"name": "module3/system",
					"type": "freia-module"
				}
			',
	];

	protected function file_get_contents($file) {
		if (isset($this->mkFileContents[$file])) {
			$res = $this->mkFileContents[$file];
		}
		else { // empty
			$res = false;
		}

		return $res;
	}

	protected function file_put_contents($file, $data, $flags = 0) {
		$this->mkFileContents[$file] = $data;
		return true;
	}

	public $mkErrors = [
		// empty
	];

	protected function error_log($message) {
		$this->mkErrors[] = $message;
	}

	public $mkAliases = [
		// empty
	];

	protected function class_alias($class, $alias) {
		return $this->mkAliases[$alias] = $class;
	}

	protected function chmod($filepath, $mode) {
		return true;
	}

	protected function requirefile($symbolfile) {
		// do nothing
	}

	protected function mkFileSearches() {
		return [
			'composer.json | rootpath:/package_1' => [
				MockFile::i('rootpath:/package_1/module6.demo/composer.json'),
				MockFile::i('rootpath:/package_1/module5.system.super.core.module6.system.core/composer.json'),
				MockFile::i('rootpath:/package_1/module0.module2/composer.json'),
				MockFile::i('rootpath:/package_1/module1.system.legacysupport/composer.json'),
				MockFile::i('rootpath:/package_1/module1.system.core/composer.json')
			],
			'composer.json | rootpath:/package_2' => [
				MockFile::i('rootpath:/package_2/module1.tools/composer.json'),
				MockFile::i('rootpath:/package_2/module2.system/composer.json'),
				MockFile::i('rootpath:/package_2/module3.system/composer.json')
			]
		];
	}

	protected function find_file($searchedfile, $searchpath, $maxdepth = -1) {
		$mkFileSearches = $this->mkFileSearches();
		$key = "$searchedfile | $searchpath";
		if (isset($mkFileSearches[$key])) {
			$res = $mkFileSearches[$key];
			return $res;
		}
		else { // not found
			return [];
		}
	}

	function mkLoad($symbol) {
		$this->lastAlias = null;
		$found = $this->load(static::pnn($symbol));
		if ($found) {
			return static::unn($this->lastsymbol);
		}
		else { // not found
			return '<fail-match>';
		}

	}

} # class

class SymbolLoaderTest extends \PHPUnit_Framework_TestCase {

	/** @test */ function
	load() {

		$loader = SymbolLoaderTester::instance('rootpath:', [
			'cache.dir' => 'files/cache',
			'load' => ['package_0', 'package_1', 'package_2', 'package_3']
		]);

		$tests = [

			// simple resolution (absolute and dynamic)
			// ----------------------------------------------------------------
			'module1.system.core.Example1' => 'module1.system.core.Example1',
			'module1.system.Example1' => 'module1.system.legacysupport.Example1',
			'module1.tools.Example1' => 'module1.tools.Example1',
			'module1.Example1' => 'module1.system.legacysupport.Example1',

			// overwriting from foreign namespace
			// ----------------------------------------------------------------
			'module2.system.Example1' => 'module2.system.Example1',
			'module2.Example1' => 'module0.module2.Example1',
			'module6.system.core.Example1' => 'module5.system.super.core.module6.system.core.Example1',
			'module6.system.Example1' => 'module5.system.super.core.module6.system.core.Example1',

			// infinite blind extention via the "next" keyword
			// ----------------------------------------------------------------
			# class Example2 extends next\module1\Example1
			'module1.Example2' => 'module1.system.core.Example2',
			'module1.system.core.next.module1.Example1' => 'module1.tools.Example1',

			// explicit inheritance
			// ----------------------------------------------------------------
			'module3.Example1' => 'module3.system.Example1',
			'module3.Example3' => 'module3.system.Example3',
			'module1.system.core.Example1' => 'module1.system.core.Example1',

		];

		foreach ($tests as $symbol => $expected) {
			$loader->mkErrors = [];
			$actual = $loader->mkLoad($symbol);
			$this->assertEquals(0, count($loader->mkErrors));
			$this->assertEquals($expected, $actual);
		}

		// non-main namespace segments should not match anything
		$loader->mkErrors = [];
		$actual = $loader->mkLoad('system.Example1');
		$this->assertEquals(0, count($loader->mkErrors));
		$this->assertEquals('<fail-match>', $actual);
	}

} # test

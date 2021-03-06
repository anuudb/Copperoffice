<?php
namespace go\core;


/**
 * Debugger class. All entries are stored and the view can render them eventually.
 * The JSON view returns them all.
 * 
 * The client can enable by sending an HTTP header X-Debug=1 (Use CTRL + F7 in webclient)
 * 
 * Example:
 * 
 * ````````````````````````````````````````````````````````````````````````````
 * \go\core\App::get()->debug($mixed);
 * ````````````````````````````````````````````````````````````````````````````
 * 
 * or:
 * 
 * ````````````````````````````````````````````````````````````````````````````
 * \go\core\App::get()->getDebugger()->debugCalledFrom();
 * ````````````````````````````````````````````````````````````````````````````
 *
 * @copyright (c) 2014, Intermesh BV http://www.intermesh.nl
 * @author Merijn Schering <mschering@intermesh.nl>
 * @license http://www.gnu.org/licenses/agpl-3.0.html AGPLv3
 */
class Debugger {
	
	const SECTION_INIT = 'init';
	
	const SECTION_ROUTER = 'router';
	
	const SECTION_CONTROLLER = 'controller';
	
	const SECTION_VIEW = 'view';
	
	const LEVEL_LOG = 'log';
	
	const LEVEL_WARN = 'warn';
	
	const LEVEL_INFO = 'info';
	
	const LEVEL_ERROR = 'error';

	/**
	 * Sets the debugger on or off
	 * @var boolean
	 */
	public $enabled = false;

	/**
	 * When set all visible debug messaged are written to this file
	 * @var string Full path on FS
	 */
	public $logPath;
	
	

	/**
	 * The debug entries as strings
	 * @var array
	 */
	private $entries = [];
	
	public function __construct() {
		try {
			$this->enabled = (!empty(GO()->getConfig()['core']['general']['debug']) || jmap\Request::get()->getHeader('X-Debug') == "1") && (!isset($_REQUEST['r']) || $_REQUEST['r']!='core/debug');
			if($this->enabled) {
				$this->logPath = GO()->getDataFolder()->getFile('log/debug.log')->getPath();
			}
		} catch (\go\core\exception\ConfigurationException $e) {
			//GO is not configured / installed yet.
			$this->enabled = true;
		}
	}

	/**
	 * Get time in milliseconds
	 * 
	 * @return float Milliseconds
	 */
	public function getMicroTime() {
		list ($usec, $sec) = explode(" ", microtime());
		return ((float) $usec + (float) $sec);
	}	
	
	public function warn($mixed, $traceBackSteps = 0) {
		$this->internalLog($mixed, self::LEVEL_WARN, $traceBackSteps);
	}
	
	public function error($mixed, $traceBackSteps = 0) {
		$this->internalLog($mixed, self::LEVEL_ERROR, $traceBackSteps);
	}
	
	public function info($mixed, $traceBackSteps = 0) {
		$this->internalLog($mixed, self::LEVEL_INFO, $traceBackSteps);
	}
	
	public function debug($mixed, $traceBackSteps = 0) {
		$this->log($mixed, $traceBackSteps);
	}
	
	public function log($mixed, $traceBackSteps = 0) {
		$this->internalLog($mixed, self::LEVEL_LOG, $traceBackSteps);
	}
	

	/**
	 * Add a debug entry. Objects will be converted to strings with var_export();
	 * 
	 * You can also provide a closure function so code will only be executed when
	 * debugging is enabled.
	 *
	 * @todo if for some reason an error occurs here then an infinite loop is created
	 * @param callable|string|object $mixed
	 * @param string $level The type of message. Types can be arbitrary and can be enabled and disabled for output. {@see self::$enabledTypes}
	 */
	private function internalLog($mixed, $level = self::LEVEL_LOG, $traceBackSteps = 0) {

		if(!$this->enabled) {
			return;
		}		
		
		if($mixed instanceof \Closure) {
			$mixed = call_user_func($mixed);
		}elseif(is_object($mixed) && method_exists($mixed, '__toString')) {
			$mixed = (string) $mixed;
		}elseif (!is_scalar($mixed)) {
			$mixed = print_r($mixed, true);
		}
		
		$bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 7 + $traceBackSteps);
		
//		var_dump($bt);
		$lastCaller = null;
		$caller = array_shift($bt);
		//can be called with \go\core\App::get()->debug(). We need to go one step back (no class for closure)
		while(isset($caller['class']) && ($caller['function'] == 'debug' || $caller['function'] == 'warn' || $caller['function'] == 'error' || $caller['function'] == 'info' || $caller['class'] == self::class)) {
			$lastCaller = $caller;
			$caller = array_shift($bt);
		}
		
		$count = count($bt);
		
		$traceBackSteps = min([$count, $traceBackSteps]);
		
		while($traceBackSteps > 0) {			

			$caller = array_shift($bt);
			$traceBackSteps--;			
		}
		
		if(empty($caller['class'])) {
			
			$caller['class'] = 'closure';
		}
		
		if(!isset($caller['line'])) {
			$caller['line'] = '[unknown line]';
		}
		
		$entry = "[" . $this->getTimeStamp() . "][" . $caller['class'] . ":".$lastCaller['line']."] " . $mixed;

		if(!empty($this->logPath)) {
			$debugLog = new Fs\File($this->logPath);

			if($debugLog->isWritable()) {
				$debugLog->putContents($entry."\n", FILE_APPEND);
			}
		}
		
//		if(Environment::get()->isCli()) {
//			echo $entry . "\n";
//		}
		
		$this->entries[] = [$level, $entry];
		
	}

	/**
	 * Add a message that notes the time since the request started in milliseconds
	 * 
	 * @param string $message
	 */
	public function debugTiming($message) {
		$this->debug($this->getTimeStamp() . ' ' . $message);
	}

	private function getTimeStamp() {
		return intval(($this->getMicroTime() - $_SERVER["REQUEST_TIME_FLOAT"])*1000) . 'ms';
	}

	public function debugCalledFrom($limit = 10) {

		$this->debug("START BACKTRACE");
		$trace = debug_backtrace();

		$count = count($trace);

		$limit++;
		if ($limit > $count) {
			$limit = $count;
		}

		for ($i = 1; $i < $limit; $i++) {
			$call = $trace[$i];

			if (!isset($call["file"])) {
				$call["file"] = 'unknown';
			}
			if (!isset($call["function"])) {
				$call["function"] = 'unknown';
			}

			if (!isset($call["line"])) {
				$call["line"] = 'unknown';
			}

			$this->debug("Function: " . $call["function"] . " called in file " . $call["file"] . " on line " . $call["line"]);
		}
		$this->debug("END BACKTRACE");
	}
	
	/**
	 * Get the debugger entries
	 * 
	 * @return array
	 */
	public function getEntries() {
		return $this->entries;
	}
	
	/**
	 * Print all entries
	 */
	public function printEntries() {
		echo implode("\n", array_map(function($e){return $e[1];}, $this->entries));
	}
	
	/**
	 * Returns the type of a given variable.
	 * 
	 * @param mixed $var
	 * @return string
	 */
	public static function getType($var) {
		if(is_object($var)) {
			return get_class($var);
		}else
		{
			return gettype($var);
		}
	}

}

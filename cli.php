#!/usr/bin/php
<?php
namespace Kloudspeaker;

require 'api/autoload.php';

$logger = new \Monolog\Logger('kloudspeaker-cli');
$logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Logger::INFO));

function ln() {
	global $logger;

	foreach (func_get_args() as $s) {
		if (is_array($s))
			$logger != NULL ? $logger->info(Utils::array2str($s)) : var_dump($s);
		else
			$logger != NULL ? $logger->info($s) : var_dump($s);
	}
}

class ErrorHandler {
	public function php($errno, $errstr, $errfile, $errline, $errCtx) {
		ln("PHP error #" . $errno . ", " . $errstr . " (" . $errfile . ":" . $errline . ")");
		die();
	}

	public function exception($exception) {
		if (is_a($exception, "Kloudspeaker\Command\CommandException")) {
	        ln("Command failed: " . $exception->getMessage());
	    } else if (is_a($exception, "Kloudspeaker\KloudspeakerException")) {
	        ln("Command failed", ["code" => $exception->getErrorCode(), "msg" => $exception->getMessage(), "result" => $exception->getResult(), "trace" => $exception->getTraceAsString()]);
	    } else if (is_a($exception, "ServiceException")) {
	        //legacy
	        ln("Command failed", ["code" => $exception->getErrorCode(), "msg" => $exception->type() . "/" . $exception->getMessage(), "result" => $exception->getResult(), "trace" => $exception->getTraceAsString()]);
	    } else {
	    	ln("Command failed, unknown exception", ["msg" => $exception->getMessage(), "trace" => $exception->getTraceAsString()]);
	    	//var_dump($exception);
	    }
		exit(-1);
	}

	public function fatal() {
		$error = error_get_last();
		//var_dump($error);
		if ($error !== NULL) ln("FATAL ERROR", var_export($error));
		exit(-1);
	}
}

$errorHandler = new ErrorHandler();
set_error_handler(array($errorHandler, 'php'));
set_exception_handler(array($errorHandler, 'exception'));
register_shutdown_function(array($errorHandler, 'fatal'));

$systemInfo = getKloudspeakerSystemInfo();

$logLevel = (isset($systemInfo["config"]["debug"]) and $systemInfo["config"]["debug"]) ? \Monolog\Logger::DEBUG : \Monolog\Logger::INFO;
$logger = new \Monolog\Logger('kloudspeaker-cli');
$logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stdout', $logLevel));
$logger->pushHandler(new \Monolog\Handler\StreamHandler($systemInfo["root"]."/logs/cli.log", $logLevel));

if ($systemInfo["error"] != NULL) {
	ln("Kloudspeaker CLI");

	if (count($systemInfo["error"]) > 1) {
		$e = $systemInfo["error"][1];
		$logger->error(Utils::array2str(["msg" => $systemInfo["error"][0] . ": " . $e->getMessage(), "trace" => $e->getTraceAsString()]));
	} else
		$logger->error($systemInfo["error"][0]);
	exit(-1);
}
ln("Kloudspeaker CLI", ["version" => $systemInfo["version"], "revision" => $systemInfo["revision"]]);

$config = new Configuration($systemInfo);

$app = new Api($config);
$app->initialize(new \KloudspeakerLegacy($config), [ "logger" => function() use ($logger) {
    return $logger;
}]);
$container = $app->getContainer();

require 'setup/autoload.php';
autoload_kloudspeaker_setup($container);

$opts = getOpts($argv);
if (count($opts["commands"]) === 0) {
	ln("No command specified");
	exit(0);
}

$command = $opts["commands"][0];
$options = $opts["options"];

ln($opts);

if ("list" == $command) {
	ln($container->commands->get(count($opts["commands"]) > 1 ? $opts["commands"][1] : NULL));
	exit(0);
}

ln("Command [$command]");
if (!$systemInfo["config_exists"] and ($command != "system:config" and !\Kloudspeaker\Utils::strStartsWith($command, "installer:"))) {
    ln("System not configured, create configuration first with 'install:perform' or command 'system:config'");
    exit(0);
}
if ($command == "system:config" or ($command == "installer:perform" and !$systemInfo["config_exists"])) {
	ln("System configuration");

	$config = isset($options["config"]) ? $options["config"] : [];
	ln($config);

	if (!isset($config['db.dsn']) or \Kloudspeaker\Utils::isEmpty($config['db.dsn'])) {
		$config['db.dsn'] = ask('Enter database DSN:');
	}
	if (!isset($config['db.user']) or \Kloudspeaker\Utils::isEmpty($config['db.user'])) {
		$config['db.user'] = ask('Enter database user:');
	}
	if (!isset($config['db.password']) or \Kloudspeaker\Utils::isEmpty($config['db.password'])) {
		$config['db.password'] = ask('Enter database password:');
	}

	ln($config);
	$options["config"] = $config;
}

if (!$container->commands->exists($command)) {
	ln("Command not found [$command]");
	exit(0);
}

$result = $container->commands->execute($command, array_slice($opts["commands"], 1), $options);

ln("Result:", $result);
echo is_array($result) ? \Kloudspeaker\Utils::array2str($result) : $result;
echo "\n";

// TOOLS

function ask($title) {
	echo $title;
	$response = fgets(STDIN);
	return rtrim($response, "\n");
}

function getOpts($args) {
	ln("args", $args);

	array_shift($args);
	$endofoptions = false;

	$ret = array(
		'commands' => array(),
		'options' => array(),
		'flags' => array(),
		'arguments' => array(),
	);

	while ($arg = array_shift($args)) {
		// if we have reached end of options,
		//we cast all remaining argvs as arguments
		if ($endofoptions) {
			$ret['arguments'][] = $arg;
			continue;
		}

		// Is it a command? (prefixed with --)
		if (substr($arg, 0, 2) === '--') {
			// is it the end of options flag?
			if (!isset($arg[3])) {
				echo "end of options\n";
				$endofoptions = true; // end of options;
				continue;
			}

			$value = "";
			$com = substr($arg, 2);

			
			if (strpos($com, ':') !== FALSE) {
				// is it the syntax '--option:argument'?
				list($com, $value) = explode(":", $com, 2);
				if (strpos($value, '=') !== FALSE) {
					list($key, $val) = explode("=", $value, 2);
					if (!isset($ret['options'][$com])) $ret['options'][$com] = [];
					$ret['options'][$com][$key] = $val;
					continue;
				}
			} else if (strpos($com, '=') !== FALSE) {
				// is it the syntax '--option=argument'?
				list($com, $value) = explode("=", $com, 2);
			} elseif (strpos($args[0], '-') !== 0) {
				// is the option not followed by another option but by arguments
				while (strpos($args[0], '-') !== 0) {
					$value .= array_shift($args) . ' ';
				}

				$value = rtrim($value, ' ');
			}

			$ret['options'][$com] = !empty($value) ? $value : true;
			continue;
		}

		// Is it a flag or a serial of flags? (prefixed with -)
		if (substr($arg, 0, 1) === '-') {
			for ($i = 1;isset($arg[$i]); $i++) {
				$ret['flags'][] = $arg[$i];
			}

			continue;
		}

		// finally, it is not option, nor flag, nor argument
		$ret['commands'][] = $arg;
		continue;
	}

	/*if (!count($ret['options']) && !count($ret['flags'])) {
		$ret['arguments'] = array_merge($ret['commands'], $ret['arguments']);
		$ret['commands'] = array();
	}*/
	
	return $ret;
}

exit(0);
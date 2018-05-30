<?php

define('__ROOT__', __DIR__);

if ($argc < 2)
	usage();

date_default_timezone_set('UTC');

$config = require_once __DIR__ . '/config.php';

$map = ['livedata' => 'LiveDataController', 'fastdata' => 'FastDataController', 'blocks' => 'BlocksController', 'balance' => 'BalanceController'];
$controller = $map[$argv[1]] ?? null;

if (!$controller) {
	echo "Invalid operation.\n";
	usage();
}

spl_autoload_register(function ($class) {
	$class = explode('\\', $class);
	if ($class[0] == 'App')
		$class[0] = 'src';

	require_once __DIR__ . '/' . implode('/', $class) . '.php';
});

if (!isset($config['base_dir']) || !isset($config['current_xdag_file']))
	die("Config key 'base_dir' or 'current_xdag_file' is missing.\n");

$current_xdag = @file_get_contents($config['base_dir'] . '/' . $config['current_xdag_file']);
if (!preg_match('/^[0-9]+$/', $current_xdag))
	die("current_xdag_file doesn't contain positive integer (check config.php).\n");

$socket_file = $config['base_dir'] . '/xdag' . $current_xdag . '/client/unix_sock.dat';

if (isset($config['xdag_class']) && $config['xdag_class'] == 'XdagLocal')
	$xdag = new App\Xdag\XdagLocal($socket_file);
else
	$xdag = new App\Xdag\Xdag($socket_file);

$controller = "App\\Controllers\\$controller";
$controller = new $controller($config, $xdag);

$args = $argv;
array_shift($args);
array_shift($args);
call_user_func_array([$controller, 'index'], $args);

function usage()
{
	die("Usage: php " . basename(__FILE__, '.php') . " operation [args, ...]
operation
	livedata
	- prints live data (designed to be run every minute) either as
	- JSON or human readable text file, prints the state, stats, pool,
	- net conn commands and current system time including time zone

	fastdata
	- prints fast data (designed to be run every 5 minutes), currently
	- prints the miners command as JSON or human readable text file
	- and current system time including time zone

	balance
	- retrieves address balance, output is always JSON

	blocks
	- inspects new found blocks, exports one fully processed found block
	- or one already exported invalidated blocks based on arguments

livedata args:
	human-readable
	- if given, prints live data in human readable format (raw text file),
	- otherwise prints JSON

fastdata args:
	human-readable
	- if given, prints fast data in human readable format (raw text file),
	- otherwise prints JSON

balance args:
	{address}
	- xdag address to retrieve balance for

blocks args:
	process
	- processes new found blocks. Designed to be called 5 minutes.
	processAll
	- reprocesses each already processed block. Validates previously
	- invalidated blocks if required, also invalidates previously validated
	- blocks if required. Designed to be run once a day.
	export
	- exports oldest unexported fully processed and validated found block.
	- Can be called any time, if operation is currently locked, a proper
	- JSON status will be set, and the client should retry the call in
	- that case.
	exportInvalidated
	- exports one unexported previously exported but now invalidated block.
	- Can be called any time, if operation is currently locked, a proper
	- JSON status will be set, and the client should retry the call in
	- that case.
	resetExport
	- resets export of all already exported valid blocks.
	resetExportInvalidated
	- resets export of all invalidated blocks.
");
}

function dd()
{
	foreach (func_get_args() as $arg) {
		var_dump($arg);
		echo "\n";
	}

	die("\n");
}


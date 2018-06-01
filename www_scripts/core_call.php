<?php

$core_path = '/home/pool/scripts/engine/core.php';

$operation = $_GET['operation'] ?? '';

if ($operation == 'balance') {
	$address = $_GET['address'] ?? '';
	header('Content-Type: application/json');
	passthru('php ' . $core_path . ' balance ' . escapeshellarg($address));
} else if ($operation == 'block') {
	header('Content-Type: application/json');
	passthru('php ' . $core_path . ' blocks export');
} else if ($operation == 'blockInvalidated') {
	header('Content-Type: application/json');
	passthru('php ' . $core_path . ' blocks exportInvalidated');
} else if ($operation == 'livedata') {
	$human_readable = $_GET['human_readable'] ?? false;
	header($human_readable ? 'Content-Type: text/plain' : 'Content-Type: application/json');
	passthru('php ' . $core_path . ' livedata' . ($human_readable ? ' 1' : ''));
} else if ($operation == 'fastdata') {
	$human_readable = $_GET['human_readable'] ?? false;
	header($human_readable ? 'Content-Type: text/plain' : 'Content-Type: application/json');
	passthru('php ' . $core_path . ' fastdata' . ($human_readable ? ' 1' : ''));
} else {
	header('Content-Type: application/json');
	echo json_encode(['result' => 'empty', 'message' => 'Invaild action specified.'], JSON_PRETTY_PRINT);
}

<?php

$core_path = '/home/pool/scripts/engine/core.php';

header('Content-Type: application/json');

$operation = $_GET['operation'] ?? '';

if ($operation == 'balance') {
	$address = $_GET['address'] ?? '';
	passthru('php ' . $core_path . ' balance ' . escapeshellarg($address));
} else if ($operation == 'block') {
	passthru('php ' . $core_path . ' blocks export');
} else if ($operation == 'blockInvalidated') {
	passthru('php ' . $core_path . ' blocks exportInvalidated');
} else if ($operation == 'livedata') {
	$human_readable = $_GET['human_readable'] ?? false;
	passthru('php ' . $core_path . ' livedata' . ($human_readable ? ' 1' : ''));
} else if ($operation == 'fastdata') {
	$human_readable = $_GET['human_readable'] ?? false;
	passthru('php ' . $core_path . ' fastdata' . ($human_readable ? ' 1' : ''));
} else {
	echo json_encode(['result' => 'empty', 'message' => 'Invaild action specified.'], JSON_PRETTY_PRINT);
}

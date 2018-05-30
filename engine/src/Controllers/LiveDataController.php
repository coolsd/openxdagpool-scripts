<?php

namespace App\Controllers;

class LiveDataController extends DataController
{
	protected function json()
	{
		$data = [
			'version' => $this->xdag->getVersion(),
			'state' => $this->xdag->getState(),
			'stats' => $this->xdag->getStats(),
			'pool_config' => $this->xdag->getPoolConfig(),
			'net_conn' => $this->xdag->getConnections(),
			'date' => exec('date'),
		];

		$this->responseJson($data);
	}

	protected function humanReadable()
	{
		$data =
		"Version: " . $this->xdag->getVersion() . "\n\n" .
		"State: " . $this->xdag->command('state') . "\n\n" .
		$this->xdag->command('stats') . "\n\n" .
		$this->xdag->command('pool') . "\n\n" .
		$this->xdag->command('net conn') . "\n\n" .
		"Date: " . exec('date');

		$this->response($data);
	}
}

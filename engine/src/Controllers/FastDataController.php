<?php

namespace App\Controllers;

class FastDataController extends DataController
{
	public function json()
	{
		$data = [
			'miners' => $this->xdag->getMiners(),
			'date' => exec('date'),
		];

		$this->responseJson($data);
	}

	public function humanReadable()
	{
		$this->response($this->xdag->command('miners') . "\n\nDate: " . exec('date'));
	}
}

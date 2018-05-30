<?php

namespace App\Controllers;

use App\Xdag\Exceptions\XdagException;
use App\Support\{ExclusiveLock, UnableToObtainLockException};

class BlocksController extends Controller
{
	public function index($address = null)
	{
		$lock = new ExclusiveLock('balances', 300);

		try {
			$lock->obtain();
		} catch (UnableToObtainLockException $ex) {
			return $this->responseJson(['address' => $address, 'balance' => null]);
		}

		try {
			$balance = $this->xdag->getBalance($address);
		} catch (XdagException $ex) {
			$lock->release();
			return $this->responseJson(['address' => $address, 'balance' => null]);
		}

		return $this->responseJson(['address' => $address, 'balance' => $balance]);
	}
}

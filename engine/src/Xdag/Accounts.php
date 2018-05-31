<?php

namespace App\Xdag;

use App\Xdag\Exceptions\{XdagException, XdagBlockNotFoundException};
use App\Support\ExclusiveLock;

class Accounts
{
	protected $config, $get_accounts, $get_block;

	public function __construct(array $config, callable $get_accounts, callable $get_block)
	{
		$this->config = $config;
		$this->get_accounts = $get_accounts;
		$this->get_block = $get_block;
	}

	public function gather($all = false)
	{
		$lock = new ExclusiveLock('accounts_gather', 300);
		$lock->obtain();

		foreach (($this->get_accounts)($all ? 10000000000 : 10000) as $line) {
			$line = preg_split('/\s+/', trim($line));

			if (count($line) !== 4)
				continue;

			$this->saveAccount($line[0], [
				'hash' => null,
				'payouts_sum' => 0,
				'first_inspected_at' => null,
				'last_inspected_at' => null,
				'inspected_times' => 0,
				'found_at' => null,
				'exported_at' => null,
				'invalidated_at' => null,
				'invalidated_exported_at' => null,
			]);
		}

		$lock->release();
	}

	public function inspect($all = false)
	{
		$lock = new ExclusiveLock('accounts_process', 300);
		$lock->obtain();

		$date_threshold = date('Y-m-d H:i:s', strtotime('-5 days'));

		foreach ($this->accounts() as $address => $account) {
			if ($all || (!$account['invalidated_at'] && ($account['inspected_times'] < 3 || !$account['hash']))) {
				if ($account['first_inspected_at'] && $account['first_inspected_at'] < $date_threshold)
					continue; // account was processed enough times, skip processing to conserve resources

				if (!$all && $account['last_inspected_at'] && $account['last_inspected_at'] > date('Y-m-d H:i:s', strtotime('-10 minutes')) && (!$account['found_at'] || $account['found_at'] > date('Y-m-d H:i:s', strtotime('-1 day')) . '000'))
					continue; // do not inspect recent accounts too often, give pool a chance to pay out the miners

				if (!$account['first_inspected_at'])
					$account['first_inspected_at'] = date('Y-m-d H:i:s');

				$account['last_inspected_at'] = date('Y-m-d H:i:s');

				$this->saveAccount($address, $account, true);

				try {
					$block = ($this->get_block)($address);
				} catch (XdagBlockNotFoundException $ex) {
					$this->invalidateAccount($account);
					$this->saveAccount($address, $account, true);
					continue;
				} catch (\InvalidArgumentException $ex) {
					$this->invalidateAccount($account);
					$this->saveAccount($address, $account, true);
					continue;
				}

				if (!$block->hasEarning()) {
					$this->invalidateAccount($account);
					$this->saveAccount($address, $account, true);
					continue;
				}

				if ($account['invalidated_at']) {
					$this->validateAccount($account);
					$this->saveAccount($address, $account, true);
				}

				if (!$block->isPaidOut())
					continue;

				$account['inspected_times']++;
				$account['found_at'] = $block->getProperty('time');
				$account['hash'] = $block->getProperty('hash');

				if (($sum = $block->getPayoutsSum()) != $account['payouts_sum'])
					$account['exported_at'] = null;

				$account['payouts_sum'] = $sum;

				$this->saveAccount($address, $account, true);
			}
		}

		$lock->release();
	}

	public function resetExport()
	{
		$lock = new ExclusiveLock('accounts_process', 300);
		$lock->obtain();

		foreach ($this->accounts() as $address => $account) {
			if (!$account['invalidated_at'] && $account['exported_at']) {
				$account['exported_at'] = null;
				$this->saveAccount($address, $account, true);
			}
		}

		$lock->release();
	}

	public function resetExportInvalidated()
	{
		$lock = new ExclusiveLock('accounts_process', 300);
		$lock->obtain();

		foreach ($this->accounts() as $address => $account) {
			if ($account['invalidated_at'] && $account['invalidated_exported_at']) {
				$account['invalidated_exported_at'] = null;
				$this->saveAccount($address, $account, true);
			}
		}

		$lock->release();
	}

	public function exportBlock()
	{
		$lock = new ExclusiveLock('accounts_process', 300);
		$lock->obtain();

		$export_address = $export_account = null;
		foreach ($this->accounts() as $address => $account) {
			if (!$account['exported_at'] && $account['inspected_times'] >= 3 && $account['hash'] && !$account['invalidated_at']) {
				if (!$export_address || $account['found_at'] < $export['found_at']) {
					$export_address = $address;
					$export_account = $account;
				}
			}
		}

		if ($export_address) {
			$block = new Block();
			$json = $block->load($export_account['hash']);
			$export_account['exported_at'] = date('Y-m-d H:i:s');
			$this->saveAccount($export_address, $export_account, true);
			$lock->release();
			return $json;
		}

		$lock->release();
		return false;
	}

	public function exportBlockInvalidated()
	{
		$lock = new ExclusiveLock('accounts_process', 300);
		$lock->obtain();

		foreach ($this->accounts() as $address => $account) {
			if ($account['hash'] && $account['invalidated_at'] && !$account['invalidated_exported_at']) {
				$account['invalidated_exported_at'] = date('Y-m-d H:i:s');
				$this->saveAccount($address, $account, true);
				$lock->release();
				return json_encode(['invalidateBlock' => $account['hash']]);
			}
		}

		return false;
	}

	public function summary()
	{
		$not_fully_inspected = $to_be_exported = $to_be_exported_invalidated = $valid = $invalid = $total = 0;

		foreach ($this->accounts() as $address => $account) {
			if (!$account['invalidated_at'] && ($account['inspected_times'] < 3 || !$account['hash']))
				$not_fully_inspected++;

			if (!$account['exported_at'] && $account['inspected_times'] >= 3 && $account['hash'] && !$account['invalidated_at'])
				$to_be_exported++;

			if ($account['hash'] && $account['invalidated_at'] && !$account['invalidated_exported_at'])
				$to_be_exported_invalidated++;

			if ($account['hash'])
				$valid++;

			if ($account['invalidated_at'])
				$invalid++;

			$total++;
		}

		return [
			'not_fully_inspected' => $not_fully_inspected,
			'to_be_exported' => $to_be_exported,
			'to_be_exported_invalidated' => $to_be_exported_invalidated,
			'valid' => $valid,
			'invalid' => $invalid,
			'total' => $total,
		];
	}

	public function setup()
	{
		if (!$this->isFreshInstall())
			return false;

		if (isset($this->config['extra_accounts_file']) && $this->config['extra_accounts_file'] !== '') {
			$file = @fopen($this->config['extra_accounts_file'], 'r');

			if (!$file)
				return true;

			while (($line = fgets($file, 1024)) !== false) {
				$line = preg_split('/\s+/', trim($line));

				try {
					$this->saveAccount($line[0], [
						'hash' => null,
						'payouts_sum' => 0,
						'first_inspected_at' => null,
						'last_inspected_at' => null,
						'inspected_times' => 0,
						'found_at' => null,
						'exported_at' => null,
						'invalidated_at' => null,
						'invalidated_exported_at' => null,
					]);
				} catch (\InvalidArgumentException $ex) {
					// account address was invalid
				}
			}
		}

		return true;
	}

	protected function invalidateAccount(array &$account)
	{
		if ($account['exported_at'])
			$account['invalidated_at'] = date('Y-m-d H:i:s');
		else
			$account['invalidated_at'] = $account['invalidated_exported_at'] = date('Y-m-d H:i:s');

		// do we have block associated with this account?
		if ($account['hash']) {
			$block = new Block();

			try {
				$block->load($account['hash']);
			} catch (XdagException $ex) {
				return;
			}

			$block->remove();
		}
	}

	protected function validateAccount(array &$account)
	{
		if (!$account['invalidated_exported_at']) {
			$account['invalidated_at'] = null;
		} else {
			$account['invalidated_at'] = $account['invalidated_exported_at'] = $account['exported_at'] = null;
			$account['hash'] = $account['found_at'] = null;
			$account['inspected_times'] = 0;
		}
	}

	protected function isFreshInstall()
	{
		$dir = __ROOT__ . '/storage/accounts/';
		$dir = opendir($dir);

		while (($address = readdir($dir)) !== false) {
			if (!preg_match('/^[0-9a-z_+]{32}\.json$/siu', $address))
				continue;

			closedir($dir);
			return false;
		}

		closedir($dir);
		return true;
	}

	protected function accounts()
	{
		$dir = __ROOT__ . '/storage/accounts/';
		$dir = opendir($dir);

		while (($address = readdir($dir)) !== false) {
			if (!preg_match('/^[0-9a-z_+]{32}\.json$/siu', $address))
				continue;

			$address = basename($address, '.json');

			try {
				$account = $this->loadAccount($address);
			} catch (XdagException $ex) {
				continue;
			}

			if ($account) {
				$address = str_replace('_', '/', $address);
				yield $address => $account;
			}
		}

		closedir($dir);
	}

	protected function loadAccount($address)
	{
		$address = str_replace('/', '_', $address);
		if (!preg_match('/^[0-9a-z_+]{32}$/siu', $address))
			throw new \InvalidArgumentException('Account address "' . $address . '" is invalid.');

		$file = __ROOT__ . '/storage/accounts/' . $address . '.json';
		if (!@file_exists($file))
			return null;

		$account = @file_get_contents($file);
		if (!$account)
			throw new XdagException('Unable to load account "' . $address . '".');

		$account = @json_decode($account, true);
		if (!$account)
			throw new XdagException('Unable to decode account "' . $address . '" into json.');

		return $account;
	}

	protected function saveAccount($address, $account, $replace = false)
	{
		$address = str_replace('/', '_', $address);
		if (!preg_match('/^[0-9a-z_+]{32}$/siu', $address))
			throw new \InvalidArgumentException('Account address "' . $address . '" is invalid.');

		$file = __ROOT__ . '/storage/accounts/' . $address . '.json';
		if (@file_exists($file) && !$replace)
			return;

		$account = @json_encode($account, JSON_PRETTY_PRINT);
		if (!$account)
			throw new XdagException('Unable to encode account "' . $address . '" as json.');

		if (!@file_put_contents($file, $account))
			throw new XdagException('Unable to save account "' . $address . '".');
	}
}

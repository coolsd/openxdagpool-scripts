<?php

namespace App\Xdag;

use App\Xdag\Exceptions\{XdagException, XdagBlockNotFoundException};
use App\Support\ExclusiveLock;

class Accounts
{
	protected $config, $get_accounts, $get_block;
	protected $accounts;

	public function __construct(array $config, callable $get_accounts, callable $get_block)
	{
		$this->config = $config;
		$this->get_accounts = $get_accounts;
		$this->get_block = $get_block;
	}

	public function gather($all = false)
	{
		$lock = new ExclusiveLock('accounts', 300);
		$lock->obtain();

		foreach (($this->get_accounts)(($this->load() || $all) ? 10000000000 : 100) as $line) {
			$line = preg_split('/\s+/', trim($line));

			if (count($line) !== 4)
				continue;

			if (!isset($this->accounts[$line[0]]))
				$this->accounts[$line[0]] = [
					'hash' => null,
					'payouts_sum' => 0,
					'first_inspected_at' => null,
					'inspected_times' => 0,
					'found_at' => null,
					'exported_at' => null,
					'invalidated_at' => null,
					'invalidated_exported_at' => null,
				];
		}

		$this->persist();
		$lock->release();
	}

	public function inspect($all = false)
	{
		$lock = new ExclusiveLock('accounts', 300);
		$lock->obtain();

		$this->load();
		$date_threshold = date('Y-m-d H:i:s', strtotime('-10 days'));

		foreach ($this->accounts as $address => $account) {
			if ($all || (!$account['invalidated_at'] && ($account['inspected_times'] < 3 || !$account['hash']))) {
				if ($account['first_inspected_at'] && $account['first_inspected_at'] < $date_threshold)
					continue; // account was processed enough times, skip processing to conserve resources

				if (!$this->accounts[$address]['first_inspected_at'])
					$this->accounts[$address]['first_inspected_at'] = date('Y-m-d H:i:s');

				try {
					$block = ($this->get_block)($address);
				} catch (XdagBlockNotFoundException $ex) {
					$this->invalidateAccount($address);
					continue;
				}

				if (!$block->hasEarning()) {
					$this->invalidateAccount($address);
					continue;
				}

				if ($this->accounts[$address]['invalidated_at']) {
					$this->accounts[$address]['invalidated_at'] = $this->accounts[$address]['invalidated_exported_at'] = null;
					$this->accounts[$address]['exported_at'] = null;
					$this->accounts[$address]['hash'] = $this->accounts[$address]['found_at'] = null;
					$this->accounts[$address]['inspected_times'] = 0;
				}

				if (!$block->isPaidOut())
					continue;

				$this->accounts[$address]['inspected_times']++;
				$this->accounts[$address]['found_at'] = $block->getProperty('time');
				$this->accounts[$address]['hash'] = $block->getProperty('hash');

				if (($sum = $block->getPayoutsSum()) != $this->accounts[$address]['payouts_sum'])
					$this->accounts[$address]['exported_at'] = null;

				$this->accounts[$address]['payouts_sum'] = $sum;
			}
		}

		$this->persist();
		$lock->release();
	}

	public function resetExport()
	{
		$lock = new ExclusiveLock('accounts', 300);
		$lock->obtain();

		$this->load();

		foreach ($this->accounts as $address => $account) {
			if (!$account['invalidated_at'] && $account['exported_at']) {
				$this->accounts[$address]['exported_at'] = null;
			}
		}

		$this->persist();
		$lock->release();
	}

	public function resetExportInvalidated()
	{
		$lock = new ExclusiveLock('accounts', 300);
		$lock->obtain();

		$this->load();

		foreach ($this->accounts as $address => $account) {
			if ($account['invalidated_at'] && $account['invalidated_exported_at']) {
				$this->accounts[$address]['invalidated_exported_at'] = null;
			}
		}

		$this->persist();
		$lock->release();
	}

	public function exportBlock()
	{
		$lock = new ExclusiveLock('accounts', 300);
		$lock->obtain();

		$this->load();

		$export_address = null;
		foreach ($this->accounts as $address => $account) {
			if (!$account['exported_at'] && $account['inspected_times'] >= 3 && $account['hash'] && !$account['invalidated_at']) {
				if (!$export_address)
					$export_address = $address;
				else if ($account['found_at'] < $export['found_at'])
					$export_address = $address;
			}
		}

		if ($export_address) {
			$block = new Block();
			$json = $block->load($this->accounts[$export_address]['hash']);
			$this->accounts[$export_address]['exported_at'] = date('Y-m-d H:i:s');
			$this->persist();
			$lock->release();
			return $json;
		}

		$lock->release();
		return false;
	}

	public function exportBlockInvalidated()
	{
		$lock = new ExclusiveLock('accounts', 300);
		$lock->obtain();

		$this->load();

		foreach ($this->accounts as $address => $account) {
			if ($account['hash'] && $account['invalidated_at'] && !$account['invalidated_exported_at']) {
				$this->accounts[$address]['invalidated_exported_at'] = date('Y-m-d H:i:s');
				$this->persist();
				$lock->release();
				return json_encode(['invalidateBlock' => $account['hash']]);
			}
		}

		$lock->release();
		return false;
	}

	protected function invalidateAccount($address)
	{
		if ($this->accounts[$address]['exported_at'])
			$this->accounts[$address]['invalidated_at'] = date('Y-m-d H:i:s');
		else
			$this->accounts[$address]['invalidated_at'] = $this->accounts[$address]['invalidated_exported_at'] = date('Y-m-d H:i:s');

		// do we have block associated with this account?
		if ($this->accounts[$address]['hash']) {
			$block = new Block();

			try {
				$block->load($this->accounts[$address]['hash']);
			} catch (XdagException $ex) {
				return;
			}

			$block->remove();
		}
	}

	protected function persist()
	{
		$file = __ROOT__ . '/storage/accounts.json';
		$data = @json_encode($this->accounts, JSON_PRETTY_PRINT);

		if ($data === false || @file_put_contents($file, $data) === false)
			throw new XdagException('Unable to persist accounts.');
	}

	protected function load()
	{
		$file = __ROOT__ . '/storage/accounts.json';
		$fresh_setup = false;

		if (!file_exists($file)) {
			$fresh_setup = true;
			$this->setup();
		}

		$data = @file_get_contents($file);

		if ($data === false)
			throw new XdagException('Unable to load accounts.');

		$this->accounts = @json_decode($data, true);
		if ($this->accounts === false) {
			$this->accounts = [];
			throw new XdagException('Unable to parse stored accounts.');
		}

		return $fresh_setup;
	}

	protected function setup()
	{
		$file = __ROOT__ . '/storage/accounts.json';
		$this->accounts = [];

		if (isset($this->config['extra_accounts']) && is_array($this->config['extra_accounts'])) {
			foreach ($this->config['extra_accounts'] as $account) {
				$this->accounts[$account] = [
					'hash' => null,
					'payouts_sum' => 0,
					'first_inspected_at' => null,
					'inspected_times' => 0,
					'found_at' => null,
					'exported_at' => null,
					'invalidated_at' => null,
					'invalidated_exported_at' => null,
				];
			}
		}

		$this->persist();
	}
}

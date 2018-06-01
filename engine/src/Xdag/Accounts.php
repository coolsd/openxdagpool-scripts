<?php

namespace App\Xdag;

use App\Xdag\Exceptions\{XdagException, XdagBlockNotFoundException};
use App\Support\ExclusiveLock;
use mysqli;

class Accounts
{
	protected $config, $get_accounts, $get_block, $mysql;

	public function __construct(array $config, callable $get_accounts, callable $get_block)
	{
		$this->config = $config;
		$this->get_accounts = $get_accounts;
		$this->get_block = $get_block;

		if (!extension_loaded('mysqli'))
			throw new AccountsException('The mysqli extension is required.');

		$this->mysql = @new mysqli($config['db']['host'] ?? 'localhost', $config['db']['user'] ?? 'root', $config['db']['pass'] ?? '', $config['db']['db'] ?? 'scripts');
		if ($this->mysql->connect_error)
			throw new AccountsException($this->mysql->connect_errno . ': ' . $this->mysql->connect_error);
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

		foreach ($this->accounts($all ? '' : 'invalidated_at IS NULL AND (inspected_times < 3 OR hash IS NULL)') as $address => $account) {
			if ($account['first_inspected_at'] && $account['first_inspected_at'] < $date_threshold)
				continue; // account was processed enough times, skip processing to conserve resources

			if ($account['last_inspected_at'] && $account['last_inspected_at'] > date('Y-m-d H:i:s', strtotime('-10 minutes')) && (!$account['found_at'] || $account['found_at'] > date('Y-m-d H:i:s', strtotime('-1 day')) . '000'))
				continue; // do not inspect recent accounts too often, give pool a chance to pay out the miner

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

		$lock->release();
	}

	public function resetExport()
	{
		$lock = new ExclusiveLock('accounts_process', 300);
		$lock->obtain();

		$this->runInsertOrUpdate('UPDATE accounts SET exported_at = NULL WHERE invalidated_at IS NULL AND exported_at IS NOT NULL');

		$lock->release();
	}

	public function resetExportInvalidated()
	{
		$lock = new ExclusiveLock('accounts_process', 300);
		$lock->obtain();

		$this->runInsertOrUpdate('UPDATE accounts SET invalidated_exported_at = NULL WHERE exported_at IS NOT NULL AND invalidated_at IS NOT NULL AND invalidated_exported_at IS NOT NULL');

		$lock->release();
	}

	public function exportBlock()
	{
		$lock = new ExclusiveLock('accounts_process', 300);
		$lock->obtain();

		$export_address = $export_account = null;
		foreach ($this->accounts('exported_at IS NULL AND inspected_times >= 3 AND hash IS NOT NULL AND invalidated_at IS NULL ORDER BY found_at LIMIT 1') as $address => $account) {
			$block = new Block();
			$json = $block->load($account['hash']);
			$account['exported_at'] = date('Y-m-d H:i:s');
			$this->saveAccount($address, $account, true);
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

		foreach ($this->accounts('hash IS NOT NULL AND invalidated_at IS NOT NULL AND invalidated_exported_at IS NULL LIMIT 1') as $address => $account) {
			$account['invalidated_exported_at'] = date('Y-m-d H:i:s');
			$this->saveAccount($address, $account, true);
			$lock->release();
			return json_encode(['invalidateBlock' => $account['hash']]);
		}

		$lock->release();
		return false;
	}

	public function summary()
	{
		return [
			'not_fully_inspected' => $this->getRowCount('SELECT COUNT(*) FROM accounts WHERE invalidated_at IS NULL AND inspected_times < 3 AND hash IS NULL'),
			'to_be_exported' => $this->getRowCount('SELECT COUNT(*) FROM accounts WHERE exported_at IS NULL AND inspected_times >= 3 AND hash IS NOT NULL AND invalidated_at IS NULL'),
			'to_be_exported_invalidated' => $this->getRowCount('SELECT COUNT(*) FROM accounts WHERE hash IS NOT NULL AND invalidated_at IS NOT NULL AND invalidated_exported_at IS NULL'),
			'valid' => $this->getRowCount('SELECT COUNT(*) FROM accounts WHERE hash IS NOT NULL'),
			'invalid' => $this->getRowCount('SELECT COUNT(*) FROM accounts WHERE invalidated_at IS NOT NULL'),
			'total' => $this->getRowCount('SELECT COUNT(*) FROM accounts'),
		];
	}

	public function truncate()
	{
		return $this->mysql->query('TRUNCATE accounts');
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

	protected function isFreshInstall()
	{
		$result = $this->runSelect('SELECT id FROM accounts LIMIT 1');
		return ! (boolean) $result->num_rows;
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

	protected function accounts($sql = null)
	{
		$query = 'SELECT
			address, hash, payouts_sum, first_inspected_at, last_inspected_at, inspected_times,
			found_at, exported_at, invalidated_at, invalidated_exported_at
		FROM accounts';

		if ($sql)
			$query .= ' WHERE ' . $sql;

		$result = $this->runSelect($query);
		while ($account = $result->fetch_assoc()) {
			$address = $account['address'];
			unset($account['address']);

			yield $address => $account;
		}
	}

	protected function saveAccount($address, $account, $replace = false)
	{
		if (!preg_match('/^[0-9a-z\/+]{32}$/siu', $address))
			throw new \InvalidArgumentException('Account address "' . $address . '" is invalid.');

		if ($replace)
			$query = 'UPDATE accounts SET ' . $this->accountToQuery($account) . " WHERE address = '" . $this->escape($address) . "' LIMIT 1";
		else
			$query = "INSERT INTO accounts SET address = '" . $this->escape($address) . "', " . $this->accountToQuery($account);

		try {
			return $this->runInsertOrUpdate($query);
		} catch (QueryException $ex) {
			if (!$replace && $this->mysql->errno == 1062) // duplicate entry, expected
				return true;

			throw $ex;
		}
	}

	protected function escape($value)
	{
		return $this->mysql->real_escape_string($value);
	}

	protected function accountToQuery(array $account)
	{
		$keys = ['hash', 'payouts_sum', 'first_inspected_at', 'last_inspected_at', 'inspected_times', 'found_at', 'exported_at', 'invalidated_at', 'invalidated_exported_at'];
		$parts = [];

		foreach ($account as $key => $value) {
			if (in_array($key, $keys)) {
				if ($value === null)
					$parts[] = $key . ' = NULL';
				else
					$parts[] = $key . " = '" . $this->escape($value) . "'";
			}
		}

		return implode(', ', $parts);
	}

	protected function runSelect($query)
	{
		$result = $this->mysql->query($query);

		if ($result === false)
			throw new QueryException('Query "' . $query . '" failed. ' . $this->mysql->errno . ': ' . $this->mysql->error);

		return $result;
	}

	protected function runInsertOrUpdate($query)
	{
		$result = $this->mysql->query($query);

		if ($result === false)
			throw new QueryException('Query "' . $query . '" failed. ' . $this->mysql->errno . ': ' . $this->mysql->error);

		return $result;
	}

	protected function getRowCount($query)
	{
		$result = $this->runSelect($query);
		$result = $result->fetch_row();
		return $result === null ? 0 : $result[0];
	}
}

class AccountsException extends \Exception {}
class QueryException extends \Exception {}

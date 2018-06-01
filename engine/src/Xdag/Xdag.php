<?php

namespace App\Xdag;

use App\Xdag\Exceptions\{XdagException, XdagBlockNotFoundException, XdagNodeNotReadyException};

class Xdag
{
	protected $socket_file;

	public function __construct($socket_file)
	{
		if (!extension_loaded('sockets'))
			throw new XdagException('Sockets extension not loaded.');

		$this->socket_file = $socket_file;
	}

	public function isReady()
	{
		$state = $this->getState();
		return stripos($state, 'normal operation') !== false || stripos($state, 'transfer to complete') !== false;
	}

	public function isAddress($address)
	{
		return preg_match('/^[a-zA-Z0-9\/+]{32}$/', $address);
	}

	public function isBlockHash($hash)
	{
		return preg_match('/^[a-f0-9]{64}$/', $hash);
	}

	public function isBlockCommandParameter($parameter)
	{
		return $this->isAddress($parameter) || $this->isBlockHash($parameter);
	}

	public function getState()
	{
		return $this->command('state');
	}

	public function getVersion()
	{
		$file = str_replace('"', '\"', dirname($this->socket_file) . '/xdag');
		exec('"' . $file . '"', $out);

		if (!$out)
			return '???';

		$line = current($out);
		$line = preg_split('/\s+/', trim($line));
		return rtrim(end($line), '.');
	}

	public function getAccounts($number = 100)
	{
		if (!$this->isReady())
			throw new XdagNodeNotReadyException;

		return $this->commandStream('account ' . max(1, intval($number)));
	}

	public function getLastBlocks($number = 100)
	{
		return $this->commandStream('lastblocks ' . min(100, max(1, intval($number))));
	}

	public function getBalance($address)
	{
		if (!$this->isAddress($address))
			throw new \InvalidArgumentException('Invalid address.');

		if (!$this->isReady())
			throw new XdagNodeNotReadyException;

		$output = $this->command("balance $address");
		$output = explode(' ', $output);

		return $output[1] ?? '0.000000000';
	}

	public function getPoolConfig()
	{
		$output = $this->command('pool');
		$output = explode(': ', $output);

		$config = [
			'max_conn' => 0,
			'max_ip' => 0,
			'max_addr' => 0,
			'fee' => 0,
			'reward' => 0,
			'direct' => 0,
			'fund' => 0,
		];

		if (count($output) != 2)
			return $config;

		$output = explode(':', $output[1]);

		if (count($output) == 7) {
			// 0.2.2 and later
			$config = [
				'max_conn' => (int) $output[0],
				'max_ip' => (int) $output[1],
				'max_addr' => (int) $output[2],
				'fee' => (float) $output[3],
				'reward' => (float) $output[4],
				'direct' => (float) $output[5],
				'fund' => (float) $output[6],
			];
		} else if (count($output) == 6) {
			// < 0.2.2
			$config = [
				'max_conn' => (int) $output[0],
				'max_ip' => (int) $output[5],
				'max_addr' => (int) $output[5],
				'fee' => (float) $output[1],
				'reward' => (float) $output[2],
				'direct' => (float) $output[3],
				'fund' => (float) $output[4],
			];
		}

		return $config;
	}

	public function getConnections()
	{
		$connections = [];
		foreach ($this->commandStream('net conn') as $line) {
			$line = preg_split('/\s+/', trim($line));

			if (count($line) != 11)
				continue;

			$connections[] = [
				'host' => $line[1],
				'seconds' => (int) $line[2],
				'in_out_bytes' => array_map('intval', explode('/', $line[4])),
				'in_out_packets' => array_map('intval', explode('/', $line[7])),
				'in_out_dropped' => array_map('intval', explode('/', $line[9])),
			];
		}

		return $connections;
	}

	public function parseBlock($input)
	{
		if (!$this->isBlockCommandParameter($input))
			throw new \InvalidArgumentException('Invalid address or block hash.');

		if (!$this->isReady())
			throw new XdagNodeNotReadyException;

		$block = new Block();
		$block->parse($this->commandStream("block $input"), [$this, 'parseBlock']);

		return $block;
	}

	public function getStats()
	{
		$stats = [];

		foreach ($this->commandStream('stats') as $line) {
			if (preg_match('/\s*(.*): (.*)/i', $line, $matches)) {
				$key = strtolower(trim($matches[1]));
				$values = explode(' of ', $raw_value = strtolower(trim($matches[2])));

				if (count($values) == 2) {
					foreach ($values as $i => $value)
						if (preg_match('/^[0-9]+$/', $value))
							$values[$i] = (int) $value;
						else if (is_numeric($value))
							$values[$i] = (float) $value;

					$stats[str_replace(' ', '_', $key)] = $values;

					if (strpos($key, 'hashrate') !== false && !isset($stats['hashrate']))
						$stats['hashrate'] = [$values[0] * 1024 * 1024, $values[1] * 1024 * 1024];
				} else {
					if (preg_match('/^[0-9]+$/', $raw_value))
						$raw_value = (int) $raw_value;
					else if (is_numeric($raw_value))
						$raw_value = (float) $raw_value;

					$stats[str_replace(' ', '_', $key)] = $raw_value;
				}
			}
		}

		return $stats;
	}

	public function getMiners()
	{
		$miners = [];
		$last_miner = null;

		foreach ($this->commandStream('miners') as $line) {
			$parts = preg_split('/\s+/siu', trim($line));

			if (count($parts) !== 6)
				continue;

			if ($parts[0] === '-1.')
				continue;

			if (!preg_match('/^C?[0-9]+\.$/siu', $parts[0]))
				continue;

			if ($last_miner && $parts[0][0] === 'C') {
				$parts[1] = $last_miner[1]; // replace miner's address from last active miner entry
				$parts[2] = $last_miner[2]; // replace miner's state from last active miner entry
				$parts[5] = $last_miner[5]; // replace miner's unpaid shares with value from last active miner entry
				$last_miner[5] = 0; // replace unpaid shares only for first connection, treat all other connections as zero unpaid shares (sum => vallue from last active miner entry)
			} else {
				$last_miner = $parts; // store currently processed miner entry
			}

			// in new miners output, IP and IN/OUT information is lost when miner disconnects. Replace with placeholder values.
			if ($parts[2] !== 'active' && $parts[3] === '-') {
				$parts[3] = '0.0.0.0:0';
				$parts[4] = '0/0';
			}

			// in new miners output, skip the first "active" miner line, and use only "C" lines - miner's connections
			// this check will succeed only for active miners in new output - we don't replace IP and IN/OUT bytes
			// in the condition above for miners in 'active' state
			if ($parts[3] !== '-')
				$miners[] = [
					'address' => $parts[1],
					'status' => $parts[2],
					'ip_and_port' => $parts[3],
					'in_out_bytes' => array_map('intval', explode('/', $parts[4])),
					'unpaid_shares' => (float) $parts[5],
				];
		};

		return $miners;
	}

	public function command($cmd)
	{
		$lines = [];
		foreach ($this->commandStream($cmd) as $line)
			$lines[] = $line;

		return implode("\n", $lines);
	}

	public function commandStream($cmd)
	{
		$socket = socket_create(AF_UNIX, SOCK_STREAM, 0);

		if (!$socket || !socket_connect($socket, $this->socket_file))
			throw new XdagException('Error establishing a connection with the socket');

		$command = "$cmd\0";
		socket_send($socket, $command, strlen($command), 0);

		while ($line = @socket_read($socket, 1024, PHP_NORMAL_READ))
			yield rtrim($line, "\n");

		socket_close($socket);
	}
}

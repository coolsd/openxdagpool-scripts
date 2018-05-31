<?php

namespace App\Controllers;

use App\Xdag\Accounts;
use App\Xdag\Exceptions\XdagNodeNotReadyException;
use App\Support\UnableToObtainLockException;

class BlocksController extends Controller
{
	protected $accounts;

	public function index($action = null)
	{
		$this->accounts = new Accounts($this->config, [$this->xdag, 'getAccounts'], [$this->xdag, 'parseBlock']);

		if ($action == 'process')
			return $this->process();
		else if ($action == 'processAll')
			return $this->process(true);
		else if ($action == 'export')
			return $this->export();
		else if ($action == 'exportInvalidated')
			return $this->exportInvalidated();
		else if ($action == 'resetExport')
			return $this->resetExport();
		else if ($action == 'resetExportInvalidated')
			return $this->resetExportInvalidated();
		else if ($action == 'summary')
			return $this->summary();
		else if ($action == 'startFresh')
			return $this->startFresh();

		return $this->responseJson(['result' => 'invalid-call', 'message' => 'Invalid blocks operation call.']);
	}

	protected function process($all = false)
	{
		try {
			$this->accounts->setup();
			$this->accounts->gather($all);
			$this->accounts->inspect($all);
		} catch (XdagNodeNotReadyException $ex) {
			$this->responseJson(['result' => 'not-ready', 'message' => 'Node is not ready at this time, blocks operation is not available.']);
		} catch (UnableToObtainLockException $ex) {
			$this->responseJson(['result' => 'locked', 'message' => 'Blocks operation is currently in progress, please try again later.']);
		}
	}

	// exports one oldest unexported fully processed and verified account (found block)
	protected function export()
	{
		if ($json = $this->accounts->exportBlock())
			return $this->response($json);

		return $this->responseJson(['result' => 'empty', 'message' => 'No new blocks.']);
	}

	// if a block was invalidated later, export one unexported invalidated found block
	protected function exportInvalidated()
	{
		if ($json = $this->accounts->exportBlockInvalidated())
			return $this->response($json);

		return $this->responseJson(['result' => 'empty', 'message' => 'No new invalidated blocks.']);
	}

	protected function resetExport()
	{
		$this->accounts->resetExport();
		return $this->responseJson(['result' => 'success', 'message' => 'All blocks will be exported again on export calls.']);
	}

	protected function resetExportInvalidated()
	{
		$this->accounts->resetExportInvalidated();
		return $this->responseJson(['result' => 'success', 'message' => 'All invalidated blocks will be exported again on exportInvalidated calls.']);
	}

	protected function summary()
	{
		return $this->responseJson($this->accounts->summary());
	}

	protected function startFresh()
	{
		// remove accounts
		$dir = __ROOT__ . '/storage/accounts/';
		$dirh = opendir($dir);

		while (($file = readdir($dirh)) !== false) {
			if (!preg_match('/^[0-9a-z_+]{32}\.json$/siu', $file))
				continue;

			@unlink($dir . $file);
		}

		closedir($dirh);

		// remove blocks
		$dir = __ROOT__ . '/storage/blocks/';
		$dirh = opendir($dir);

		while (($file = readdir($dirh)) !== false) {
			if (!preg_match('/^[0-9a-f]{64}\.json$/siu', $file))
				continue;

			@unlink($dir . $file);
		}

		closedir($dirh);

		return $this->responseJson(['result' => 'success', 'message' => 'Core storage was deleted.']);
	}
}

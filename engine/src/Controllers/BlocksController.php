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

		return $this->responseJson(['result' => 'invalid-call', 'message' => 'Invalid blocks operation call.']);
	}

	protected function process($all = false)
	{
		try {
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
}

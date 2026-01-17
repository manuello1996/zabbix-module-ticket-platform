<?php declare(strict_types = 0);
/*
** Zabbix
** Copyright (C) 2001-2025 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
**/


namespace Modules\TicketPlatform\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Exception;
use Modules\TicketPlatform\Includes\Config;
use Modules\TicketPlatform\Includes\LocalApi;
use Modules\TicketPlatform\Includes\RemoteApi;

class TicketPlatformItemPopup extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'server_id' => 'required',
			'itemid' => 'required'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseData([
				'error' => [
					'title' => _('Cannot load item details'),
					'messages' => array_column(get_and_clear_messages(), 'message')
				]
			]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return CWebUser::getType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$server = $this->getServer((string) $this->getInput('server_id', ''));
		$itemid = (string) $this->getInput('itemid', '');

		if ($server === null || $itemid === '') {
			$this->setResponse(new CControllerResponseData([
				'error' => [
					'title' => _('Cannot load item details'),
					'messages' => [_('No remote server or item specified.')]
				]
			]));
			return;
		}

		try {
			$item = $this->getItem($server, $itemid);

			$response = new CControllerResponseData([
				'action' => $this->getAction(),
				'server' => $server,
				'item' => $item
			]);
			$response->setTitle(_('Item'));

			$this->setResponse($response);
		}
		catch (Exception $e) {
			$this->setResponse(new CControllerResponseData([
				'error' => [
					'title' => _('Cannot load item details'),
					'messages' => [$e->getMessage()]
				]
			]));
		}
	}

	private function getServer(string $server_id): ?array {
		$config = Config::get();
		$servers = $this->addLocalServer($config['servers']);

		foreach ($servers as $server) {
			if ($server['id'] === $server_id) {
				return $server;
			}
		}

		return null;
	}

	private function getItem(array $server, string $itemid): array {
		$items = $this->callApi($server, 'item.get', [
			'output' => 'extend',
			'itemids' => [$itemid],
			'selectHosts' => ['hostid', 'name', 'host'],
			'selectTags' => ['tag', 'value'],
			'selectValueMap' => ['valuemapid', 'name'],
			'selectPreprocessing' => ['type', 'params', 'error_handler', 'error_handler_params']
		]);

		if (!$items) {
			throw new Exception(_('No permissions to referred object or it does not exist!'));
		}

		$item = $items[0];
		if (array_key_exists('preprocessing', $item) && is_array($item['preprocessing'])) {
			foreach ($item['preprocessing'] as &$step) {
				if (array_key_exists('params', $step) && is_string($step['params'])) {
					$step['params'] = $step['params'] === '' ? [''] : explode("\n", $step['params']);
				}
			}
			unset($step);
		}

		return $item;
	}

	private function callApi(array $server, string $method, array $params): array {
		if (!empty($server['is_local'])) {
			return LocalApi::call($method, $params);
		}

		return RemoteApi::call($server['api_url'], $server['api_token'], $method, $params);
	}

	private function addLocalServer(array $servers): array {
		$servers[] = [
			'id' => 'local',
			'name' => _('Local server'),
			'api_url' => '',
			'api_token' => '',
			'hostgroup' => '',
			'include_subgroups' => 1,
			'enabled' => 1,
			'is_local' => true
		];

		return $servers;
	}
}

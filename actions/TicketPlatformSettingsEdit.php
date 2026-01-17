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
use CControllerResponseFatal;
use CControllerResponseRedirect;
use CUrl;
use CWebUser;
use Exception;
use Modules\TicketPlatform\Includes\Config;
use Modules\TicketPlatform\Includes\RemoteApi;

class TicketPlatformSettingsEdit extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'server_id' => 'string',
			'save' => 'string',
			'name' => 'string',
			'api_url' => 'string',
			'api_token' => 'string',
			'hostgroup' => 'string',
			'include_subgroups' => 'in 0,1',
			'enabled' => 'in 0,1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return CWebUser::getType() >= USER_TYPE_ZABBIX_ADMIN;
	}

	protected function doAction(): void {
		$config = Config::get();
		$server_id = $this->getInput('server_id', '');

		if ($this->hasInput('save')) {
			$name = trim($this->getInput('name', ''));
			$api_url = trim($this->getInput('api_url', ''));
			if ($api_url !== '' && stripos($api_url, 'api_jsonrpc.php') === false) {
				$api_url = rtrim($api_url, '/').'/api_jsonrpc.php';
			}
			$api_token = trim($this->getInput('api_token', ''));
			$hostgroup = trim($this->getInput('hostgroup', ''));
			$include_subgroups = (int) $this->getInput('include_subgroups', 0);
			$enabled = (int) $this->getInput('enabled', 0);
			$api_version = '';

			$existing_server = null;
			foreach ($config['servers'] as $existing) {
				if ($existing['id'] === $server_id) {
					$existing_server = $existing;
					break;
				}
			}

			if ($api_token === '' && $existing_server !== null) {
				$api_token = $existing_server['api_token'];
			}

			if ($api_url === '') {
				$this->setErrorResponse(_('Cannot save server'), [_('API URL is required.')], [
					'id' => $server_id,
					'name' => $name,
					'api_url' => $api_url,
					'api_token' => $api_token,
					'hostgroup' => $hostgroup,
					'include_subgroups' => $include_subgroups,
					'enabled' => $enabled,
					'api_version' => $existing_server['api_version'] ?? ''
				]);
				return;
			}

			try {
				$api_version = RemoteApi::callNoAuth($api_url, 'apiinfo.version', []);
				if (!is_string($api_version) || $api_version === '') {
					throw new Exception(_('Invalid API version response.'));
				}
			}
			catch (Exception $e) {
				$this->setErrorResponse(_('Cannot save server'), [$e->getMessage()], [
					'id' => $server_id,
					'name' => $name,
					'api_url' => $api_url,
					'api_token' => $api_token,
					'hostgroup' => $hostgroup,
					'include_subgroups' => $include_subgroups,
					'enabled' => $enabled,
					'api_version' => $existing_server['api_version'] ?? ''
				]);
				return;
			}

			$server = [
				'id' => $server_id !== '' ? $server_id : bin2hex(random_bytes(8)),
				'name' => $name,
				'api_url' => $api_url,
				'api_token' => $api_token,
				'hostgroup' => $hostgroup,
				'include_subgroups' => $include_subgroups,
				'enabled' => $enabled,
				'api_version' => $api_version,
				'connection_status' => 'ok',
				'last_reached' => time()
			];

			$updated = false;
			foreach ($config['servers'] as $index => $existing) {
				if ($existing['id'] === $server['id']) {
					$config['servers'][$index] = $server;
					$updated = true;
					break;
				}
			}

			if (!$updated) {
				$config['servers'][] = $server;
			}

			Config::save($config);

			$this->setResponse(new CControllerResponseRedirect(
				(new CUrl('zabbix.php'))->setArgument('action', 'ticket.platform.settings')
			));
			return;
		}

		$server = [
			'id' => '',
			'name' => '',
			'api_url' => '',
			'api_token' => '',
			'hostgroup' => '',
			'include_subgroups' => 1,
			'enabled' => 1,
			'api_version' => '',
			'connection_status' => '',
			'last_reached' => 0
		];

		foreach ($config['servers'] as $existing) {
			if ($existing['id'] === $server_id) {
				$server = $existing;
				break;
			}
		}

		$response = new CControllerResponseData([
			'action' => $this->getAction(),
			'server' => $server
		]);
		$response->setTitle(_('Ticket Platform settings'));

		$this->setResponse($response);
	}

	private function setErrorResponse(string $title, array $messages, array $server): void {
		$this->setResponse(new CControllerResponseData([
			'action' => $this->getAction(),
			'server' => $server,
			'error' => [
				'title' => $title,
				'messages' => $messages
			]
		]));
	}
}

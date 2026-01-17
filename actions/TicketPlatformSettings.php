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
use CControllerResponseRedirect;
use CUrl;
use CWebUser;
use Exception;
use Modules\TicketPlatform\Includes\Cache;
use Modules\TicketPlatform\Includes\Config;
use Modules\TicketPlatform\Includes\RemoteApi;

class TicketPlatformSettings extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'save_settings' => 'string',
			'cache_ttl' => 'int32',
			'local_server_name' => 'string',
			'reset_cache_id' => 'string',
			'delete_id' => 'string',
			'check_connection_id' => 'string'
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
		$message = null;
		$message_type = null;
		$message_title = null;

		if ($this->hasInput('save_settings')) {
			$config['cache_ttl'] = max(5, (int) $this->getInput('cache_ttl', 60));
			$local_name = trim($this->getInput('local_server_name', ''));
			$config['local_server_name'] = $local_name !== '' ? $local_name : 'Local server';
			Config::save($config);

			$this->setResponse(new CControllerResponseRedirect(
				(new CUrl('zabbix.php'))->setArgument('action', $this->getAction())
			));
			return;
		}

		if ($this->hasInput('delete_id')) {
			$delete_id = $this->getInput('delete_id');
			$config['servers'] = array_values(array_filter($config['servers'], function ($server) use ($delete_id) {
				return $server['id'] !== $delete_id;
			}));

			Config::save($config);

			$this->setResponse(new CControllerResponseRedirect(
				(new CUrl('zabbix.php'))->setArgument('action', $this->getAction())
			));
			return;
		}

		if ($this->hasInput('reset_cache_id')) {
			$reset_id = $this->getInput('reset_cache_id');
			Cache::clearServer($reset_id);

			$this->setResponse(new CControllerResponseRedirect(
				(new CUrl('zabbix.php'))->setArgument('action', $this->getAction())
			));
			return;
		}

		if ($this->hasInput('check_connection_id')) {
			$check_id = $this->getInput('check_connection_id');
			$server_index = null;
			foreach ($config['servers'] as $index => $server) {
				if ($server['id'] === $check_id) {
					$server_index = $index;
					break;
				}
			}

			if ($server_index === null) {
				$message_type = ZBX_STYLE_MSG_BAD;
				$message_title = _('Connection check failed');
				$message = [_('No remote server specified.')];
			}
			else {
				$server = $config['servers'][$server_index];
				if (trim($server['api_url']) === '') {
					$message_type = ZBX_STYLE_MSG_BAD;
					$message_title = _('Connection check failed');
					$message = [_('API URL is required.')];
				}
				else {
					try {
						$api_version = RemoteApi::callNoAuth($server['api_url'], 'apiinfo.version', []);
						if (!is_string($api_version) || $api_version === '') {
							throw new Exception(_('Invalid API version response.'));
						}
						RemoteApi::call($server['api_url'], $server['api_token'], 'user.get', [
							'output' => ['userid', 'username', 'roleid', 'status'],
							'limit' => 1
						]);

						$config['servers'][$server_index]['api_version'] = $api_version;
						$config['servers'][$server_index]['connection_status'] = 'ok';
						$config['servers'][$server_index]['last_reached'] = time();
						Config::save($config);

						$message_type = ZBX_STYLE_MSG_GOOD;
						$message_title = _('Connection OK');
						$message = [_s('API version: %1$s', $api_version)];
					}
					catch (Exception $e) {
						$config['servers'][$server_index]['connection_status'] = 'problem';
						Config::save($config);

						$message_type = ZBX_STYLE_MSG_BAD;
						$message_title = _('Connection check failed');
						$message = [_s('Server "%1$s": %2$s', $server['name'], $e->getMessage())];
					}
				}
			}
		}

		$response = new CControllerResponseData([
			'action' => $this->getAction(),
			'cache_ttl' => (int) $config['cache_ttl'],
			'local_server_name' => $config['local_server_name'],
			'servers' => $config['servers'],
			'message' => $message,
			'message_type' => $message_type,
			'message_title' => $message_title
		]);
		$response->setTitle(_('Ticket Platform settings'));

		$this->setResponse($response);
	}
}

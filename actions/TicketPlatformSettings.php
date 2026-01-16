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
use Modules\TicketPlatform\Includes\Config;

class TicketPlatformSettings extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'save_settings' => 'string',
			'cache_ttl' => 'int32',
			'delete_id' => 'string'
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

		if ($this->hasInput('save_settings')) {
			$config['cache_ttl'] = max(5, (int) $this->getInput('cache_ttl', 60));
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

		$response = new CControllerResponseData([
			'action' => $this->getAction(),
			'cache_ttl' => (int) $config['cache_ttl'],
			'servers' => $config['servers']
		]);
		$response->setTitle(_('Ticket Platform settings'));

		$this->setResponse($response);
	}
}

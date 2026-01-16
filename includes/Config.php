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


namespace Modules\TicketPlatform\Includes;

use APP;
use Zabbix\Core\CModule;

class Config {
	public const MODULE_ID = 'ticket-platform';

	private const DEFAULT_CONFIG = [
		'servers' => [],
		'cache_ttl' => 60
	];

	public static function getModule(): ?CModule {
		return APP::ModuleManager()->getModule(self::MODULE_ID);
	}

	public static function get(): array {
		$module = self::getModule();

		if ($module === null) {
			return self::DEFAULT_CONFIG;
		}

		$config = $module->getConfig();

		return array_replace(self::DEFAULT_CONFIG, $config);
	}

	public static function save(array $config): void {
		$module = self::getModule();

		if ($module === null) {
			return;
		}

		$module->setConfig($config);
	}
}

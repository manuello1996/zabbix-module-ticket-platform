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

class Cache {
	private const CACHE_FILE = 'zbx_ticket_platform_cache.json';

	public static function makeKey(array $payload): string {
		return sha1(json_encode($payload, JSON_UNESCAPED_SLASHES));
	}

	public static function get(string $server_id, string $key, int $ttl): ?array {
		$cache = self::load();

		if (!array_key_exists('servers', $cache)
				|| !array_key_exists($server_id, $cache['servers'])
				|| !array_key_exists('entries', $cache['servers'][$server_id])
				|| !array_key_exists($key, $cache['servers'][$server_id]['entries'])) {
			return null;
		}

		$entry = $cache['servers'][$server_id]['entries'][$key];
		if (!array_key_exists('ts', $entry) || (time() - $entry['ts']) > $ttl) {
			return null;
		}

		return $entry['payload'] ?? null;
	}

	public static function set(string $server_id, string $key, array $payload): void {
		$cache = self::load();
		$cache['servers'][$server_id]['entries'][$key] = [
			'ts' => time(),
			'payload' => $payload
		];

		self::save($cache);
	}

	public static function clearServer(string $server_id): void {
		$cache = self::load();
		if (array_key_exists('servers', $cache) && array_key_exists($server_id, $cache['servers'])) {
			unset($cache['servers'][$server_id]);
			if (!$cache['servers']) {
				unset($cache['servers']);
			}
			self::save($cache);
		}
	}

	private static function load(): array {
		$path = self::getPath();

		if (!is_file($path)) {
			return [];
		}

		$data = json_decode(file_get_contents($path), true);
		return is_array($data) ? $data : [];
	}

	private static function save(array $data): void {
		file_put_contents(self::getPath(), json_encode($data, JSON_UNESCAPED_SLASHES));
	}

	private static function getPath(): string {
		return sys_get_temp_dir().DIRECTORY_SEPARATOR.self::CACHE_FILE;
	}
}

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

use Exception;
use CSettingsHelper;
use Modules\TicketPlatform\Includes\Config;
use Modules\TicketPlatform\Includes\RemoteApi;
use Modules\TicketPlatform\Includes\LocalApi;

class ProblemHelper {
	public static function fetchProblems(array $servers, array $filter, int $cache_ttl): array {
		$problems = [];
		$errors = [];

		foreach ($servers as $server) {
			if (array_key_exists('enabled', $server) && !$server['enabled']) {
				continue;
			}

			$cache_key = Cache::makeKey([
				'filter' => $filter,
				'server' => [
					'id' => $server['id'],
					'hostgroup' => $server['hostgroup'],
					'include_subgroups' => $server['include_subgroups'],
					'api_version' => $server['api_version'] ?? ''
				]
			]);

			$cached = Cache::get($server['id'], $cache_key, $cache_ttl);
			if ($cached !== null) {
				$problems = array_merge($problems, $cached);
				continue;
			}

			try {
				$api_version = '';
				if (empty($server['is_local'])) {
					$api_version = RemoteApi::callNoAuth($server['api_url'], 'apiinfo.version', []);
					if (!is_string($api_version) || $api_version === '') {
						throw new Exception(_('Invalid API version response.'));
					}
					RemoteApi::call($server['api_url'], $server['api_token'], 'user.get', [
						'output' => ['userid', 'username', 'roleid', 'status'],
						'limit' => 1
					]);
					if (!array_key_exists('api_version', $server) || $server['api_version'] !== $api_version) {
						self::updateServerMeta($server['id'], ['api_version' => $api_version], true);
					}
					$server['api_version'] = $api_version;
				}

				$groupids = self::resolveGroupIds($server);
				$hostids = self::resolveHostIds($server, $filter['host']);
				if ($filter['host'] !== '' && !$hostids) {
					continue;
				}
				$items = [];
				if ($filter['show'] == TRIGGERS_OPTION_ALL) {
					$event_params = self::buildEventParams($filter, $groupids, $hostids);
					$items = self::callApi($server, 'event.get', $event_params);
				}
				else {
					$problem_params = self::buildProblemParams($filter, $groupids, $hostids);
					$items = self::callApi($server, 'problem.get', $problem_params);
				}
				$event_info = self::getEventInfo($server, $items);
				$actions_summary = self::getEventActionsSummary($server, $items);

				$server_problems = [];
				foreach ($items as $item) {
					$eventid = $item['eventid'];
					$event_suppressed = array_key_exists($eventid, $event_info)
						? $event_info[$eventid]['suppressed']
						: null;

					if (!$filter['show_suppressed']
							&& ((array_key_exists('suppressed', $item) && (int) $item['suppressed'] === 1)
								|| $event_suppressed === true)) {
						continue;
					}

					$hosts = array_key_exists($eventid, $event_info)
						? $event_info[$eventid]['hosts']
						: [];
					$objectid = array_key_exists($eventid, $event_info)
						? $event_info[$eventid]['objectid']
						: (array_key_exists('objectid', $item) ? $item['objectid'] : null);
					$actions = array_key_exists($eventid, $actions_summary)
						? $actions_summary[$eventid]
						: ['count' => 0, 'has_uncomplete' => false, 'has_failed' => false];

					$server_problems[] = [
						'eventid' => $eventid,
						'clock' => (int) $item['clock'],
						'severity' => (int) $item['severity'],
						'name' => $item['name'],
						'acknowledged' => (int) $item['acknowledged'],
						'r_eventid' => (int) $item['r_eventid'],
						'tags' => $item['tags'] ?? [],
						'hosts' => $hosts,
						'objectid' => $objectid,
						'server_id' => $server['id'],
						'server_name' => $server['name'],
						'server_url' => empty($server['is_local']) ? RemoteApi::getWebUrl($server['api_url']) : '',
						'actions_count' => (int) $actions['count'],
						'actions_has_uncomplete' => (bool) $actions['has_uncomplete'],
						'actions_has_failed' => (bool) $actions['has_failed']
					];
				}

				if (empty($server['is_local'])) {
					self::updateServerMeta($server['id'], [
						'connection_status' => 'ok',
						'last_reached' => time(),
						'api_version' => $server['api_version'] ?? ''
					], true);
				}

				$cache_key = Cache::makeKey([
					'filter' => $filter,
					'server' => [
						'id' => $server['id'],
						'hostgroup' => $server['hostgroup'],
						'include_subgroups' => $server['include_subgroups'],
						'api_version' => $server['api_version'] ?? ''
					]
				]);

				Cache::set($server['id'], $cache_key, $server_problems);
				$problems = array_merge($problems, $server_problems);
			}
			catch (Exception $e) {
				if (empty($server['is_local'])) {
					self::updateServerMeta($server['id'], ['connection_status' => 'problem'], false);
				}
				$errors[] = [
					'server' => $server['name'],
					'error' => $e->getMessage()
				];
			}
		}

		return [$problems, $errors];
	}

	private static function resolveGroupIds(array $server): array {
		if ($server['hostgroup'] === '' || $server['hostgroup'] === null) {
			return [];
		}

		$params = [
			'output' => ['groupid', 'name'],
			'search' => ['name' => $server['hostgroup']]
		];

		if ($server['include_subgroups']) {
			$params['startSearch'] = true;
		}
		else {
			$params['filter'] = ['name' => $server['hostgroup']];
		}

		$groups = self::callApi($server, 'hostgroup.get', $params);

		return array_values(array_unique(array_column($groups, 'groupid')));
	}

	private static function buildProblemParams(array $filter, array $groupids, array $hostids): array {
		$params = [
			'output' => ['eventid', 'clock', 'severity', 'name', 'acknowledged', 'r_eventid', 'objectid',
				'suppressed'
			],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'sortfield' => ['eventid'],
			'sortorder' => ZBX_SORT_DOWN
		];

		if ($groupids) {
			$params['groupids'] = $groupids;
		}

		if ($hostids) {
			$params['hostids'] = $hostids;
		}

		if ($filter['severities']) {
			$params['severities'] = $filter['severities'];
		}

		if ($filter['name'] !== '') {
			$params['search'] = ['name' => '*'.$filter['name'].'*'];
			$params['searchWildcardsEnabled'] = true;
			$params['searchByAny'] = true;
		}

		if ($filter['acknowledged'] !== null) {
			$params['acknowledged'] = $filter['acknowledged'];
		}

		if (!$filter['show_suppressed']) {
			$params['suppressed'] = false;
		}

		if ($filter['recent'] !== null) {
			$params['recent'] = $filter['recent'];
		}

		if ($filter['time_from'] !== null) {
			$params['time_from'] = $filter['time_from'];
		}

		if ($filter['time_till'] !== null) {
			$params['time_till'] = $filter['time_till'];
		}

		if ($filter['tags']) {
			$params['tags'] = $filter['tags'];
			$params['evaltype'] = $filter['evaltype'];
		}

		if ($filter['limit'] !== null) {
			$params['limit'] = $filter['limit'];
		}

		if ($filter['show_tags']) {
			$params['selectTags'] = ['tag', 'value'];
		}

		return $params;
	}

	private static function buildEventParams(array $filter, array $groupids, array $hostids): array {
		$params = [
			'output' => ['eventid', 'clock', 'severity', 'name', 'acknowledged', 'r_eventid', 'objectid'],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'value' => TRIGGER_VALUE_TRUE,
			'sortfield' => ['eventid'],
			'sortorder' => ZBX_SORT_DOWN
		];

		if ($groupids) {
			$params['groupids'] = $groupids;
		}

		if ($hostids) {
			$params['hostids'] = $hostids;
		}

		if ($filter['severities']) {
			$params['severities'] = $filter['severities'];
		}

		if ($filter['name'] !== '') {
			$params['search'] = ['name' => '*'.$filter['name'].'*'];
			$params['searchWildcardsEnabled'] = true;
			$params['searchByAny'] = true;
		}

		if ($filter['acknowledged'] !== null) {
			$params['acknowledged'] = $filter['acknowledged'];
		}

		if ($filter['time_from'] !== null) {
			$params['time_from'] = $filter['time_from'];
		}

		if ($filter['time_till'] !== null) {
			$params['time_till'] = $filter['time_till'];
		}

		if ($filter['tags']) {
			$params['tags'] = $filter['tags'];
			$params['evaltype'] = $filter['evaltype'];
		}

		if ($filter['limit'] !== null) {
			$params['limit'] = $filter['limit'];
		}

		if ($filter['show_tags']) {
			$params['selectTags'] = ['tag', 'value'];
		}

		return $params;
	}

	private static function getEventInfo(array $server, array $problems): array {
		if (!$problems) {
			return [];
		}

		$eventids = array_column($problems, 'eventid');
		$events = self::callApi($server, 'event.get', [
			'output' => ['eventid', 'objectid'],
			'eventids' => $eventids,
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'selectHosts' => ['hostid', 'name', 'host'],
			'selectAcknowledges' => ['clock', 'action', 'suppress_until']
		]);

		$event_info = [];
		foreach ($events as $event) {
			$hosts = [];
			if (array_key_exists('hosts', $event)) {
				foreach ($event['hosts'] as $host) {
					$hosts[] = [
						'hostid' => $host['hostid'],
						'name' => $host['name'] !== '' ? $host['name'] : $host['host']
					];
				}
			}
			$event_info[$event['eventid']] = [
				'hosts' => $hosts,
				'objectid' => array_key_exists('objectid', $event) ? $event['objectid'] : null,
				'suppressed' => self::isSuppressed($event['acknowledges'] ?? [])
			];
		}

		return $event_info;
	}

	private static function isSuppressed(array $acknowledges): bool {
		if (!$acknowledges) {
			return false;
		}

		usort($acknowledges, function (array $left, array $right): int {
			$left_clock = array_key_exists('clock', $left) ? (int) $left['clock'] : 0;
			$right_clock = array_key_exists('clock', $right) ? (int) $right['clock'] : 0;
			return $right_clock <=> $left_clock;
		});

		foreach ($acknowledges as $ack) {
			if (!array_key_exists('suppress_until', $ack)) {
				continue;
			}

			if (($ack['action'] & ZBX_PROBLEM_UPDATE_UNSUPPRESS) == ZBX_PROBLEM_UPDATE_UNSUPPRESS) {
				return false;
			}
			if (($ack['action'] & ZBX_PROBLEM_UPDATE_SUPPRESS) == ZBX_PROBLEM_UPDATE_SUPPRESS) {
				$suppress_until = (int) $ack['suppress_until'];
				return ($suppress_until == ZBX_PROBLEM_SUPPRESS_TIME_INDEFINITE || $suppress_until > time());
			}
		}

		return false;
	}

	private static function getEventActionsSummary(array $server, array $problems): array {
		if (!$problems) {
			return [];
		}

		$eventids = array_column($problems, 'eventid');
		$r_eventids = array_filter(array_map('intval', array_column($problems, 'r_eventid')),
			function (int $eventid): bool {
				return $eventid > 0;
			}
		);
		$alert_eventids = array_values(array_unique(array_merge($eventids, $r_eventids)));

		$events = self::callApi($server, 'event.get', [
			'output' => ['eventid'],
			'eventids' => $eventids,
			'selectAcknowledges' => ['userid', 'action', 'message', 'clock', 'new_severity', 'old_severity',
				'suppress_until'
			]
		]);

		$ack_counts = [];
		foreach ($events as $event) {
			$ack_counts[$event['eventid']] = array_key_exists('acknowledges', $event)
				? count($event['acknowledges'])
				: 0;
		}

		$search_limit = CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);
		$alerts = self::callApi($server, 'alert.get', [
			'output' => ['alertid', 'eventid', 'alerttype', 'status', 'mediatypeid', 'userid'],
			'eventids' => $alert_eventids,
			'limit' => $search_limit
		]);

		$alerts_by_event = [];
		foreach ($alerts as $alert) {
			$alerts_by_event[$alert['eventid']][] = $alert;
		}

		$summary = [];
		foreach ($problems as $problem) {
			$eventid = $problem['eventid'];
			$r_eventid = (int) $problem['r_eventid'];
			$event_alerts = $alerts_by_event[$eventid] ?? [];

			if ($r_eventid > 0 && array_key_exists((string) $r_eventid, $alerts_by_event)) {
				$event_alerts = array_merge($event_alerts, $alerts_by_event[(string) $r_eventid]);
			}

			$has_uncomplete = false;
			$has_failed = false;
			foreach ($event_alerts as $alert) {
				if ($alert['status'] == ALERT_STATUS_NEW || $alert['status'] == ALERT_STATUS_NOT_SENT) {
					$has_uncomplete = true;
				}
				elseif ($alert['status'] == ALERT_STATUS_FAILED) {
					$has_failed = true;
				}
			}

			$summary[$eventid] = [
				'count' => ($ack_counts[$eventid] ?? 0) + count($event_alerts),
				'has_uncomplete' => $has_uncomplete,
				'has_failed' => $has_failed
			];
		}

		return $summary;
	}

	private static function resolveHostIds(array $server, string $host_query): array {
		if ($host_query === '') {
			return [];
		}

		$params = [
			'output' => ['hostid', 'name', 'host'],
			'search' => [
				'name' => '*'.$host_query.'*',
				'host' => '*'.$host_query.'*'
			],
			'searchWildcardsEnabled' => true,
			'searchByAny' => true
		];

		$hosts = self::callApi($server, 'host.get', $params);

		return array_values(array_unique(array_column($hosts, 'hostid')));
	}

	private static function callApi(array $server, string $method, array $params): array {
		if (!empty($server['is_local'])) {
			return LocalApi::call($method, $params);
		}

		return RemoteApi::call($server['api_url'], $server['api_token'], $method, $params);
	}

	private static function updateServerMeta(string $server_id, array $updates, bool $clear_cache_on_version_change): void {
		$config = Config::get();
		$updated = false;
		foreach ($config['servers'] as $index => $server) {
			if ($server['id'] === $server_id) {
				$current_version = $server['api_version'] ?? '';
				foreach ($updates as $key => $value) {
					$config['servers'][$index][$key] = $value;
				}
				$updated = true;
				if ($clear_cache_on_version_change
						&& array_key_exists('api_version', $updates)
						&& $updates['api_version'] !== ''
						&& $updates['api_version'] !== $current_version) {
					Cache::clearServer($server_id);
				}
				break;
			}
		}

		if ($updated) {
			Config::save($config);
		}
	}
}

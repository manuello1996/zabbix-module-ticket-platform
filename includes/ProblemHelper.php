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
use Modules\TicketPlatform\Includes\RemoteApi;

class ProblemHelper {
	public static function fetchProblems(array $servers, array $filter, int $cache_ttl): array {
		RemoteApi::clearDebug();
		$problems = [];
		$errors = [];

		foreach ($servers as $server) {
			if (array_key_exists('enabled', $server) && !$server['enabled']) {
				continue;
			}

			try {
				$version = RemoteApi::callNoAuth($server['api_url'], 'apiinfo.version', []);
				RemoteApi::debug('apiinfo.version url='.$server['api_url'].' version='.$version);
			}
			catch (Exception $e) {
				RemoteApi::debug('apiinfo.version failed url='.$server['api_url'].' message='.$e->getMessage());
			}

			try {
				$users = RemoteApi::call($server['api_url'], $server['api_token'], 'user.get', [
					'output' => ['userid', 'username', 'roleid', 'status'],
					'limit' => 1
				]);
				if ($users) {
					$user = $users[0];
					$roleid = array_key_exists('roleid', $user) ? $user['roleid'] : '';
					$status = array_key_exists('status', $user) ? $user['status'] : '';
					RemoteApi::debug('user.get ok url='.$server['api_url'].' username='.$user['username']
						.' status='.$status.' roleid='.$roleid);
				}
				else {
					RemoteApi::debug('user.get ok url='.$server['api_url'].' empty result');
				}
			}
			catch (Exception $e) {
				RemoteApi::debug('user.get failed url='.$server['api_url'].' message='.$e->getMessage());
			}

			$cache_key = Cache::makeKey([
				'filter' => $filter,
				'server' => [
					'id' => $server['id'],
					'hostgroup' => $server['hostgroup'],
					'include_subgroups' => $server['include_subgroups']
				]
			]);

			$cached = Cache::get($server['id'], $cache_key, $cache_ttl);
			if ($cached !== null) {
				$problems = array_merge($problems, $cached);
				continue;
			}

			try {
				$groupids = self::resolveGroupIds($server);
				$items = [];
				if ($filter['show'] == TRIGGERS_OPTION_ALL) {
					$event_params = self::buildEventParams($filter, $groupids);
					$items = RemoteApi::call($server['api_url'], $server['api_token'], 'event.get', $event_params);
				}
				else {
					$problem_params = self::buildProblemParams($filter, $groupids);
					$items = RemoteApi::call($server['api_url'], $server['api_token'], 'problem.get', $problem_params);
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
						'server_url' => RemoteApi::getWebUrl($server['api_url']),
						'actions_count' => (int) $actions['count'],
						'actions_has_uncomplete' => (bool) $actions['has_uncomplete'],
						'actions_has_failed' => (bool) $actions['has_failed']
					];
				}

				Cache::set($server['id'], $cache_key, $server_problems);
				$problems = array_merge($problems, $server_problems);
			}
			catch (Exception $e) {
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

		$groups = RemoteApi::call($server['api_url'], $server['api_token'], 'hostgroup.get', $params);

		return array_values(array_unique(array_column($groups, 'groupid')));
	}

	private static function buildProblemParams(array $filter, array $groupids): array {
		$params = [
			'output' => ['eventid', 'clock', 'severity', 'name', 'acknowledged', 'r_eventid', 'objectid',
				'suppressed'
			],
			'sortfield' => ['eventid'],
			'sortorder' => ZBX_SORT_DOWN
		];

		if ($groupids) {
			$params['groupids'] = $groupids;
		}

		if ($filter['severities']) {
			$params['severities'] = $filter['severities'];
		}

		if ($filter['name'] !== '') {
			$params['search'] = ['name' => $filter['name']];
			$params['searchWildcardsEnabled'] = true;
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

	private static function buildEventParams(array $filter, array $groupids): array {
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

		if ($filter['severities']) {
			$params['severities'] = $filter['severities'];
		}

		if ($filter['name'] !== '') {
			$params['search'] = ['name' => $filter['name']];
			$params['searchWildcardsEnabled'] = true;
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
		$events = RemoteApi::call($server['api_url'], $server['api_token'], 'event.get', [
			'output' => ['eventid', 'objectid'],
			'eventids' => $eventids,
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'selectHosts' => ['name', 'host'],
			'selectAcknowledges' => ['clock', 'action', 'suppress_until']
		]);

		$event_info = [];
		foreach ($events as $event) {
			$hosts = [];
			if (array_key_exists('hosts', $event)) {
				foreach ($event['hosts'] as $host) {
					$hosts[] = $host['name'] !== '' ? $host['name'] : $host['host'];
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

		$events = RemoteApi::call($server['api_url'], $server['api_token'], 'event.get', [
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
		$alerts = RemoteApi::call($server['api_url'], $server['api_token'], 'alert.get', [
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
}

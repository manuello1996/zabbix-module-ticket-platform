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

use CArrayHelper;
use CController;
use CControllerResponseData;
use CSettingsHelper;
use CWebUser;
use Exception;
use Modules\TicketPlatform\Includes\Config;
use Modules\TicketPlatform\Includes\RemoteApi;

class TicketPlatformEventDetails extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'server_id' => 'required',
			'eventid' => 'required',
			'triggerid' => 'required'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseData([
				'error' => [
					'title' => _('Cannot load event details'),
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
		$eventid = (string) $this->getInput('eventid', '');
		$triggerid = (string) $this->getInput('triggerid', '');

		if ($server === null || $eventid === '' || $triggerid === '') {
			$this->setResponse(new CControllerResponseData([
				'error' => [
					'title' => _('Cannot load event details'),
					'messages' => [_('No remote server or event specified.')]
				]
			]));
			return;
		}

		try {
			$trigger = $this->getTrigger($server, $triggerid);
			$event = $this->getEvent($server, $eventid, $triggerid);
			$cause_event = $event['cause_eventid'] != 0
				? $this->getCauseEvent($server, (string) $event['cause_eventid'])
				: null;
			$opdata = $this->buildOpdata($server, $trigger);

			$actions_data = $this->getActionsData($server, $event);
			$users = $actions_data['userids']
				? $this->getUsers($server, array_keys($actions_data['userids']))
				: [];
			$mediatypes = $actions_data['mediatypeids']
				? $this->getMediatypes($server, array_keys($actions_data['mediatypeids']))
				: [];

			$event_list = $this->getEventList($server, $event);
			$event_list_actions = $this->getActionsSummary($server, $event_list);

			$response = new CControllerResponseData([
				'action' => $this->getAction(),
				'server' => $server,
				'trigger' => $trigger,
				'event' => $event,
				'cause_event' => $cause_event,
				'opdata' => $opdata,
				'actions' => $actions_data['actions'],
				'users' => $users,
				'mediatypes' => $mediatypes,
				'event_list' => $event_list,
				'event_list_actions' => $event_list_actions
			]);
			$response->setTitle(_('Event details'));

			$this->setResponse($response);
		}
		catch (Exception $e) {
			$this->setResponse(new CControllerResponseData([
				'error' => [
					'title' => _('Cannot load event details'),
					'messages' => [$e->getMessage()]
				]
			]));
		}
	}

	private function getServer(string $server_id): ?array {
		$config = Config::get();

		foreach ($config['servers'] as $server) {
			if ($server['id'] === $server_id) {
				return $server;
			}
		}

		return null;
	}

	private function getTrigger(array $server, string $triggerid): array {
		$triggers = RemoteApi::call($server['api_url'], $server['api_token'], 'trigger.get', [
			'output' => ['triggerid', 'description', 'expression', 'recovery_expression', 'priority', 'type',
				'manual_close', 'status', 'comments', 'opdata'
			],
			'selectHosts' => ['hostid', 'name', 'host'],
			'triggerids' => [$triggerid],
			'expandExpression' => true,
			'expandDescription' => true
		]);

		if (!$triggers) {
			throw new Exception(_('No permissions to referred object or it does not exist!'));
		}

		return $triggers[0];
	}

	private function getEvent(array $server, string $eventid, string $triggerid): array {
		$events = RemoteApi::call($server['api_url'], $server['api_token'], 'event.get', [
			'output' => ['eventid', 'r_eventid', 'clock', 'ns', 'objectid', 'name', 'acknowledged', 'severity',
				'cause_eventid'
			],
			'selectAcknowledges' => ['clock', 'message', 'action', 'userid', 'old_severity', 'new_severity',
				'suppress_until'
			],
			'selectTags' => ['tag', 'value'],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'eventids' => [$eventid],
			'objectids' => [$triggerid],
			'value' => TRIGGER_VALUE_TRUE
		]);

		if (!$events) {
			throw new Exception(_('No permissions to referred object or it does not exist!'));
		}

		$event = $events[0];
		$event['comments'] = '';
		$event['opdata'] = '';

		if ($event['r_eventid'] != 0) {
			$r_events = RemoteApi::call($server['api_url'], $server['api_token'], 'event.get', [
				'output' => ['correlationid', 'userid'],
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'eventids' => [$event['r_eventid']],
				'objectids' => [$triggerid]
			]);

			if ($r_events) {
				$r_event = reset($r_events);
				$event['correlationid'] = $r_event['correlationid'] ?? 0;
				$event['userid'] = $r_event['userid'] ?? 0;
			}
		}
		else {
			$event['correlationid'] = 0;
			$event['userid'] = 0;
		}

		return $event;
	}

	private function getCauseEvent(array $server, string $eventid): ?array {
		$events = RemoteApi::call($server['api_url'], $server['api_token'], 'event.get', [
			'output' => ['eventid', 'name', 'objectid'],
			'eventids' => [$eventid]
		]);

		return $events ? $events[0] : null;
	}

	private function getActionsData(array $server, array $event): array {
		$r_events = [];
		$alert_eventids = [(string) $event['eventid']];

		if ((int) $event['r_eventid'] > 0) {
			$alert_eventids[] = (string) $event['r_eventid'];

			$r_events = RemoteApi::call($server['api_url'], $server['api_token'], 'event.get', [
				'output' => ['clock'],
				'eventids' => [$event['r_eventid']],
				'preservekeys' => true
			]);
		}

		$search_limit = CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);
		$alerts = RemoteApi::call($server['api_url'], $server['api_token'], 'alert.get', [
			'output' => ['alertid', 'alerttype', 'clock', 'error', 'eventid', 'esc_step', 'mediatypeid', 'message',
				'retries', 'sendto', 'status', 'subject', 'userid', 'p_eventid', 'acknowledgeid'
			],
			'eventids' => $alert_eventids,
			'limit' => $search_limit
		]);

		return $this->buildActions($event, $r_events, $alerts);
	}

	private function buildActions(array $event, array $r_events, array $alerts): array {
		$action_count = 0;
		$has_uncomplete_action = false;
		$has_failed_action = false;
		$mediatypeids = [];
		$userids = [];
		$actions = [];

		$actions[] = [
			'action_type' => ZBX_EVENT_HISTORY_PROBLEM_EVENT,
			'clock' => $event['clock']
		];

		if (array_key_exists($event['r_eventid'], $r_events)) {
			$actions[] = [
				'action_type' => ZBX_EVENT_HISTORY_RECOVERY_EVENT,
				'clock' => $r_events[$event['r_eventid']]['clock']
			];
		}

		foreach ($event['acknowledges'] ?? [] as $ack) {
			$ack['action_type'] = ZBX_EVENT_HISTORY_MANUAL_UPDATE;
			$actions[] = $ack;
			$action_count++;

			if (array_key_exists('userid', $ack)) {
				$userids[$ack['userid']] = true;
			}
		}

		foreach ($alerts as $alert) {
			$alert_eventid = (string) $alert['eventid'];
			if ($alert_eventid === (string) $event['eventid']
					|| $alert_eventid === (string) $event['r_eventid']) {
				$alert['action_type'] = ZBX_EVENT_HISTORY_ALERT;
				$actions[] = $alert;
				$action_count++;

				if ($alert['alerttype'] == ALERT_TYPE_MESSAGE) {
					if ($alert['mediatypeid'] != 0) {
						$mediatypeids[$alert['mediatypeid']] = true;
					}
					if ($alert['userid'] != 0) {
						$userids[$alert['userid']] = true;
					}
				}

				if ($alert['status'] == ALERT_STATUS_NEW || $alert['status'] == ALERT_STATUS_NOT_SENT) {
					$has_uncomplete_action = true;
				}
				elseif ($alert['status'] == ALERT_STATUS_FAILED) {
					$has_failed_action = true;
				}
			}
		}

		CArrayHelper::sort($actions, [
			['field' => 'clock', 'order' => ZBX_SORT_DOWN],
			['field' => 'action_type', 'order' => ZBX_SORT_DOWN],
			['field' => 'alertid', 'order' => ZBX_SORT_DOWN]
		]);

		return [
			'actions' => array_values($actions),
			'count' => $action_count,
			'has_uncomplete_action' => $has_uncomplete_action,
			'has_failed_action' => $has_failed_action,
			'mediatypeids' => $mediatypeids,
			'userids' => $userids
		];
	}

	private function getUsers(array $server, array $userids): array {
		$users = RemoteApi::call($server['api_url'], $server['api_token'], 'user.get', [
			'output' => ['userid', 'username', 'name', 'surname'],
			'userids' => $userids
		]);

		$result = [];
		foreach ($users as $user) {
			$result[$user['userid']] = $user;
		}

		return $result;
	}

	private function getMediatypes(array $server, array $mediatypeids): array {
		$mediatypes = RemoteApi::call($server['api_url'], $server['api_token'], 'mediatype.get', [
			'output' => ['mediatypeid', 'name', 'maxattempts'],
			'mediatypeids' => $mediatypeids
		]);

		$result = [];
		foreach ($mediatypes as $mediatype) {
			$result[$mediatype['mediatypeid']] = $mediatype;
		}

		return $result;
	}

	private function buildOpdata(array $server, array $trigger): string {
		if ($trigger['opdata'] !== '') {
			return $trigger['opdata'];
		}

		$items = RemoteApi::call($server['api_url'], $server['api_token'], 'item.get', [
			'output' => ['itemid', 'name', 'value_type', 'units', 'lastvalue', 'lastclock', 'valuemapid'],
			'selectValueMap' => ['mappings'],
			'triggerids' => [$trigger['triggerid']]
		]);

		if (!$items) {
			return _('N/A');
		}

		$parts = [];
		foreach ($items as $item) {
			$value = array_key_exists('lastvalue', $item) ? $item['lastvalue'] : UNRESOLVED_MACRO_STRING;

			if ((int) $item['value_type'] === ITEM_VALUE_TYPE_BINARY) {
				$value = _('binary value');
			}
			elseif ($value !== UNRESOLVED_MACRO_STRING) {
				if (!array_key_exists('valuemap', $item) || !is_array($item['valuemap'])) {
					$item['valuemap'] = [];
				}
				$value = formatHistoryValue($value, $item);
			}

			$parts[] = $item['name'].': '.$value;
		}

		return implode(', ', $parts);
	}

	private function getEventList(array $server, array $event): array {
		$events = RemoteApi::call($server['api_url'], $server['api_token'], 'event.get', [
			'output' => ['eventid', 'objectid', 'acknowledged', 'clock', 'ns', 'severity', 'r_eventid',
				'cause_eventid'
			],
			'selectAcknowledges' => ['userid', 'clock', 'message', 'action', 'old_severity', 'new_severity',
				'suppress_until', 'taskid'
			],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'value' => TRIGGER_VALUE_TRUE,
			'objectids' => [$event['objectid']],
			'eventid_till' => $event['eventid'],
			'sortfield' => ['clock', 'eventid'],
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => 20,
			'preservekeys' => true
		]);

		if (!$events) {
			return [];
		}

		$r_eventids = [];
		foreach ($events as $row) {
			if ($row['r_eventid'] != 0) {
				$r_eventids[$row['r_eventid']] = true;
			}
		}

		$r_events = $r_eventids
			? RemoteApi::call($server['api_url'], $server['api_token'], 'event.get', [
				'output' => ['clock'],
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'eventids' => array_keys($r_eventids),
				'preservekeys' => true
			])
			: [];

		foreach ($events as &$row) {
			$row['r_clock'] = array_key_exists($row['r_eventid'], $r_events)
				? $r_events[$row['r_eventid']]['clock']
				: 0;
		}
		unset($row);

		return array_values($events);
	}

	private function getActionsSummary(array $server, array $events): array {
		if (!$events) {
			return [];
		}

		$eventids = array_column($events, 'eventid');
		$r_eventids = array_filter(array_map('intval', array_column($events, 'r_eventid')),
			function (int $eventid): bool {
				return $eventid > 0;
			}
		);
		$alert_eventids = array_values(array_unique(array_merge($eventids, $r_eventids)));

		$ack_counts = [];
		foreach ($events as $row) {
			$ack_counts[$row['eventid']] = array_key_exists('acknowledges', $row)
				? count($row['acknowledges'])
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
		foreach ($events as $row) {
			$eventid = $row['eventid'];
			$r_eventid = (int) $row['r_eventid'];
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

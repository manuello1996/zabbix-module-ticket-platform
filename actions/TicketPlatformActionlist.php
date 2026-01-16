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
use Exception;
use Modules\TicketPlatform\Includes\Config;
use Modules\TicketPlatform\Includes\RemoteApi;

class TicketPlatformActionlist extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'eventid' => 'required',
			'server_id' => 'required'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setErrorResponse(_('Cannot fetch actions'), array_column(get_and_clear_messages(), 'message'));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return ($this->getUserType() >= USER_TYPE_ZABBIX_USER);
	}

	protected function doAction(): void {
		$eventid = (string) $this->getInput('eventid', '');
		$server_id = (string) $this->getInput('server_id', '');
		$server = $this->getServer($server_id);

		if ($server === null || $eventid === '') {
			$this->setErrorResponse(_('Cannot fetch actions'), [_('No remote server or event specified.')]);
			return;
		}

		try {
			$event = $this->getEvent($server, $eventid);
			$actions_data = $this->getActionsData($server, $event);

			$users = $actions_data['userids']
				? $this->getUsers($server, array_keys($actions_data['userids']))
				: [];

			$mediatypes = $actions_data['mediatypeids']
				? $this->getMediatypes($server, array_keys($actions_data['mediatypeids']))
				: [];

			$this->setResponse(new CControllerResponseData([
				'actions' => $actions_data['actions'],
				'users' => $users,
				'mediatypes' => $mediatypes,
				'foot_note' => ($actions_data['count'] > ZBX_WIDGET_ROWS)
					? _s('Displaying %1$s of %2$s found', ZBX_WIDGET_ROWS, $actions_data['count'])
					: null
			]));
		}
		catch (Exception $e) {
			$this->setErrorResponse(_('Cannot fetch actions'), [$e->getMessage()]);
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

	private function getEvent(array $server, string $eventid): array {
		$events = RemoteApi::call($server['api_url'], $server['api_token'], 'event.get', [
			'output' => ['eventid', 'r_eventid', 'clock'],
			'eventids' => [$eventid],
			'selectAcknowledges' => ['userid', 'action', 'message', 'clock', 'new_severity', 'old_severity',
				'suppress_until'
			]
		]);

		if (!$events) {
			throw new Exception(_('No permissions to referred object or it does not exist!'));
		}

		return $events[0];
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

	private function setErrorResponse(string $title, array $messages): void {
		$this->setResponse(
			(new CControllerResponseData(['main_block' => json_encode([
				'error' => [
					'title' => $title,
					'messages' => $messages
				]
			])]))->disableView()
		);
	}
}

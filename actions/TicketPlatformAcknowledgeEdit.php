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
use CProfile;
use CRoleHelper;
use CWebUser;
use Modules\TicketPlatform\Includes\Config;
use Modules\TicketPlatform\Includes\RemoteApi;

class TicketPlatformAcknowledgeEdit extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'eventids' => 'array',
			'server_id' => 'string'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(
				(new CControllerResponseData(['main_block' => json_encode([
					'error' => [
						'messages' => array_column(get_and_clear_messages(), 'message')
					]
				])]))->disableView()
			);
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return CWebUser::getType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$server = $this->getServer($this->getInput('server_id', ''));
		$eventids = $this->getInput('eventids', []);

		if ($server === null || !$eventids) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => [
					'messages' => [_('No remote server or events selected.')]
				]
			])]));
			return;
		}

		$allowed_acknowledge = $this->checkAccess(CRoleHelper::ACTIONS_ACKNOWLEDGE_PROBLEMS);
		$allowed_close = $this->checkAccess(CRoleHelper::ACTIONS_CLOSE_PROBLEMS);
		$allowed_change_severity = $this->checkAccess(CRoleHelper::ACTIONS_CHANGE_SEVERITY);
		$allowed_add_comments = $this->checkAccess(CRoleHelper::ACTIONS_ADD_PROBLEM_COMMENTS);
		$allowed_suppress = $this->checkAccess(CRoleHelper::ACTIONS_SUPPRESS_PROBLEMS);
		$allowed_change_problem_ranking = $this->checkAccess(CRoleHelper::ACTIONS_CHANGE_PROBLEM_RANKING);

		$events = RemoteApi::call($server['api_url'], $server['api_token'], 'event.get', [
			'output' => ['eventid', 'name', 'objectid', 'acknowledged', 'value', 'r_eventid', 'cause_eventid'],
			'selectAcknowledges' => ['userid', 'clock', 'message', 'action', 'old_severity', 'new_severity',
				'suppress_until'
			],
			'selectSuppressionData' => $allowed_suppress ? ['maintenanceid', 'suppress_until'] : null,
			'eventids' => $eventids,
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'preservekeys' => true
		]);

		if (!$events) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => [
					'messages' => [_('No remote events found.')]
				]
			])]));
			return;
		}

		$triggerids = array_column($events, 'objectid', 'objectid');
		$editable_triggers = [];

		try {
			if ($triggerids) {
				$editable_triggers = RemoteApi::call($server['api_url'], $server['api_token'], 'trigger.get', [
					'output' => ['manual_close'],
					'triggerids' => array_keys($triggerids),
					'preservekeys' => true
				]);
				$editable_triggers = array_column($editable_triggers, null, 'triggerid');
			}
		}
		catch (\Exception $e) {
			$editable_triggers = [];
		}

		$data = [
			'eventids' => $eventids,
			'server_id' => $server['id'],
			'message' => '',
			'scope' => ZBX_ACKNOWLEDGE_SELECTED,
			'change_severity' => ZBX_PROBLEM_UPDATE_NONE,
			'severity' => TRIGGER_SEVERITY_NOT_CLASSIFIED,
			'acknowledge_problem' => ZBX_PROBLEM_UPDATE_NONE,
			'unacknowledge_problem' => ZBX_PROBLEM_UPDATE_NONE,
			'close_problem' => ZBX_PROBLEM_UPDATE_NONE,
			'suppress_problem' => ZBX_PROBLEM_UPDATE_NONE,
			'unsuppress_problem' => ZBX_PROBLEM_UPDATE_NONE,
			'related_problems_count' => 0,
			'problem_can_be_closed' => false,
			'problem_can_be_suppressed' => false,
			'problem_can_be_unsuppressed' => false,
			'problem_severity_can_be_changed' => false,
			'problem_can_change_rank' => false,
			'allowed_acknowledge' => $allowed_acknowledge,
			'allowed_close' => $allowed_close,
			'allowed_change_severity' => $allowed_change_severity,
			'allowed_add_comments' => $allowed_add_comments,
			'allowed_suppress' => $allowed_suppress,
			'allowed_change_problem_ranking' => $allowed_change_problem_ranking,
			'suppress_until_problem' => CProfile::get('web.problem_suppress_action_time_until', 'now+1d')
		];

		if (count($events) == 1) {
			$data['problem_name'] = reset($events)['name'];
		}
		else {
			$data['problem_name'] = _s('%1$d problems selected.', count($events));
		}

		$ack_count = 0;
		foreach ($events as $event) {
			$can_be_closed = true;
			$can_be_suppressed = true;
			$can_be_unsuppressed = false;

			if ($event['cause_eventid'] != 0) {
				$data['problem_can_change_rank'] = true;
			}

			if ($allowed_suppress) {
				foreach ($event['suppression_data'] as $suppression) {
					if ($suppression['maintenanceid'] == 0) {
						$can_be_unsuppressed = true;
					}
				}
			}

			if ($event['r_eventid'] != 0 || $event['value'] == TRIGGER_VALUE_FALSE) {
				$can_be_closed = false;
				$can_be_suppressed = false;
				$can_be_unsuppressed = false;
				$data['related_problems_count']++;
			}
			elseif (array_key_exists($event['objectid'], $editable_triggers)
					&& $editable_triggers[$event['objectid']]['manual_close'] == ZBX_TRIGGER_MANUAL_CLOSE_NOT_ALLOWED) {
				$can_be_closed = false;
			}
			elseif (hasEventCloseAction($event['acknowledges'])) {
				$can_be_closed = false;
			}

			if ($can_be_closed) {
				$data['problem_can_be_closed'] = true;
			}
			if ($can_be_suppressed) {
				$data['problem_can_be_suppressed'] = true;
			}
			if ($can_be_unsuppressed) {
				$data['problem_can_be_unsuppressed'] = true;
			}

			$ack_count += ($event['acknowledged'] == EVENT_ACKNOWLEDGED) ? 1 : 0;
		}

		$data['has_ack_events'] = ($ack_count > 0);
		$data['has_unack_events'] = ($ack_count != count($events));
		$data['problem_severity_can_be_changed'] = (bool) $editable_triggers;

		try {
			if ($triggerids) {
				$data['related_problems_count'] += (int) RemoteApi::call(
					$server['api_url'],
					$server['api_token'],
					'problem.get',
					[
						'countOutput' => true,
						'objectids' => array_keys($triggerids)
					]
				);
			}
		}
		catch (\Exception $e) {
		}

		$output = [
			'title' => _('Update problem'),
			'errors' => hasErrorMessages() ? getMessages() : null,
			'user' => [
				'debug_mode' => $this->getDebugMode()
			]
		] + $data;

		$this->setResponse(new CControllerResponseData($output));
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
}

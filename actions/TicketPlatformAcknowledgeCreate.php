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
use CRangeTimeParser;
use CRoleHelper;
use CWebUser;
use Modules\TicketPlatform\Includes\Cache;
use Modules\TicketPlatform\Includes\Config;
use Modules\TicketPlatform\Includes\RemoteApi;

class TicketPlatformAcknowledgeCreate extends CController {

	private ?CRangeTimeParser $suppress_until_time_parser = null;
	private int $suppress_until = 0;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'eventids' => 'array',
			'server_id' => 'string',
			'cause_eventid' => 'string',
			'message' => 'string',
			'scope' => 'in '.ZBX_ACKNOWLEDGE_SELECTED.','.ZBX_ACKNOWLEDGE_PROBLEM,
			'change_severity' => 'in '.ZBX_PROBLEM_UPDATE_NONE.','.ZBX_PROBLEM_UPDATE_SEVERITY,
			'severity' => 'ge '.TRIGGER_SEVERITY_NOT_CLASSIFIED.'|le '.TRIGGER_SEVERITY_COUNT,
			'acknowledge_problem' => 'in '.ZBX_PROBLEM_UPDATE_NONE.','.ZBX_PROBLEM_UPDATE_ACKNOWLEDGE,
			'unacknowledge_problem' => 'in '.ZBX_PROBLEM_UPDATE_NONE.','.ZBX_PROBLEM_UPDATE_UNACKNOWLEDGE,
			'close_problem' => 'in '.ZBX_PROBLEM_UPDATE_NONE.','.ZBX_PROBLEM_UPDATE_CLOSE,
			'suppress_problem' => 'in '.ZBX_PROBLEM_UPDATE_NONE.','.ZBX_PROBLEM_UPDATE_SUPPRESS,
			'suppress_time_option' => 'in '.ZBX_PROBLEM_SUPPRESS_TIME_INDEFINITE.','.ZBX_PROBLEM_SUPPRESS_TIME_DEFINITE,
			'suppress_until_problem' => 'range_time',
			'unsuppress_problem' => 'in '.ZBX_PROBLEM_UPDATE_NONE.','.ZBX_PROBLEM_UPDATE_UNSUPPRESS,
			'change_rank' => 'in '.ZBX_PROBLEM_UPDATE_NONE.','.ZBX_PROBLEM_UPDATE_RANK_TO_CAUSE.','.ZBX_PROBLEM_UPDATE_RANK_TO_SYMPTOM
		];

		$ret = $this->validateInput($fields);

		$suppress = $this->getInput('suppress_problem', ZBX_PROBLEM_UPDATE_NONE);
		$suppress_time = $this->getInput('suppress_time_option', ZBX_PROBLEM_SUPPRESS_TIME_INDEFINITE);

		if ($ret && $suppress == ZBX_PROBLEM_UPDATE_SUPPRESS && $suppress_time == ZBX_PROBLEM_SUPPRESS_TIME_DEFINITE) {
			$this->suppress_until_time_parser = new CRangeTimeParser();
			$this->suppress_until_time_parser->parse($this->getInput('suppress_until_problem', ''));
			$suppress_until = $this->suppress_until_time_parser->getDateTime(false)->getTimestamp();

			if (!validateUnixTime($suppress_until) || $suppress_until < time()) {
				error(_s('Incorrect value for field "%1$s": %2$s.', _('Suppress'), _('invalid time')));
				$ret = false;
			}
		}

		if (!$ret) {
			$error_title = $this->hasInput('eventids')
				? _n('Cannot update event', 'Cannot update events', count($this->getInput('eventids', [])))
				: _('Cannot update events');

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => [
					'title' => $error_title,
					'messages' => array_column(get_and_clear_messages(), 'message')
				]
			])]));
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
					'title' => _('Cannot update events'),
					'messages' => [_('No remote server or events selected.')]
				]
			])]));
			return;
		}

		$action = ZBX_PROBLEM_UPDATE_NONE;
		$params = [
			'eventids' => $eventids
		];

		if ($this->checkAccess(CRoleHelper::ACTIONS_CHANGE_SEVERITY)
				&& $this->getInput('change_severity', ZBX_PROBLEM_UPDATE_NONE) == ZBX_PROBLEM_UPDATE_SEVERITY) {
			$action |= ZBX_PROBLEM_UPDATE_SEVERITY;
			$params['severity'] = (int) $this->getInput('severity');
		}

		if ($this->checkAccess(CRoleHelper::ACTIONS_ACKNOWLEDGE_PROBLEMS)
				&& $this->getInput('acknowledge_problem', ZBX_PROBLEM_UPDATE_NONE) == ZBX_PROBLEM_UPDATE_ACKNOWLEDGE) {
			$action |= ZBX_PROBLEM_UPDATE_ACKNOWLEDGE;
		}

		if ($this->checkAccess(CRoleHelper::ACTIONS_ACKNOWLEDGE_PROBLEMS)
				&& $this->getInput('unacknowledge_problem', ZBX_PROBLEM_UPDATE_NONE)
					== ZBX_PROBLEM_UPDATE_UNACKNOWLEDGE) {
			$action |= ZBX_PROBLEM_UPDATE_UNACKNOWLEDGE;
		}

		if ($this->checkAccess(CRoleHelper::ACTIONS_CLOSE_PROBLEMS)
				&& $this->getInput('close_problem', ZBX_PROBLEM_UPDATE_NONE) == ZBX_PROBLEM_UPDATE_CLOSE) {
			$action |= ZBX_PROBLEM_UPDATE_CLOSE;
		}

		if ($this->checkAccess(CRoleHelper::ACTIONS_ADD_PROBLEM_COMMENTS)) {
			$message = $this->getInput('message', '');
			if ($message !== '') {
				$action |= ZBX_PROBLEM_UPDATE_MESSAGE;
				$params['message'] = $message;
			}
		}

		if ($this->checkAccess(CRoleHelper::ACTIONS_SUPPRESS_PROBLEMS)
				&& $this->getInput('suppress_problem', ZBX_PROBLEM_UPDATE_NONE) == ZBX_PROBLEM_UPDATE_SUPPRESS) {
			$action |= ZBX_PROBLEM_UPDATE_SUPPRESS;
			if ($this->getInput('suppress_time_option') == ZBX_PROBLEM_SUPPRESS_TIME_DEFINITE) {
				$this->suppress_until = $this->suppress_until_time_parser->getDateTime(false)->getTimestamp();
				if ($this->suppress_until_time_parser->getTimeType() == CRangeTimeParser::ZBX_TIME_RELATIVE) {
					$suppress_until_time = $this->getInput('suppress_until_problem');
					CProfile::update('web.problem_suppress_action_time_until', $suppress_until_time, PROFILE_TYPE_STR);
				}
			}
			else {
				$this->suppress_until = ZBX_PROBLEM_SUPPRESS_TIME_INDEFINITE;
			}
			$params['suppress_until'] = $this->suppress_until;
		}

		if ($this->checkAccess(CRoleHelper::ACTIONS_SUPPRESS_PROBLEMS)
				&& $this->getInput('unsuppress_problem', ZBX_PROBLEM_UPDATE_NONE) == ZBX_PROBLEM_UPDATE_UNSUPPRESS) {
			$action |= ZBX_PROBLEM_UPDATE_UNSUPPRESS;
		}

		$change_rank = $this->getInput('change_rank', ZBX_PROBLEM_UPDATE_NONE);
		if ($this->checkAccess(CRoleHelper::ACTIONS_CHANGE_PROBLEM_RANKING)
				&& $change_rank == ZBX_PROBLEM_UPDATE_RANK_TO_CAUSE) {
			$action |= ZBX_PROBLEM_UPDATE_RANK_TO_CAUSE;
		}
		elseif ($this->checkAccess(CRoleHelper::ACTIONS_CHANGE_PROBLEM_RANKING)
				&& $change_rank == ZBX_PROBLEM_UPDATE_RANK_TO_SYMPTOM) {
			$cause_eventid = $this->getInput('cause_eventid', '');
			if ($cause_eventid === '') {
				$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
					'error' => [
						'title' => _('Cannot update events'),
						'messages' => [_('Field "cause_eventid" is mandatory.')]
					]
				])]));
				return;
			}
			$action |= ZBX_PROBLEM_UPDATE_RANK_TO_SYMPTOM;
			$params['cause_eventid'] = $cause_eventid;
		}

		if ($this->getInput('scope', ZBX_ACKNOWLEDGE_SELECTED) == ZBX_ACKNOWLEDGE_PROBLEM) {
			$params['eventids'] = $this->getRelatedProblemids($server, $eventids);
		}

		if ($action == ZBX_PROBLEM_UPDATE_NONE) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => [
					'title' => _('Cannot update events'),
					'messages' => [_('At least one update operation or message is mandatory')]
				]
			])]));
			return;
		}

		$params['action'] = $action;

		try {
			RemoteApi::call($server['api_url'], $server['api_token'], 'event.acknowledge', $params);
			Cache::clearServer($server['id']);
			$success = ['title' => _n('Event updated', 'Events updated', count($params['eventids']))];
			if ($messages = get_and_clear_messages()) {
				$success['messages'] = array_column($messages, 'message');
			}

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'success' => $success
			])]));
		}
		catch (\Exception $e) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => [
					'title' => _n('Cannot update event', 'Cannot update events', count($params['eventids'])),
					'messages' => [$e->getMessage()]
				]
			])]));
		}
	}

	private function getRelatedProblemids(array $server, array $eventids): array {
		$events = RemoteApi::call($server['api_url'], $server['api_token'], 'event.get', [
			'output' => ['objectid'],
			'eventids' => $eventids,
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER
		]);

		if (!$events) {
			return $eventids;
		}

		$objectids = array_column($events, 'objectid', 'objectid');
		if (!$objectids) {
			return $eventids;
		}

		$related = RemoteApi::call($server['api_url'], $server['api_token'], 'problem.get', [
			'output' => ['eventid'],
			'objectids' => array_keys($objectids),
			'preservekeys' => true
		]);

		$related_eventids = array_keys($related);
		return $related_eventids ? $related_eventids : $eventids;
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

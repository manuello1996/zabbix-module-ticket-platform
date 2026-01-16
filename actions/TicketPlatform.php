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
use CProfile;
use CRangeTimeParser;
use CSettingsHelper;
use CParser;
use CUrl;
use CWebUser;
use Modules\TicketPlatform\Includes\Config;
use Modules\TicketPlatform\Includes\ProblemHelper;
use Modules\TicketPlatform\Includes\RemoteApi;

class TicketPlatform extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'filter_set' => 'in 1',
			'filter_rst' => 'in 1',
			'show' => 'in '.TRIGGERS_OPTION_RECENT_PROBLEM.','.TRIGGERS_OPTION_IN_PROBLEM.','.TRIGGERS_OPTION_ALL,
			'server_ids' => 'array',
			'name' => 'string',
			'severities' => 'array',
			'age_state' => 'in 0,1',
			'age' => 'int32',
			'acknowledgement_status' => 'in '.ZBX_ACK_STATUS_ALL.','.ZBX_ACK_STATUS_UNACK.','.ZBX_ACK_STATUS_ACK,
			'show_suppressed' => 'in 0,1',
			'evaltype' => 'in '.TAG_EVAL_TYPE_AND_OR.','.TAG_EVAL_TYPE_OR,
			'tags' => 'array',
			'show_tags' => 'in '.SHOW_TAGS_NONE.','.SHOW_TAGS_1.','.SHOW_TAGS_2.','.SHOW_TAGS_3,
			'tag_name_format' => 'in '.TAG_NAME_FULL.','.TAG_NAME_SHORTENED.','.TAG_NAME_NONE,
			'sort' => 'in clock,host,severity,name,server',
			'sortorder' => 'in '.ZBX_SORT_DOWN.','.ZBX_SORT_UP,
			'from' => 'range_time',
			'to' => 'range_time',
			'page' => 'ge 1'
		];

		return $this->validateInput($fields) && $this->validateTimeSelectorPeriod();
	}

	protected function checkPermissions(): bool {
		return CWebUser::getType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$config = Config::get();
		$servers = $config['servers'];

		if ($this->hasInput('filter_rst')) {
			$redirect = (new CUrl('zabbix.php'))->setArgument('action', $this->getAction());
			$this->setResponse(new CControllerResponseRedirect($redirect));
			return;
		}

		$profile_from = CProfile::get('web.problem.filter.from',
			'now-'.CSettingsHelper::get(CSettingsHelper::PERIOD_DEFAULT)
		);
		$profile_to = CProfile::get('web.problem.filter.to', 'now');

		$filter = [
			'show' => $this->getInput('show', TRIGGERS_OPTION_RECENT_PROBLEM),
			'server_ids' => $this->getInput('server_ids', []),
			'name' => $this->getInput('name', ''),
			'severities' => $this->getInput('severities', []),
			'age_state' => $this->getInput('age_state', 0),
			'age' => $this->getInput('age', 14),
			'acknowledgement_status' => $this->getInput('acknowledgement_status', ZBX_ACK_STATUS_ALL),
			'show_suppressed' => $this->getInput('show_suppressed', 0),
			'evaltype' => $this->getInput('evaltype', TAG_EVAL_TYPE_AND_OR),
			'tags' => array_filter($this->getInput('tags', []), function ($tag) {
				return array_key_exists('tag', $tag) && $tag['tag'] !== '';
			}),
			'show_tags' => $this->getInput('show_tags', SHOW_TAGS_3),
			'tag_name_format' => $this->getInput('tag_name_format', TAG_NAME_FULL),
			'from' => $this->getInput('from', $profile_from),
			'to' => $this->getInput('to', $profile_to),
			'sort' => $this->getInput('sort', 'clock'),
			'sortorder' => $this->getInput('sortorder', ZBX_SORT_DOWN),
			'page' => $this->getInput('page', 1)
		];

		$normalized_filter = $this->normalizeFilter($filter);
		$visible_servers = $this->filterServers($servers, $filter['server_ids']);

		[$problems, $errors] = ProblemHelper::fetchProblems(
			$visible_servers,
			$normalized_filter,
			(int) $config['cache_ttl']
		);

		$response = new CControllerResponseData([
			'action' => $this->getAction(),
			'filter' => $filter,
			'problems' => $problems,
			'errors' => $errors,
			'servers' => $servers,
			'debug' => RemoteApi::getDebug()
		]);
		$response->setTitle(_('Ticket Platform'));

		$this->setResponse($response);
	}

	private function filterServers(array $servers, array $server_ids): array {
		if (!$server_ids) {
			return $servers;
		}

		$allowed = array_fill_keys($server_ids, true);
		$filtered = [];

		foreach ($servers as $server) {
			if (array_key_exists($server['id'], $allowed)) {
				$filtered[] = $server;
			}
		}

		return $filtered;
	}

	private function normalizeFilter(array $filter): array {
		$time_from = null;
		$time_till = null;
		$recent = null;

		$range_time_parser = new CRangeTimeParser();
		if ($range_time_parser->parse($filter['from']) == CParser::PARSE_SUCCESS) {
			$time_from = $range_time_parser->getDateTime(true)->getTimestamp();
		}
		if ($range_time_parser->parse($filter['to']) == CParser::PARSE_SUCCESS) {
			$time_till = $range_time_parser->getDateTime(false)->getTimestamp();
		}

		if ($filter['age_state']) {
			$time_from = time() - ($filter['age'] * SEC_PER_DAY);
		}

		if ($filter['show'] == TRIGGERS_OPTION_ALL) {
			$recent = true;
		}
		elseif ($filter['show'] == TRIGGERS_OPTION_RECENT_PROBLEM) {
			$recent = true;
		}
		elseif ($filter['show'] == TRIGGERS_OPTION_IN_PROBLEM) {
			$recent = false;
		}

		$acknowledged = null;
		if ($filter['acknowledgement_status'] == ZBX_ACK_STATUS_ACK) {
			$acknowledged = true;
		}
		elseif ($filter['acknowledgement_status'] == ZBX_ACK_STATUS_UNACK) {
			$acknowledged = false;
		}

		$tags = [];
		foreach ($filter['tags'] as $tag) {
			$tags[] = [
				'tag' => $tag['tag'],
				'operator' => array_key_exists('operator', $tag) ? (int) $tag['operator'] : TAG_OPERATOR_LIKE,
				'value' => array_key_exists('value', $tag) ? $tag['value'] : ''
			];
		}

		return [
			'show' => $filter['show'],
			'severities' => $filter['severities'],
			'name' => $filter['name'],
			'acknowledged' => $acknowledged,
			'show_suppressed' => (bool) $filter['show_suppressed'],
			'recent' => $recent,
			'time_from' => $time_from,
			'time_till' => $time_till,
			'tags' => $tags,
			'evaltype' => $filter['evaltype'],
			'limit' => CWebUser::$data['rows_per_page'] * (int) $filter['page'],
			'show_tags' => $filter['show_tags'] != SHOW_TAGS_NONE
		];
	}
}

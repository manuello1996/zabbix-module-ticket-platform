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


/**
 * @var CView $this
 * @var array $data
 */

$this->addJsFile('class.calendar.js');
$this->addJsFile('gtlc.js');
$this->addJsFile('items.js');
$this->addJsFile('multilineinput.js');

$filter = $data['filter'];
$problems = $data['problems'];

$server_options = [];
foreach ($data['servers'] as $server) {
	$server_options[] = [
		'label' => $server['name'],
		'value' => $server['id'],
		'checked' => in_array($server['id'], $filter['server_ids'])
	];
}

$severity_options = [];
foreach (CSeverityHelper::getSeverities() as $severity) {
	$severity_options[] = [
		'label' => $severity['label'],
		'value' => $severity['value'],
		'checked' => in_array($severity['value'], $filter['severities'])
	];
}

$tag_filter = $filter['tags'] ? $filter['tags'][0] : ['tag' => '', 'operator' => TAG_OPERATOR_LIKE, 'value' => ''];

$left_column = (new CFormList())
	->addRow(_('Show'),
		(new CRadioButtonList('show', (int) $filter['show']))
			->addValue(_('Recent problems'), TRIGGERS_OPTION_RECENT_PROBLEM, 'show_1')
			->addValue(_('Problems'), TRIGGERS_OPTION_IN_PROBLEM, 'show_2')
			->addValue(_('History'), TRIGGERS_OPTION_ALL, 'show_3')
			->setModern(true)
	)
	->addRow(_('Servers'),
		(new CCheckBoxList('server_ids'))
			->setOptions($server_options)
			->setColumns(2)
			->setVertical()
	)
	->addRow(_('Problem'),
		(new CTextBox('name', $filter['name']))
			->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
	)
	->addRow(_('Host'),
		(new CTextBox('host', $filter['host']))
			->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
	)
	->addRow(_('Severity'),
		(new CCheckBoxList('severities'))
			->setOptions($severity_options)
			->setColumns(3)
			->setVertical()
	);

$age_box = (new CNumericBox('age', $filter['age'], 3, false, false, false))
	->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
	->removeId();
if ($filter['age_state'] == 0) {
	$age_box->setAttribute('disabled', 'disabled');
}

$right_column = (new CFormList())
	->addRow(_('Age less than'), [
		(new CCheckBox('age_state'))
			->setChecked($filter['age_state'] == 1)
			->setUncheckedValue(0),
		$age_box,
		(new CDiv())->addClass(ZBX_STYLE_FORM_INPUT_MARGIN),
		_('days')
	])
	->addRow(_('Acknowledgement status'),
		(new CRadioButtonList('acknowledgement_status', (int) $filter['acknowledgement_status']))
			->addValue(_('All'), ZBX_ACK_STATUS_ALL, 'acknowledgement_status_0')
			->addValue(_('Unacknowledged'), ZBX_ACK_STATUS_UNACK, 'acknowledgement_status_1')
			->addValue(_('Acknowledged'), ZBX_ACK_STATUS_ACK, 'acknowledgement_status_2')
			->setModern(true)
	)
	->addRow(_('Show suppressed problems'),
		(new CCheckBox('show_suppressed'))
			->setChecked((int) $filter['show_suppressed'] === 1)
			->setUncheckedValue(0)
	)
	->addRow(_('Tag filter'), [
		(new CTextBox('tags[0][tag]', $tag_filter['tag']))
			->setAttribute('placeholder', _('tag'))
			->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH),
		(new CSelect('tags[0][operator]'))
			->addOptions(CSelect::createOptionsFromArray([
				TAG_OPERATOR_EXISTS => _('Exists'),
				TAG_OPERATOR_EQUAL => _('Equals'),
				TAG_OPERATOR_LIKE => _('Contains'),
				TAG_OPERATOR_NOT_EXISTS => _('Does not exist'),
				TAG_OPERATOR_NOT_EQUAL => _('Does not equal'),
				TAG_OPERATOR_NOT_LIKE => _('Does not contain')
			]))
			->setValue($tag_filter['operator']),
		(new CTextBox('tags[0][value]', $tag_filter['value']))
			->setAttribute('placeholder', _('value'))
			->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH)
	])
	->addRow(_('Show tags'), [
		(new CRadioButtonList('show_tags', (int) $filter['show_tags']))
			->addValue(_('None'), SHOW_TAGS_NONE, 'show_tags_0')
			->addValue(SHOW_TAGS_1, SHOW_TAGS_1, 'show_tags_1')
			->addValue(SHOW_TAGS_2, SHOW_TAGS_2, 'show_tags_2')
			->addValue(SHOW_TAGS_3, SHOW_TAGS_3, 'show_tags_3')
			->setModern(true)
	])
	->addRow(_('Tag name format'),
		(new CRadioButtonList('tag_name_format', (int) $filter['tag_name_format']))
			->addValue(_('Full'), TAG_NAME_FULL, 'tag_name_format_0')
			->addValue(_('Shortened'), TAG_NAME_SHORTENED, 'tag_name_format_1')
			->addValue(_('None'), TAG_NAME_NONE, 'tag_name_format_2')
			->setModern(true)
	);

$filter_box = (new CFilter())
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', $data['action']))
	->setProfile('web.problem.filter', 0)
	->addVar('action', $data['action'])
	->addTimeSelector($filter['from'], $filter['to'], true, 'web.problem.filter')
	->addFilterTab(_('Filter'), [$left_column, $right_column])
	->setActiveTab(2);

$url = (new CUrl('zabbix.php'))
	->setArgument('action', $data['action'])
	->setArgument('show', $filter['show'])
	->setArgument('name', $filter['name'])
	->setArgument('host', $filter['host'])
	->setArgument('sort', $filter['sort'])
	->setArgument('sortorder', $filter['sortorder']);

foreach ($filter['server_ids'] as $server_id) {
	$url->setArgument('server_ids[]', $server_id);
}
foreach ($filter['severities'] as $severity) {
	$url->setArgument('severities[]', $severity);
}
if ($filter['age_state']) {
	$url->setArgument('age_state', $filter['age_state']);
	$url->setArgument('age', $filter['age']);
}
$url->setArgument('acknowledgement_status', $filter['acknowledgement_status']);
$url->setArgument('show_suppressed', $filter['show_suppressed']);
$url->setArgument('show_tags', $filter['show_tags']);
$url->setArgument('tag_name_format', $filter['tag_name_format']);
if ($filter['tags']) {
	$url->setArgument('tags[0][tag]', $tag_filter['tag']);
	$url->setArgument('tags[0][operator]', $tag_filter['operator']);
	$url->setArgument('tags[0][value]', $tag_filter['value']);
}
$url->setArgument('from', $filter['from']);
$url->setArgument('to', $filter['to']);

	$refresh_form = (new CForm('get'))
		->addVar('action', $data['action'])
		->addVar('refresh_status', 1)
		->addVar('filter_set', 1)
		->addVar('show', $filter['show'])
		->addVar('name', $filter['name'])
		->addVar('host', $filter['host'])
		->addVar('sort', $filter['sort'])
		->addVar('sortorder', $filter['sortorder'])
		->addVar('page', $filter['page']);

	foreach ($filter['server_ids'] as $server_id) {
		$refresh_form->addVar('server_ids[]', $server_id);
	}
	foreach ($filter['severities'] as $severity) {
		$refresh_form->addVar('severities[]', $severity);
	}
	if ($filter['age_state']) {
		$refresh_form->addVar('age_state', $filter['age_state']);
		$refresh_form->addVar('age', $filter['age']);
	}
	$refresh_form->addVar('acknowledgement_status', $filter['acknowledgement_status']);
	$refresh_form->addVar('show_suppressed', $filter['show_suppressed']);
	$refresh_form->addVar('show_tags', $filter['show_tags']);
	$refresh_form->addVar('tag_name_format', $filter['tag_name_format']);
	if ($filter['tags']) {
		$refresh_form->addVar('tags[0][tag]', $tag_filter['tag']);
		$refresh_form->addVar('tags[0][operator]', $tag_filter['operator']);
		$refresh_form->addVar('tags[0][value]', $tag_filter['value']);
	}
	$refresh_form->addVar('from', $filter['from']);
	$refresh_form->addVar('to', $filter['to']);
	$refresh_form->addItem(new CSubmit('refresh', _('Refresh status')));

	if ($problems) {
		usort($problems, function ($a, $b) use ($filter) {
			switch ($filter['sort']) {
			case 'host':
				$left = implode(', ', array_map(function ($host) {
					return $host['name'];
				}, $a['hosts']));
				$right = implode(', ', array_map(function ($host) {
					return $host['name'];
				}, $b['hosts']));
				break;
			case 'severity':
				$left = $a['severity'];
				$right = $b['severity'];
				break;
			case 'name':
				$left = $a['name'];
				$right = $b['name'];
				break;
			case 'server':
				$left = $a['server_name'];
				$right = $b['server_name'];
				break;
			case 'clock':
			default:
				$left = $a['clock'];
				$right = $b['clock'];
				break;
		}

		if ($left == $right) {
			return 0;
		}

		$result = ($left < $right) ? -1 : 1;
		return $filter['sortorder'] == ZBX_SORT_DOWN ? -$result : $result;
	});
}

$paging = CPagerHelper::paginate($filter['page'], $problems, $filter['sortorder'], $url);

$table_header = [
	_('Time'),
	_('Severity'),
	_('Status'),
	_('Recovery time'),
	_('Origin server'),
	_('Host'),
	_('Problem'),
	_('Duration'),
	_('Acknowledged'),
	_('Update'),
	_('Actions')
];

if ($filter['show_tags'] != SHOW_TAGS_NONE) {
	array_splice($table_header, count($table_header), 0, [_('Tags')]);
}

$problems_table = (new CTableInfo())
	->setHeader($table_header)
	->setPageNavigation($paging);

$tags_by_event = [];
if ($filter['show_tags'] != SHOW_TAGS_NONE) {
	$tags_by_event = makeTags($problems, true, 'eventid', (int) $filter['show_tags'], $filter['tags'], null,
		(int) $filter['tag_name_format']
	);
}

foreach ($problems as $problem) {
	$time_text = zbx_date2str(DATE_TIME_FORMAT_SECONDS, $problem['clock']);
	if (!empty($problem['objectid'])) {
		$time_text = new CLink($time_text,
			(new CUrl('zabbix.php'))
				->setArgument('action', 'ticket.platform.eventdetails')
				->setArgument('server_id', $problem['server_id'])
				->setArgument('eventid', $problem['eventid'])
				->setArgument('triggerid', $problem['objectid'])
		);
	}

	$acknowledged = (new CSpan($problem['acknowledged'] ? _('Yes') : _('No')))->addClass(
		$problem['acknowledged'] ? ZBX_STYLE_GREEN : ZBX_STYLE_RED
	);

	$status = (new CSpan($problem['r_eventid'] ? _('RESOLVED') : _('PROBLEM')))->addClass(
		$problem['r_eventid'] ? ZBX_STYLE_GREEN : ZBX_STYLE_RED
	);

	$recovery_time = '';
	if (!empty($problem['r_eventid']) && !empty($problem['r_clock'])) {
		$recovery_time = zbx_date2str(DATE_TIME_FORMAT_SECONDS, $problem['r_clock']);
	}

	$host_list = '';
	if ($problem['hosts']) {
		$host_links = [];
		foreach ($problem['hosts'] as $host) {
			if ($problem['server_id'] === 'local') {
				$host_links[] = (new CLinkAction($host['name']))
					->setMenuPopup(CMenuPopupHelper::getHost($host['hostid']));
				continue;
			}

			$host_problems_url = (new CUrl('zabbix.php'))
				->setArgument('action', 'ticket.platform')
				->setArgument('show', $filter['show'])
				->setArgument('server_ids[]', $problem['server_id'])
				->setArgument('host', $host['name'])
				->setArgument('filter_set', 1)
				->getUrl();
			$host_popup = 'javascript:ticketPlatformRemoteHostPopUp("'.$host['hostid'].'","'
				.$problem['server_id'].'");';

			$host_links[] = (new CLinkAction($host['name']))
				->setMenuPopup([
					'type' => 'submenu',
					'data' => [
						'submenu' => [
							'view' => [
								'label' => _('View'),
								'items' => [
									$host_problems_url => _('Problems')
								]
							],
							'configuration' => [
								'label' => _('Configuration'),
								'items' => [
									$host_popup => _('Host')
								]
							]
						]
					]
				]);
		}

		$host_items = [];
		foreach ($host_links as $index => $link) {
			if ($index > 0) {
				$host_items[] = ', ';
			}
			$host_items[] = $link;
		}
		$host_list = new CSpan($host_items);
	}

	$update_link = (new CLink(_('Update')))
		->setAttribute('data-eventid', $problem['eventid'])
		->setAttribute('data-serverid', $problem['server_id'])
		->onClick('window.ticketPlatformAcknowledgePopUp({eventids: [this.dataset.eventid], server_id: this.dataset.serverid}, this);');

	$actions_icon = '';
	if (($problem['actions_count'] ?? 0) > 0) {
		$actions_icon = (new CButtonIcon(ZBX_ICON_BULLET_RIGHT_WITH_CONTENT))
			->setAttribute('data-content', $problem['actions_count'])
			->setAttribute('aria-label',
				_xn('%1$s action', '%1$s actions', $problem['actions_count'], 'screen reader', $problem['actions_count'])
			)
			->setAjaxHint([
				'action' => 'ticket.platform.actionlist',
				'data' => [
					'eventid' => $problem['eventid'],
					'server_id' => $problem['server_id']
				]
			], ZBX_STYLE_HINTBOX_WRAP_HORIZONTAL);

		if (!empty($problem['actions_has_failed'])) {
			$actions_icon->addClass(ZBX_STYLE_COLOR_NEGATIVE);
		}
		elseif (!empty($problem['actions_has_uncomplete'])) {
			$actions_icon->addClass(ZBX_STYLE_COLOR_WARNING);
		}
	}

	$problem_cell = $problem['name'];
	if (!empty($problem['objectid'])) {
		$problems_url = (new CUrl('zabbix.php'))
			->setArgument('action', 'ticket.platform')
			->setArgument('show', $filter['show'])
			->setArgument('server_ids[]', $problem['server_id'])
			->setArgument('name', $problem['name'])
			->setArgument('filter_set', 1)
			->getUrl();
		$trigger_popup = 'javascript:ticketPlatformTriggerPopUp("'.$problem['objectid'].'","'
			.$problem['server_id'].'");';

		$problem_link = (new CLinkAction($problem['name']))->addClass(ZBX_STYLE_WORDBREAK);

		if ($problem['server_id'] === 'local') {
			$problem_link->setMenuPopup(CMenuPopupHelper::getTrigger([
				'triggerid' => $problem['objectid'],
				'eventid' => $problem['eventid'],
				'backurl' => $url->getUrl()
			]));
		}
		else {
			$item_links = [];
			foreach ($problem['items'] ?? [] as $item) {
				if (!array_key_exists('itemid', $item)) {
					continue;
				}
				$item_links['javascript:ticketPlatformRemoteItemPopUp("'.$item['itemid'].'","'
					.$problem['server_id'].'");'] = $item['name'] ?? $item['itemid'];
			}
			if (!$item_links) {
				$item_links['javascript:void(0)'] = _('No items');
			}

			$problem_link->setMenuPopup([
				'type' => 'submenu',
				'data' => [
					'submenu' => [
						'view' => [
							'label' => _('View'),
							'items' => [
								$problems_url => _('Problems')
							]
						],
						'configuration' => [
							'label' => _('Configuration'),
							'items' => [
								$trigger_popup => _('Trigger'),
								'items' => [
									'label' => _('Items'),
									'items' => $item_links
								]
							]
						]
					]
				]
			]);
		}

		$problem_cell = $problem_link;
	}

	$row = [
		$time_text,
		CSeverityHelper::makeSeverityCell($problem['severity']),
		$status,
		$recovery_time,
		$problem['server_name'],
		$host_list,
		$problem_cell,
		zbx_date2age($problem['clock']),
		$acknowledged,
		$update_link,
		$actions_icon
	];

	if ($filter['show_tags'] != SHOW_TAGS_NONE) {
		$row[] = $tags_by_event[$problem['eventid']] ?? '';
	}

	$problems_table->addRow($row);
}

$page = (new CHtmlPage())
	->setTitle(_('Ticket Platform'))
	->setDocUrl(CDocHelper::getUrl(CDocHelper::MONITORING_PROBLEMS_VIEW))
	->addItem($filter_box);

if ($data['errors']) {
	$error_messages = [];
	foreach ($data['errors'] as $error) {
		$error_messages[] = ['message' => $error['server'].': '.$error['error']];
	}
	$page->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING, $error_messages, null, true));
}

$page
	->addItem($problems_table)
	->addItem($refresh_form)
	->show();

(new CScriptTag('
	window.ticketPlatformAcknowledgePopUp = function(parameters, trigger_element) {
		if (parameters.server_id === "local" || parameters.server_id === undefined || parameters.server_id === null) {
			return acknowledgePopUp({eventids: parameters.eventids}, trigger_element);
		}

		return PopUp("ticket.platform.ack.edit", parameters,
			{dialogue_class: "modal-popup-generic", trigger_element: trigger_element}
		);
	};

	window.ticketPlatformTriggerPopUp = function(triggerid, serverid, trigger_element) {
		return PopUp("ticket.platform.trigger.popup",
			{triggerid: triggerid, server_id: serverid},
			{dialogue_class: "modal-popup-generic", trigger_element: trigger_element}
		);
	};

	window.ticketPlatformRemoteItemPopUp = function(itemid, serverid, trigger_element) {
		return PopUp("ticket.platform.item.popup",
			{itemid: itemid, server_id: serverid},
			{dialogue_class: "modal-popup-large", trigger_element: trigger_element}
		);
	};

	window.ticketPlatformRemoteHostPopUp = function(hostid, serverid, trigger_element) {
		return PopUp("ticket.platform.host.popup",
			{hostid: hostid, server_id: serverid},
			{dialogueid: "host_edit", dialogue_class: "modal-popup-large", trigger_element: trigger_element}
		);
	};

	if (typeof $.subscribe === "function") {
		$.subscribe("acknowledge.create", function() {
			location.reload();
		});

		$.subscribe("ticketplatform.host.update", function() {
			location.reload();
		});

		$.subscribe("timeselector.rangeupdate", function(e, data) {
			var $form = jQuery("form[name=zbx_filter]");

			if ($form.length) {
				var $from = $form.find("input[name=from]");
				if (!$from.length) {
					$from = jQuery("<input>", {type: "hidden", name: "from"}).appendTo($form);
				}
				$from.val(data.from);

				var $to = $form.find("input[name=to]");
				if (!$to.length) {
					$to = jQuery("<input>", {type: "hidden", name: "to"}).appendTo($form);
				}
				$to.val(data.to);

				var $filterSet = $form.find("input[name=filter_set]");
				if (!$filterSet.length) {
					$filterSet = jQuery("<input>", {type: "hidden", name: "filter_set"}).appendTo($form);
				}
				$filterSet.val("1");

				$form[0].submit();
			}
			else {
				location.reload();
			}
		});
	}

	window.view = window.view || {};
	window.view.editHost = function(hostid) {
		clearMessages();

		var original_url = location.href;
		var overlay = PopUp("popup.host.edit", {hostid: hostid}, {
			dialogueid: "host_edit",
			dialogue_class: "modal-popup-large",
			prevent_navigation: true
		});

		overlay.$dialogue[0].addEventListener("dialogue.submit", function() {
			location.reload();
		}, {once: true});
		overlay.$dialogue[0].addEventListener("dialogue.close", function() {
			history.replaceState({}, "", original_url);
		}, {once: true});
	};

	window.view.editItem = function(target, data) {
		clearMessages();

		var overlay = PopUp("item.edit", data, {
			dialogueid: "item-edit",
			dialogue_class: "modal-popup-large",
			trigger_element: target,
			prevent_navigation: true
		});

		overlay.$dialogue[0].addEventListener("dialogue.submit", function() {
			location.reload();
		}, {once: true});
	};
'))->show();

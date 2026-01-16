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

if ($problems) {
	usort($problems, function ($a, $b) use ($filter) {
		switch ($filter['sort']) {
			case 'host':
				$left = implode(', ', $a['hosts']);
				$right = implode(', ', $b['hosts']);
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
	_('Origin server'),
	_('Host'),
	_('Problem'),
	_('Duration'),
	_('Acknowledged'),
	_('Update'),
	_('Actions')
];

if ($filter['show_tags'] != SHOW_TAGS_NONE) {
	array_splice($table_header, 10, 0, [_('Tags')]);
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

	$host_list = $problem['hosts'] ? implode(', ', $problem['hosts']) : '';

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

	$row = [
		$time_text,
		CSeverityHelper::makeSeverityCell($problem['severity']),
		$status,
		$problem['server_name'],
		$host_list,
		$problem['name'],
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

if (array_key_exists('debug', $data) && $data['debug']) {
	$debug_messages = [];
	foreach ($data['debug'] as $message) {
		$debug_messages[] = ['message' => $message];
	}
	$page->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING, $debug_messages, _('Ticket Platform debug'), true));
}

$page
	->addItem($problems_table)
	->show();

(new CScriptTag('
	window.ticketPlatformAcknowledgePopUp = function(parameters, trigger_element) {
		return PopUp("ticket.platform.ack.edit", parameters,
			{dialogue_class: "modal-popup-generic", trigger_element: trigger_element}
		);
	};

	if (typeof $.subscribe === "function") {
		$.subscribe("acknowledge.create", function() {
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
'))->show();

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

require_once dirname(__FILE__).'/../../../include/events.inc.php';

if (array_key_exists('error', $data)) {
	$page = (new CHtmlPage())
		->setTitle(_('Event details'))
		->addItem(makeMessageBox(ZBX_STYLE_MSG_BAD, [['message' => $data['error']['title']]], null, true));

	if (array_key_exists('messages', $data['error'])) {
		$messages = [];
		foreach ($data['error']['messages'] as $message) {
			$messages[] = ['message' => $message];
		}
		$page->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING, $messages, null, true));
	}

	$page->show();
	return;
}

$server = $data['server'];
$trigger = $data['trigger'];
$event = $data['event'];
$cause_event = $data['cause_event'];
$event_list = $data['event_list'];
$event_list_actions = $data['event_list_actions'];

$hosts = [];
if (array_key_exists('hosts', $trigger)) {
	foreach ($trigger['hosts'] as $host) {
		$hosts[] = $host['name'] !== '' ? $host['name'] : $host['host'];
	}
}

$trigger_name = array_key_exists('description', $trigger) ? $trigger['description'] : $trigger['triggerid'];
$opdata = array_key_exists('opdata', $data) ? $data['opdata'] : _('N/A');
$comments = $trigger['comments'] ?? '';

$trigger_details = (new CTableInfo())
	->addRow([_n('Host', 'Hosts', count($hosts)), implode(', ', $hosts)])
	->addRow([_('Trigger'), (new CCol($trigger_name))->addClass(ZBX_STYLE_WORDBREAK)])
	->addRow([_('Severity'), CSeverityHelper::makeSeverityCell((int) $trigger['priority'])])
	->addRow([_('Problem expression'), (new CCol((new CDiv($trigger['expression']))
		->addClass(ZBX_STYLE_WORDBREAK)))])
	->addRow([_('Recovery expression'), (new CCol((new CDiv($trigger['recovery_expression']))
		->addClass(ZBX_STYLE_WORDBREAK)))])
	->addRow([_('Event generation'),
		_('Normal').((TRIGGER_MULT_EVENT_ENABLED == $trigger['type']) ? ' + '._('Multiple PROBLEM events') : '')
	])
	->addRow([_('Allow manual close'), ($trigger['manual_close'] == ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED)
		? (new CCol(_('Yes')))->addClass(ZBX_STYLE_GREEN)
		: (new CCol(_('No')))->addClass(ZBX_STYLE_RED)
	])
	->addRow([_('Enabled'), ($trigger['status'] == TRIGGER_STATUS_ENABLED)
		? (new CCol(_('Yes')))->addClass(ZBX_STYLE_GREEN)
		: (new CCol(_('No')))->addClass(ZBX_STYLE_RED)
	]);

$is_acknowledged = ($event['acknowledged'] == EVENT_ACKNOWLEDGED);

$tags = makeTags([$event]);
$event_details = (new CTableInfo())
	->addRow([_('Event'), (new CCol($event['name']))->addClass(ZBX_STYLE_WORDBREAK)])
	->addRow([_('Operational data'), (new CCol($opdata))->addClass(ZBX_STYLE_WORDBREAK)])
	->addRow([_('Severity'), CSeverityHelper::makeSeverityCell((int) $event['severity'])])
	->addRow([_('Time'), zbx_date2str(DATE_TIME_FORMAT_SECONDS, $event['clock'])])
	->addRow([_('Acknowledged'),
		(new CSpan($is_acknowledged ? _('Yes') : _('No')))
			->addClass($is_acknowledged ? ZBX_STYLE_GREEN : ZBX_STYLE_RED)
	])
	->addRow([_('Tags'), $tags[$event['eventid']] ?? ''])
	->addRow([_('Description'), (new CDiv(zbx_str2links($comments)))->addClass(ZBX_STYLE_WORDBREAK)]);

if ($event['cause_eventid'] == 0) {
	$event_details->addRow([_('Rank'), _('Cause')]);
}
elseif ($cause_event !== null) {
	$cause_link = new CLink(
		$cause_event['name'],
		(new CUrl('zabbix.php'))
			->setArgument('action', 'ticket.platform.eventdetails')
			->setArgument('server_id', $server['id'])
			->setArgument('eventid', $cause_event['eventid'])
			->setArgument('triggerid', $cause_event['objectid'])
	);
	$event_details->addRow([_('Rank'), [_('Symptom'), ' (', $cause_link, ')']]);
}

$actions_table = makeEventDetailsActionsTable(['actions' => $data['actions']], $data['users'], $data['mediatypes']);

$event_list_table = (new CTableInfo())
	->setHeader([
		_('Time'),
		_('Recovery time'),
		_('Status'),
		_('Age'),
		_('Duration'),
		_('Update'),
		_('Actions')
	]);

foreach ($event_list as $row) {
	$duration = ($row['r_eventid'] != 0)
		? zbx_date2age($row['clock'], $row['r_clock'])
		: zbx_date2age($row['clock']);

	$in_closing = false;
	if ($row['r_eventid'] != 0) {
		$value = TRIGGER_VALUE_FALSE;
		$value_clock = $row['r_clock'];
	}
	else {
		if (hasEventCloseAction($row['acknowledges'])) {
			$in_closing = true;
		}

		$value = $in_closing ? TRIGGER_VALUE_FALSE : TRIGGER_VALUE_TRUE;
		$value_clock = $in_closing ? time() : $row['clock'];
	}

	$value_str = getEventStatusString($in_closing, $row);
	$is_acknowledged = ($row['acknowledged'] == EVENT_ACKNOWLEDGED);
	$cell_status = new CSpan($value_str);

	if (isEventUpdating($in_closing, $row)) {
		$cell_status->addClass('js-blink');
	}

	addTriggerValueStyle($cell_status, $value, $value_clock, $is_acknowledged);

	$update_link = (new CLink(_('Update')))
		->addClass(ZBX_STYLE_LINK_ALT)
		->setAttribute('data-eventid', $row['eventid'])
		->setAttribute('data-serverid', $server['id'])
		->onClick('window.ticketPlatformAcknowledgePopUp({eventids: [this.dataset.eventid], server_id: this.dataset.serverid}, this);');

	$actions_icon = '';
	if (array_key_exists($row['eventid'], $event_list_actions)) {
		$summary = $event_list_actions[$row['eventid']];
		if ($summary['count'] > 0) {
			$actions_icon = (new CButtonIcon(ZBX_ICON_BULLET_RIGHT_WITH_CONTENT))
				->setAttribute('data-content', $summary['count'])
				->setAttribute('aria-label',
					_xn('%1$s action', '%1$s actions', $summary['count'], 'screen reader', $summary['count'])
				)
				->setAjaxHint([
					'action' => 'ticket.platform.actionlist',
					'data' => [
						'eventid' => $row['eventid'],
						'server_id' => $server['id']
					]
				], ZBX_STYLE_HINTBOX_WRAP_HORIZONTAL);

			if ($summary['has_failed']) {
				$actions_icon->addClass(ZBX_STYLE_COLOR_NEGATIVE);
			}
			elseif ($summary['has_uncomplete']) {
				$actions_icon->addClass(ZBX_STYLE_COLOR_WARNING);
			}
		}
	}

	$event_list_table->addRow([
		new CLink(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $row['clock']),
			(new CUrl('zabbix.php'))
				->setArgument('action', 'ticket.platform.eventdetails')
				->setArgument('server_id', $server['id'])
				->setArgument('eventid', $row['eventid'])
				->setArgument('triggerid', $row['objectid'])
		),
		($row['r_eventid'] == 0)
			? ''
			: new CLink(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $row['r_clock']),
				(new CUrl('zabbix.php'))
					->setArgument('action', 'ticket.platform.eventdetails')
					->setArgument('server_id', $server['id'])
					->setArgument('eventid', $row['eventid'])
					->setArgument('triggerid', $row['objectid'])
			),
		$cell_status,
		zbx_date2age($row['clock']),
		$duration,
		$update_link,
		$actions_icon
	]);
}

$event_tab = (new CDiv([
	new CDiv([
		(new CSection($trigger_details))
			->setId(SECTION_HAT_TRIGGERDETAILS)
			->setHeader(new CTag('h4', true, _('Trigger details'))),
		(new CSection($event_details))
			->setId(SECTION_HAT_EVENTDETAILS)
			->setHeader(new CTag('h4', true, _('Event details')))
	]),
	new CDiv([
		(new CSectionCollapsible($actions_table))
			->setId(SECTION_HAT_EVENTACTIONS)
			->setHeader(new CTag('h4', true, _('Actions')))
			->setExpanded(true),
		(new CSectionCollapsible($event_list_table))
			->setId(SECTION_HAT_EVENTLIST)
			->setHeader(new CTag('h4', true, _('Event list [previous 20]')))
			->setExpanded(true)
	])
]))
	->addClass(ZBX_STYLE_COLUMNS)
	->addClass(ZBX_STYLE_COLUMNS_2);

(new CHtmlPage())
	->setTitle(_('Event details'))
	->addItem($event_tab)
	->show();

(new CScriptTag('
	window.ticketPlatformAcknowledgePopUp = function(parameters, trigger_element) {
		return PopUp("ticket.platform.ack.edit", parameters,
			{dialogue_class: "modal-popup-generic", trigger_element: trigger_element}
		);
	};
'))->show();

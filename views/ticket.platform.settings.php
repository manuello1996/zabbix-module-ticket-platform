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

$settings_form = (new CForm('post'))
	->setName('ticket_platform_settings')
	->addVar('action', $data['action']);

$settings_form_list = (new CFormList())
	->addRow(_('Cache TTL (seconds)'),
		(new CNumericBox('cache_ttl', $data['cache_ttl'], 5))
			->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
	)
	->addRow(_('Local server name'),
		(new CTextBox('local_server_name', $data['local_server_name']))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	);

$settings_form->addItem($settings_form_list);
$settings_form->addItem(
	(new CSubmitButton(_('Save')))->setName('save_settings')
);

$servers_table = (new CTableInfo())
	->setHeader([
		_('Name'),
		_('API URL'),
		_('API version'),
		_('Connection'),
		_('Last reached'),
		_('Host group'),
		_('Include subgroups'),
		_('Enabled'),
		_('Token'),
		_('Actions')
	]);

foreach ($data['servers'] as $server) {
	$delete_form = (new CForm('post'))
		->addVar('action', $data['action'])
		->addVar('delete_id', $server['id'])
		->addItem(
			(new CSubmitButton(_('Delete')))
				->addClass(ZBX_STYLE_BTN_ALT)
				->addClass(ZBX_STYLE_COLOR_NEGATIVE)
		);

	$reset_form = (new CForm('post'))
		->addVar('action', $data['action'])
		->addVar('reset_cache_id', $server['id'])
		->addItem((new CSubmitButton(_('Reset cache')))->addClass(ZBX_STYLE_BTN_ALT));

	$check_form = (new CForm('post'))
		->addVar('action', $data['action'])
		->addVar('check_connection_id', $server['id'])
		->addItem((new CSubmitButton(_('Check connection')))->addClass(ZBX_STYLE_BTN_ALT));

	$actions = [
		(new CLink(_('Edit'), (new CUrl('zabbix.php'))
			->setArgument('action', 'ticket.platform.settings.edit')
			->setArgument('server_id', $server['id'])
		)),
		$check_form,
		$reset_form,
		$delete_form
	];

	$connection_status = $server['connection_status'] ?? '';
	$connection_label = _('Unknown');
	if ($connection_status === 'ok') {
		$connection_label = _('OK');
	}
	elseif ($connection_status === 'problem') {
		$connection_label = _('Problem');
	}

	$last_reached = $server['last_reached'] ?? 0;
	$last_reached_label = $last_reached > 0
		? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $last_reached)
		: _('Never');

	$servers_table->addRow([
		$server['name'],
		$server['api_url'],
		($server['api_version'] ?? '') !== '' ? $server['api_version'] : _('Unknown'),
		$connection_label,
		$last_reached_label,
		$server['hostgroup'] !== '' ? $server['hostgroup'] : _('All'),
		$server['include_subgroups'] ? _('Yes') : _('No'),
		$server['enabled'] ? _('Yes') : _('No'),
		$server['api_token'] !== '' ? '********' : '',
		$actions
	]);
}

$add_button = new CLink(_('Add server'), (new CUrl('zabbix.php'))
	->setArgument('action', 'ticket.platform.settings.edit')
);

(new CHtmlPage())
	->setTitle(_('Ticket Platform settings'))
	->addItem(new CTag('h1', true, _('Ticket Platform settings')))
	->addItem(
		$data['message_type'] !== null && $data['message'] !== null
			? makeMessageBox(
				$data['message_type'],
				array_map(function ($message) {
					return ['message' => $message];
				}, $data['message']),
				$data['message_title'],
				true
			)
			: null
	)
	->addItem($settings_form)
	->addItem((new CDiv())->addItem($add_button))
	->addItem($servers_table)
	->show();

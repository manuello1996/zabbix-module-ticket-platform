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
	);

$settings_form->addItem($settings_form_list);
$settings_form->addItem(
	(new CSubmitButton(_('Save')))->setName('save_settings')
);

$servers_table = (new CTableInfo())
	->setHeader([
		_('Name'),
		_('API URL'),
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
		->addItem((new CSubmitButton(_('Delete')))->addClass(ZBX_STYLE_BTN_ALT));

	$actions = [
		(new CLink(_('Edit'), (new CUrl('zabbix.php'))
			->setArgument('action', 'ticket.platform.settings.edit')
			->setArgument('server_id', $server['id'])
		)),
		$delete_form
	];

	$servers_table->addRow([
		$server['name'],
		$server['api_url'],
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
	->addItem($settings_form)
	->addItem((new CDiv())->addItem($add_button))
	->addItem($servers_table)
	->show();

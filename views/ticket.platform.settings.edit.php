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

$server = $data['server'];
$is_edit = $server['id'] !== '';

$form = (new CForm('post'))
	->setName('ticket_platform_settings_edit')
	->addVar('action', $data['action']);

if ($is_edit) {
	$form->addVar('server_id', $server['id']);
}

$form_list = (new CFormList())
	->addRow(_('Zabbix Name'), (new CTextBox('name', $server['name']))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH))
	->addRow(_('API URL'), (new CTextBox('api_url', $server['api_url']))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH))
	->addRow(_('API Token'),
		(new CTextBox('api_token', $server['api_token']))
			->setAttribute('type', 'password')
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	)
	->addRow(_('Host group'),
		(new CTextBox('hostgroup', $server['hostgroup']))
			->setAttribute('placeholder', _('All'))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	)
	->addRow(_('Include subgroups'),
		(new CCheckBox('include_subgroups'))
			->setChecked($server['include_subgroups'] == 1)
			->setUncheckedValue(0)
	)
	->addRow(_('Enabled'),
		(new CCheckBox('enabled'))
			->setChecked($server['enabled'] == 1)
			->setUncheckedValue(0)
	);

$form->addItem($form_list);
$form->addItem((new CSubmitButton(_('Save')))->setName('save'));
$form->addItem(
	(new CLink(_('Cancel'), (new CUrl('zabbix.php'))->setArgument('action', 'ticket.platform.settings')))
		->addClass(ZBX_STYLE_COLOR_NEGATIVE)
);

$page = (new CHtmlPage())
	->setTitle(_('Ticket Platform settings'))
	->addItem(new CTag('h1', true, $is_edit ? _('Edit server') : _('Add server')));

if (array_key_exists('error', $data)) {
	$page->addItem(
		makeMessageBox(
			ZBX_STYLE_MSG_BAD,
			array_map(function ($message) {
				return ['message' => $message];
			}, $data['error']['messages']),
			$data['error']['title'],
			true
		)
	);
}

$page->addItem($form)
	->show();

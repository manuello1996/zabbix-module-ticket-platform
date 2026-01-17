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

require_once 'include/forms.inc.php';

if (array_key_exists('error', $data)) {
	echo json_encode([
		'header' => _('Item'),
		'body' => makeMessageBox(ZBX_STYLE_MSG_BAD, [['message' => $data['error']['title']]], null, true)->toString(),
		'buttons' => []
	]);
	return;
}

$item = $data['item'];
$hosts = [];
if (array_key_exists('hosts', $item)) {
	foreach ($item['hosts'] as $host) {
		$hosts[] = $host['name'] !== '' ? $host['name'] : $host['host'];
	}
}

$mask_keys = ['password', 'ssl_key_password', 'privatekey'];
foreach ($mask_keys as $mask_key) {
	if (array_key_exists($mask_key, $item) && $item[$mask_key] !== '') {
		$item[$mask_key] = '********';
	}
}

$selectBox = function (string $name, array $options, $value) {
	$select = new CSelect($name);
	foreach ($options as $key => $label) {
		$select->addOption(new CSelectOption($key, $label));
	}
	$select->setValue($value);
	return $select->setReadonly(true);
};

$value_types = [
	ITEM_VALUE_TYPE_UINT64 => _('Numeric (unsigned)'),
	ITEM_VALUE_TYPE_FLOAT => _('Numeric (float)'),
	ITEM_VALUE_TYPE_STR => _('Character'),
	ITEM_VALUE_TYPE_LOG => _('Log'),
	ITEM_VALUE_TYPE_TEXT => _('Text'),
	ITEM_VALUE_TYPE_BINARY => _('Binary')
];

$item_tab = (new CFormGrid())
	->addItem([
		new CLabel(_n('Host', 'Hosts', count($hosts))),
		new CFormField(implode(', ', $hosts))
	])
	->addItem([
		(new CLabel(_('Name'), 'name'))->setAsteriskMark(),
		new CFormField(
			(new CTextBox('name', $item['name'] ?? ''))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setReadonly(true)
		)
	])
	->addItem([
		new CLabel(_('Type'), 'type'),
		new CFormField($selectBox('type', item_type2str(), $item['type'] ?? null))
	])
	->addItem([
		(new CLabel(_('Key'), 'key_'))->setAsteriskMark(),
		new CFormField(
			(new CTextBox('key_', $item['key_'] ?? ''))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setReadonly(true)
		)
	])
	->addItem([
		new CLabel(_('Type of information'), 'value_type'),
		new CFormField($selectBox('value_type', $value_types, $item['value_type'] ?? null))
	])
	->addItem([
		new CLabel(_('Units'), 'units'),
		new CFormField(
			(new CTextBox('units', $item['units'] ?? ''))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setReadonly(true)
		)
	])
	->addItem([
		(new CLabel(_('Update interval'), 'delay'))->setAsteriskMark(),
		new CFormField(
			(new CTextBox('delay', $item['delay'] ?? ''))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setReadonly(true)
		)
	])
	->addItem([
		(new CLabel(_('History'), 'history'))->setAsteriskMark(),
		new CFormField(
			(new CTextBox('history', $item['history'] ?? ''))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setReadonly(true)
		)
	])
	->addItem([
		(new CLabel(_('Trends'), 'trends'))->setAsteriskMark(),
		new CFormField(
			(new CTextBox('trends', $item['trends'] ?? ''))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setReadonly(true)
		)
	])
	->addItem([
		new CLabel(_('Value mapping'), 'valuemap'),
		new CFormField(
			(new CTextBox('valuemap', $item['valuemap']['name'] ?? ''))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setReadonly(true)
		)
	])
	->addItem([
		new CLabel(_('Description'), 'description'),
		new CFormField(
			(new CTextArea('description', $item['description'] ?? ''))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setAttribute('rows', 4)
				->setReadonly(true)
		)
	])
	->addItem([
		new CLabel(_('Enabled'), 'status'),
		new CFormField(
			(new CCheckBox('status'))
				->setChecked(($item['status'] ?? ITEM_STATUS_ACTIVE) == ITEM_STATUS_ACTIVE)
				->setUncheckedValue(ITEM_STATUS_DISABLED)
				->setReadonly(true)
		)
	])
	->addItem([
		new CLabel(_('Last value'), 'lastvalue'),
		new CFormField(
			(new CTextBox('lastvalue', $item['lastvalue'] ?? ''))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setReadonly(true)
		)
	])
	->addItem([
		new CLabel(_('Last check'), 'lastclock'),
		new CFormField(
			(new CTextBox('lastclock',
				(array_key_exists('lastclock', $item) && (int) $item['lastclock'] != 0)
					? zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $item['lastclock'])
					: ''
			))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setReadonly(true)
		)
	]);

$tags_tab = new CPartial('configuration.tags.tab', [
	'readonly' => true,
	'show_inherited_tags' => 0,
	'source' => 'item',
	'tabs_id' => 'ticket-platform-item-tab',
	'tags_tab_id' => 'tags-tab',
	'tags' => $item['tags'] ?? []
]);

$preprocessing_tab = new CPartial('item.edit.preprocessing.tab', [
	'item' => $item,
	'preprocessing' => $item['preprocessing'] ?? [],
	'preprocessing_types' => CItem::SUPPORTED_PREPROCESSING_TYPES,
	'readonly' => true,
	'value_types' => $value_types
]);

$tabs = (new CTabView(['id' => 'ticket-platform-item-tab', 'selected' => 0]))
	->setSelected(0)
	->addTab('item-tab', _('Item'), $item_tab)
	->addTab('tags-tab', _('Tags'), $tags_tab, TAB_INDICATOR_TAGS)
	->addTab('processing-tab', _('Preprocessing'), $preprocessing_tab, TAB_INDICATOR_PREPROCESSING);

$form = (new CForm())
	->setId('item-form')
	->setName('itemForm')
	->addItem($tabs);

$output = [
	'header' => _('Item'),
	'body' => $form->toString(),
	'buttons' => [],
	'script_inline' => getPagePostJs()
];

echo json_encode($output);

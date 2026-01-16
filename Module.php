<?php declare(strict_types = 0);
/*
** Zabbix
** Copyright (C) 2001-2023 Zabbix SIA
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


namespace Modules\TicketPlatform;

use Zabbix\Core\CModule,
	APP,
	CMenu,
	CMenuItem;

class Module extends CModule {

	public function init(): void {
		APP::Component()->get('menu.main')
			->findOrAdd(_('Monitoring'))
			->getSubmenu()
			->insertAfter(_('Problems'),
				(new CMenuItem(_('Ticket Platform')))->setAction('ticket.platform')
			);

		APP::Component()->get('menu.main')
			->findOrAdd(_('Administration'))
			->getSubmenu()
			->insertAfter(_('General'),
				(new CMenuItem(_('Ticket Platform')))->setAction('ticket.platform.settings')
			);
	}
}

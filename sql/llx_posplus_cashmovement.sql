-- Copyright (C) 2026		Edgar Bustamante	<ebustamantep@gmail.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.

-- Cash put in or withdrawn from a cash box during a cash session (with its bank line).
CREATE TABLE llx_posplus_cashmovement(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_session INTEGER NOT NULL,
	fk_account INTEGER NOT NULL,
	fk_bank INTEGER,
	amount DOUBLE(24,8) NOT NULL,			-- in the currency of the box: > 0 put in, < 0 withdrawn
	currency_code VARCHAR(3),
	reason VARCHAR(255),
	fk_user INTEGER NOT NULL,
	datec DATETIME NOT NULL
) ENGINE=innodb;

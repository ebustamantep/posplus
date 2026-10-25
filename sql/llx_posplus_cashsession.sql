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

-- Cash session (shift) of a TakePOS terminal: opening with the float of each cash box,
-- closing with the cash count (report X) or with the close of the day (report Z).
CREATE TABLE llx_posplus_cashsession(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	ref VARCHAR(64),
	terminal VARCHAR(30) NOT NULL,
	status SMALLINT DEFAULT 0 NOT NULL,		-- 0 open, 1 closed (report X), 2 closed with the close of the day (report Z)
	date_open DATETIME NOT NULL,
	fk_user_open INTEGER NOT NULL,
	date_start DATETIME NOT NULL,			-- start of the period counted (close of the previous shift, or opening)
	date_close DATETIME,
	fk_user_close INTEGER,
	fk_cashfence INTEGER,					-- llx_pos_cash_fence validated by the report Z
	note TEXT,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;

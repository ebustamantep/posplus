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

-- Lines of a cash session: one per cash box (float, expected, counted with denominations)
-- and one per non cash payment mode and bank account (expected and declared).
CREATE TABLE llx_posplus_cashsession_line(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	fk_session INTEGER NOT NULL,
	fk_account INTEGER NOT NULL,
	paymode VARCHAR(16),					-- code of c_paiement, NULL for a cash box line
	currency_code VARCHAR(3),
	iscash SMALLINT DEFAULT 0 NOT NULL,
	opening DOUBLE(24,8) DEFAULT 0,
	inflow DOUBLE(24,8) DEFAULT 0,			-- collections of the period
	outflow DOUBLE(24,8) DEFAULT 0,			-- refunds of the period
	movement_in DOUBLE(24,8) DEFAULT 0,		-- cash put in the box
	movement_out DOUBLE(24,8) DEFAULT 0,	-- cash withdrawn from the box
	expected DOUBLE(24,8) DEFAULT 0,
	declared DOUBLE(24,8),
	difference DOUBLE(24,8),
	denominations TEXT						-- JSON {"value": qty, ..., "other": amount}
) ENGINE=innodb;

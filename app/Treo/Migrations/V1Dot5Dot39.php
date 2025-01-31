<?php
/*
 * This file is part of EspoCRM and/or AtroCore.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2019 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: http://www.espocrm.com
 *
 * AtroCore is EspoCRM-based Open Source application.
 * Copyright (C) 2020 AtroCore UG (haftungsbeschränkt).
 *
 * AtroCore as well as EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * AtroCore as well as EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word
 * and "AtroCore" word.
 */

namespace Treo\Migrations;

use Treo\Core\Migration\Base;

class V1Dot5Dot39 extends Base
{
    public function up(): void
    {
        $this->exec("DROP TABLE array_value");
    }

    public function down(): void
    {
        $this->getPDO()->exec("CREATE TABLE array_value (id VARCHAR(24) NOT NULL COLLATE `utf8mb4_unicode_ci`, deleted TINYINT(1) DEFAULT '0' COLLATE `utf8mb4_unicode_ci`, value VARCHAR(255) DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, attribute VARCHAR(255) DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, entity_id VARCHAR(24) DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, entity_type VARCHAR(100) DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, INDEX IDX_ENTITY (entity_id, entity_type), INDEX IDX_ENTITY_TYPE_VALUE (entity_type, value), INDEX IDX_ENTITY_VALUE (entity_type, entity_id, value), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB");
    }

    protected function exec(string $query): void
    {
        try {
            $this->getPDO()->exec($query);
        } catch (\Throwable $e) {
        }
    }
}

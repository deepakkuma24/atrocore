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

declare(strict_types=1);

namespace Espo\Repositories;

use Espo\Core\Templates\Repositories\Base;
use Espo\ORM\Entity;
use Espo\Core\Exceptions\BadRequest;

class ExtensibleEnumOption extends Base
{
    protected function beforeSave(Entity $entity, array $options = [])
    {
        if ($entity->get('code') === '') {
            $entity->set('code', null);
        }

        if ($entity->isNew() && $entity->get('sortOrder') === null) {
            $last = $this->where(['extensibleEnumId' => $entity->get('extensibleEnumId')])->order('sortOrder', 'DESC')->findOne();
            $entity->set('sortOrder', empty($last) ? 0 : (int)$last->get('sortOrder') + 10);
        }

        parent::beforeSave($entity, $options);
    }

    public function updateSortOrder(array $ids): void
    {
        $collection = $this->where(['id' => $ids])->find();
        if (empty($collection[0])) {
            return;
        }

        foreach ($ids as $k => $id) {
            $sortOrder = (int)$k * 10;
            foreach ($collection as $entity) {
                if ($entity->get('id') !== (string)$id) {
                    continue;
                }
                $entity->set('sortOrder', $sortOrder);
                $this->save($entity);
            }
        }
    }

    protected function beforeRemove(Entity $entity, array $options = [])
    {
        $this->protectedListPacking($entity);
        parent::beforeRemove($entity, $options);

        $this->getEntityManager()->getRepository('OrderItem')->where(['orderId' => $entity->get('id')])->removeCollection();
    }

    protected function protectedListPacking(Entity $entity){
        $packingOptions = [
            'bottleIdPacking',
            'boxIdPacking',
            'oneIdPacking',
            'caseIdPacking',
            'cartonIdPacking',
            'packetIdPacking',
            'palletIdPacking',
            'packIdPacking',
            'rollIdPacking'
        ];

        if(in_array($entity->id, $packingOptions)){
            throw new BadRequest(
                $this->getInjection('language')->translate('packingOptionsForbiden', 'exceptions', 'ExtensibleEnum')
            );
        }
    }

    protected function init()
    {
        parent::init();

        $this->addDependency('language');
    }
}

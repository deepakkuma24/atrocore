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

namespace Espo\Core\Utils\Database\Schema;

use Espo\Core\Utils\Util;
use Espo\ORM\Entity;
use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Database\Schema\Utils as SchemaUtils;

class Converter
{
    private $dbalSchema;

    private $databaseSchema;

    private $fileManager;

    private $metadata;

    protected $typeList;

    //pair ORM => doctrine
    protected static $allowedDbFieldParams = array(
        'len' => 'length',
        'default' => 'default',
        'notNull' => 'notnull',
        'autoincrement' => 'autoincrement',
        'unique' => 'unique',
    );

    //todo: same array in Converters\Orm
    protected $idParams = array(
        'dbType' => 'varchar',
        'len' => 24,
    );

    //todo: same array in Converters\Orm
    protected $defaultLength = array(
        'varchar' => 255,
        'int' => 11,
    );

    protected $notStorableTypes = array(
        'foreign'
    );

    protected $maxIndexLength;

    public function __construct(\Espo\Core\Utils\Metadata $metadata, \Espo\Core\Utils\File\Manager $fileManager, \Espo\Core\Utils\Database\Schema\Schema $databaseSchema, \Espo\Core\Utils\Config $config = null)
    {
        $this->metadata = $metadata;
        $this->fileManager = $fileManager;
        $this->databaseSchema = $databaseSchema;
        $this->config = $config;

        $this->typeList = array_keys(\Doctrine\DBAL\Types\Type::getTypesMap());
    }

    protected function getMetadata()
    {
        return $this->metadata;
    }

    protected function getFileManager()
    {
        return $this->fileManager;
    }

    protected function getConfig()
    {
        return $this->config;
    }

    /**
     * Get schema
     *
     * @param  boolean $reload
     *
     * @return \Doctrine\DBAL\Schema\Schema
     */
    protected function getSchema($reload = false)
    {
        if (!isset($this->dbalSchema) || $reload) {
            $this->dbalSchema = new \Espo\Core\Utils\Database\DBAL\Schema\Schema();
        }

        return $this->dbalSchema;
    }

    protected function getDatabaseSchema()
    {
        return $this->databaseSchema;
    }

    protected function getMaxIndexLength()
    {
        if (!isset($this->maxIndexLength)) {
            $this->maxIndexLength = $this->getDatabaseSchema()->getDatabaseHelper()->getMaxIndexLength();
        }

        return $this->maxIndexLength;
    }

    /**
     * Schema convertation process
     *
     * @param  array  $ormMeta
     * @param  array|null $entityList
     *
     * @return \Doctrine\DBAL\Schema\Schema
     */
    public function process(array $ormMeta, $entityList = null)
    {
        $GLOBALS['log']->debug('Schema\Converter - Start: building schema');

        //check if exist files in "Tables" directory and merge with ormMetadata
        $ormMeta = Util::merge($ormMeta, $this->getCustomTables($ormMeta));

        if (isset($ormMeta['unsetIgnore'])) {
            $protectedOrmMeta = array();
            foreach ($ormMeta['unsetIgnore'] as $protectedKey) {
                $protectedOrmMeta = Util::merge( $protectedOrmMeta, Util::fillArrayKeys($protectedKey, Util::getValueByKey($ormMeta, $protectedKey)) );
            }
            unset($ormMeta['unsetIgnore']);
        }

        //unset some keys in orm
        if (isset($ormMeta['unset'])) {
            $ormMeta = Util::unsetInArray($ormMeta, $ormMeta['unset']);
            unset($ormMeta['unset']);
        } //END: unset some keys in orm

        if (isset($protectedOrmMeta)) {
            $ormMeta = Util::merge($ormMeta, $protectedOrmMeta);
        }

        if (isset($entityList)) {
            $entityList = is_string($entityList) ? (array) $entityList : $entityList;

            $dependentEntities = $this->getDependentEntities($entityList, $ormMeta);
            $GLOBALS['log']->debug('Rebuild Database for entities: ['.implode(', ', $entityList).'] with dependent entities: ['.implode(', ', $dependentEntities).']');

            $ormMeta = array_intersect_key($ormMeta, array_flip($dependentEntities));
        }

        $schema = $this->getSchema(true);

        $indexList = SchemaUtils::getIndexList($ormMeta);
        $fieldListExceededIndexMaxLength = SchemaUtils::getFieldListExceededIndexMaxLength($ormMeta, $this->getMaxIndexLength());

        $tables = array();
        foreach ($ormMeta as $entityName => $entityParams) {

            $tableName = Util::toUnderScore($entityName);

            if ($schema->hasTable($tableName)) {
                if (!isset($tables[$entityName])) {
                    $tables[$entityName] = $schema->getTable($tableName);
                }
                $GLOBALS['log']->debug('DBAL: Table ['.$tableName.'] exists.');
                continue;
            }

            $tables[$entityName] = $schema->createTable($tableName);

            if (isset($entityParams['params']) && is_array($entityParams['params'])) {
                foreach ($entityParams['params'] as $paramName => $paramValue) {
                    $tables[$entityName]->addOption($paramName, $paramValue);
                }
            }

            $primaryColumns = array();
            $uniqueColumns = array();

            foreach ($entityParams['fields'] as $fieldName => $fieldParams) {

                if ((isset($fieldParams['notStorable']) && $fieldParams['notStorable']) || in_array($fieldParams['type'], $this->notStorableTypes)) {
                    continue;
                }

                switch ($fieldParams['type']) {
                    case 'id':
                        $primaryColumns[] = Util::toUnderScore($fieldName);
                        break;
                }

                $fieldType = isset($fieldParams['dbType']) ? $fieldParams['dbType'] : $fieldParams['type'];
                if (!in_array($fieldType, $this->typeList)) {
                    $GLOBALS['log']->debug('Converters\Schema::process(): Field type ['.$fieldType.'] does not exist '.$entityName.':'.$fieldName);
                    continue;
                }

                if (isset($fieldListExceededIndexMaxLength[$entityName]) && in_array($fieldName, $fieldListExceededIndexMaxLength[$entityName])) {
                    $fieldParams['utf8mb3'] = true;
                }

                $columnName = Util::toUnderScore($fieldName);
                if (!$tables[$entityName]->hasColumn($columnName)) {
                    $tables[$entityName]->addColumn($columnName, $fieldType, $this->getDbFieldParams($fieldParams));
                }

                //add unique
                if ($fieldParams['type'] != 'id' && isset($fieldParams['unique'])) {
                    $additionalFields = [];
                    $metadataFieldType = $this->getMetadata()->get(['entityDefs', $entityName, 'fields', $fieldName, 'type']);
                    if (!empty($metadataFieldType)) {
                        $additionalFields = $this->getMetadata()->get(['fields', $metadataFieldType, 'actualFields'], []);
                    }

                    $uniqueColumns = $this->getKeyList($columnName, $fieldParams, $uniqueColumns, $additionalFields);
                } //END: add unique
            }

            $tables[$entityName]->setPrimaryKey($primaryColumns);

            if (!empty($indexList[$entityName])) {
                foreach($indexList[$entityName] as $indexName => $indexParams) {
                    $indexColumnList = $indexParams['columns'];
                    $indexFlagList = isset($indexParams['flags']) ? $indexParams['flags'] : array();
                    $tables[$entityName]->addIndex($indexColumnList, $indexName, $indexFlagList);
                }
            }

            if (!empty($uniqueColumns)) {
                foreach($uniqueColumns as $uniqueItem) {
                    $tables[$entityName]->addUniqueIndex($uniqueItem);
                }
            }

            foreach ($this->getMetadata()->get(['entityDefs', $entityName, 'uniqueIndexes'], []) as $indexName => $indexColumns) {
                $tables[$entityName]->addUniqueIndex($indexColumns, SchemaUtils::generateIndexName($indexName));
            }
        }

        //check and create columns/tables for relations
        foreach ($ormMeta as $entityName => $entityParams) {

            if (!isset($entityParams['relations'])) {
                continue;
            }

            foreach ($entityParams['relations'] as $relationName => $relationParams) {

                 switch ($relationParams['type']) {
                    case 'manyMany':
                        $tableName = $relationParams['relationName'];

                        //check for duplicate tables
                        if (!isset($tables[$tableName])) { //no needs to create the table if it already exists
                            $tables[$tableName] = $this->prepareManyMany($entityName, $relationParams, $tables);
                        }
                        break;
                }
            }
        }
        //END: check and create columns/tables for relations

        $GLOBALS['log']->debug('Schema\Converter - End: building schema');

        return $schema;
    }

    /**
     * Prepare a relation table for the manyMany relation
     *
     * @param string $entityName
     * @param array $relationParams
     * @param array $tables
     *
     * @return \Doctrine\DBAL\Schema\Table
     */
    protected function prepareManyMany($entityName, $relationParams, $tables)
    {
        $tableName = Util::toUnderScore($relationParams['relationName']);

        if ($this->getSchema()->hasTable($tableName)) {
            $GLOBALS['log']->debug('DBAL: Table ['.$tableName.'] exists.');
            return $this->getSchema()->getTable($tableName);
        }

        $table = $this->getSchema()->createTable($tableName);
        $table->addColumn('id', 'int', $this->getDbFieldParams(array(
            'type' => 'int',
            'len' => $this->defaultLength['int'],
            'autoincrement' => true,
        )));

        //add midKeys to a schema
        $uniqueIndex = array();
        if (!empty($relationParams['midKeys'])) {
            foreach($relationParams['midKeys'] as $index => $midKey) {
                $columnName = Util::toUnderScore($midKey);
                $table->addColumn($columnName, $this->idParams['dbType'], $this->getDbFieldParams(array(
                    'type' => 'foreignId',
                    'len' => $this->idParams['len'],
                )));
                $table->addIndex(array($columnName));

                $uniqueIndex[] = $columnName;
            }
        }

        //END: add midKeys to a schema

        //add additionalColumns
        if (!empty($relationParams['additionalColumns'])) {
            foreach($relationParams['additionalColumns'] as $fieldName => $fieldParams) {

                if (!isset($fieldParams['type'])) {
                    $fieldParams = array_merge($fieldParams, array(
                        'type' => 'varchar',
                        'len' => $this->defaultLength['varchar'],
                    ));
                }

                $table->addColumn(Util::toUnderScore($fieldName), $fieldParams['type'], $this->getDbFieldParams($fieldParams));
            }
        } //END: add additionalColumns

        //add unique indexes
        if (!empty($relationParams['conditions'])) {
            foreach ($relationParams['conditions'] as $fieldName => $fieldParams) {
                $uniqueIndex[] = Util::toUnderScore($fieldName);
            }
        }

        if (!empty($uniqueIndex)) {
            $table->addUniqueIndex($uniqueIndex);
        }
        //END: add unique indexes

        $table->addColumn('deleted', 'bool', $this->getDbFieldParams(array(
            'type' => 'bool',
            'default' => false,
        )));

        $table->setPrimaryKey(array("id"));

        return $table;
    }

    public function getDbFieldParams(array $fieldParams): array
    {
        $dbFieldParams = [];

        foreach (self::$allowedDbFieldParams as $espoName => $dbalName) {
            if (isset($fieldParams[$espoName])) {
                $dbFieldParams[$dbalName] = $fieldParams[$espoName];
            }
        }

        $databaseParams = $this->getConfig()->get('database');
        if (!isset($databaseParams['charset']) || $databaseParams['charset'] == 'utf8mb4') {
            $dbFieldParams['platformOptions'] = [
                'collation' => 'utf8mb4_unicode_ci'
            ];
        }

        switch ($fieldParams['type']) {
            case 'id':
            case 'foreignId':
            case 'foreignType':
                if ($this->getMaxIndexLength() < 3072) {
                    $fieldParams['utf8mb3'] = true;
                }
                break;

            case 'array':
            case 'jsonArray':
            case 'text':
            case 'longtext':
                if (!empty($dbFieldParams['default'])) {
                    $dbFieldParams['comment'] = "default={" . $dbFieldParams['default'] . "}";
                }
                unset($dbFieldParams['default']); //for db type TEXT can't be defined a default value
                break;

            case 'bool':
                $default = false;
                if (array_key_exists('default', $dbFieldParams)) {
                    $default = $dbFieldParams['default'];
                }
                $dbFieldParams['default'] = intval($default);
                break;
        }

        if (isset($fieldParams['autoincrement']) && $fieldParams['autoincrement']) {
            $dbFieldParams['unique'] = true;
            $dbFieldParams['notnull'] = true;
        }

        if (isset($fieldParams['utf8mb3']) && $fieldParams['utf8mb3']) {
            $dbFieldParams['platformOptions'] = ['collation' => 'utf8_unicode_ci'];
        }

        return $dbFieldParams;
    }

    /**
     * Get key list (index, unique). Ex. index => true OR index => 'somename'
     * @param array $fieldParams
     * @param bool | string $keyValue
     * @return array
     */
    protected function getKeyList($columnName, $fieldParams, array $keyList, array $additionalFields = [])
    {
        $keyValue = $fieldParams['unique'];
        if ($keyValue === true) {
            if (empty($fieldParams['autoincrement'])) {
                $tableIndexName = SchemaUtils::generateIndexName($columnName . '_deleted');
                $columnNames = [$columnName, 'deleted'];
            } else {
                $tableIndexName = SchemaUtils::generateIndexName($columnName);
                $columnNames = [$columnName];
            }

            foreach ($additionalFields as $column) {
                if (!empty($column)) {
                    $columnNames[] = $columnName . '_' . Util::toUnderScore($column);
                }
            }

            $keyList[$tableIndexName] = $columnNames;
        } else if (is_string($keyValue)) {
            $tableIndexName = SchemaUtils::generateIndexName($keyValue);
            $keyList[$tableIndexName][] = $columnName;
        }

        return $keyList;
    }

    /**
     * Get custom table defenition
     *
     * @param  array  $ormMeta
     *
     * @return array
     */
    protected function getCustomTables(array $ormMeta)
    {
        return $this->loadData(CORE_PATH . '/Espo/Core/Utils/Database/Schema/tables');
    }

    protected function getDependentEntities($entityList, $ormMeta, $dependentEntities = array())
    {
        if (is_string($entityList)) {
            $entityList = (array) $entityList;
        }

        foreach ($entityList as $entityName) {

            if (in_array($entityName, $dependentEntities)) {
                continue;
            }

            $dependentEntities[] = $entityName;

            if (isset($ormMeta[$entityName]['relations'])) {
                foreach ($ormMeta[$entityName]['relations'] as $relationName => $relationParams) {
                    if (isset($relationParams['entity'])) {
                        $relationEntity = $relationParams['entity'];
                        if (!in_array($relationEntity, $dependentEntities)) {
                            $dependentEntities = $this->getDependentEntities($relationEntity, $ormMeta, $dependentEntities);
                        }
                    }
                }
            }
        }

        return $dependentEntities;
    }

    protected function loadData($path)
    {
        $tables = array();

        if (!file_exists($path)) {
            return $tables;
        }

        $fileList = $this->getFileManager()->getFileList($path, false, '\.php$', true);

        foreach($fileList as $fileName) {
            $fileData = $this->getFileManager()->getPhpContents( array($path, $fileName) );
            if (is_array($fileData)) {
                $tables = Util::merge($tables, $fileData);
            }
        }

        return $tables;
    }
}

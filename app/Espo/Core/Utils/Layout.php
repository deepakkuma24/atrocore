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

namespace Espo\Core\Utils;

use Espo\Core\Container;

/**
 * Class Layout
 */
class Layout
{
    protected Container $container;

    protected array $changedData = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function isCustom(string $scope, string $name): bool
    {
        return file_exists($this->concatPath($this->getCustomPath($scope), $name . '.json'));
    }

    /**
     * Get a full path of the file
     *
     * @param string | array $folderPath - Folder path, Ex. myfolder
     * @param string         $filePath   - File path, Ex. file.json
     *
     * @return string
     */
    public function concatPath($folderPath, $filePath = null)
    {
        // for portal
        if ($this->isPortal()) {
            $portalPath = Util::concatPath($folderPath, 'portal/' . $filePath);
            if (file_exists($portalPath)) {
                return $portalPath;
            }
        }

        return Util::concatPath($folderPath, $filePath);
    }

    /**
     * Get Layout context
     *
     * @param string $scope
     * @param string $name
     *
     * @return json|string
     */
    public function get($scope, $name)
    {
        // prepare scope
        $scope = $this->sanitizeInput($scope);

        // prepare name
        $name = $this->sanitizeInput($name);

        // cache
        if (isset($this->changedData[$scope][$name])) {
            return Json::encode($this->changedData[$scope][$name]);
        }

        // compose
        $layout = $this->compose($scope, $name);

        // remove fields from layout if this fields not exist in metadata
        $layout = $this->disableNotExistingFields($scope, $name, $layout);

        return Json::encode($layout);
    }

    /**
     * Set Layout data
     * Ex. $scope = Account, $name = detail then will be created a file layoutFolder/Account/detail.json
     *
     * @param array|string $data
     * @param string       $scope - ex. Account
     * @param string       $name  - detail
     *
     * @return void
     */
    public function set($data, $scope, $name)
    {
        $scope = $this->sanitizeInput($scope);
        $name = $this->sanitizeInput($name);

        if (empty($scope) || empty($name)) {
            return;
        }

        $this->changedData[$scope][$name] = $data;
    }

    /**
     * @param string $scope
     * @param string $name
     *
     * @return json|string
     */
    public function resetToDefault($scope, $name)
    {
        $scope = $this->sanitizeInput($scope);
        $name = $this->sanitizeInput($name);

        $filePath = 'custom/Espo/Custom/Resources/layouts/' . $scope . '/' . $name . '.json';
        if ($this->getFileManager()->isFile($filePath)) {
            $this->getFileManager()->removeFile($filePath);
        }
        if (!empty($this->changedData[$scope]) && !empty($this->changedData[$scope][$name])) {
            unset($this->changedData[$scope][$name]);
        }

        return $this->get($scope, $name);
    }

    /**
     * Save changes
     *
     * @return bool
     */
    public function save()
    {
        $result = true;

        if (!empty($this->changedData)) {
            foreach ($this->changedData as $scope => $rowData) {
                foreach ($rowData as $layoutName => $layoutData) {
                    if (empty($scope) || empty($layoutName)) {
                        continue;
                    }
                    $layoutPath = $this->getCustomPath($scope);
                    $data = Json::encode($layoutData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

                    $result &= $this->getFileManager()->putContents(array($layoutPath, $layoutName . '.json'), $data);
                }
            }
        }

        if ($result == true) {
            $this->clearChanges();
        }

        return (bool)$result;
    }

    /**
     * Clear unsaved changes
     *
     * @return void
     */
    public function clearChanges(): void
    {
        $this->changedData = [];
    }

    /**
     * @param JSON string $data
     * @param string $scope - ex. Account
     * @param string $name  - detail
     *
     * @return bool
     */
    public function merge($data, $scope, $name)
    {
        $scope = $this->sanitizeInput($scope);
        $name = $this->sanitizeInput($name);

        $prevData = $this->get($scope, $name);

        $prevDataArray = Json::getArrayData($prevData);
        $dataArray = Json::getArrayData($data);

        $data = Util::merge($prevDataArray, $dataArray);
        $data = Json::encode($data);

        return $this->set($data, $scope, $name);
    }

    protected function getCustomPath(string $entityType): string
    {
        return Util::concatPath('custom/Espo/Custom/Resources/layouts', $entityType);
    }

    protected function compose(string $scope, string $name): array
    {
        // from custom data
        if ($this->isCustom($scope, $name)) {
            return Json::decode($this->getFileManager()->getContents($this->concatPath($this->getCustomPath($scope), $name . '.json')), true);
        }

        // prepare data
        $data = [];

        // from treo core data
        $filePath = $this->concatPath(CORE_PATH . '/Treo/Resources/layouts', $scope);
        $fileFullPath = $this->concatPath($filePath, $name . '.json');
        if (file_exists($fileFullPath)) {
            // get file data
            $fileData = $this->getFileManager()->getContents($fileFullPath);

            // prepare data
            $data = Json::decode($fileData, true);
        }

        // from espo core data
        if (empty($data)) {
            $filePath = $this->concatPath(CORE_PATH . '/Espo/Resources/layouts', $scope);
            $fileFullPath = $this->concatPath($filePath, $name . '.json');
            if (file_exists($fileFullPath)) {
                // get file data
                $fileData = $this->getFileManager()->getContents($fileFullPath);

                // prepare data
                $data = Json::decode($fileData, true);
            }
        }

        // from modules data
        foreach ($this->getMetadata()->getModules() as $module) {
            $module->loadLayouts($scope, $name, $data);
        }

        // default
        if (empty($data)) {
            // prepare file path
            $fileFullPath = $this->concatPath($this->concatPath(CORE_PATH . '/Espo/Core/defaults', 'layouts'), $name . '.json');

            if (file_exists($fileFullPath)) {
                // get file data
                $fileData = $this->getFileManager()->getContents($fileFullPath);

                // prepare data
                $data = Json::decode($fileData, true);
            }
        }

        return $data;
    }

    /**
     * Disable fields from layout if this fields not exist in metadata
     *
     * @param string $scope
     * @param string $name
     * @param array  $data
     *
     * @return array
     */
    protected function disableNotExistingFields($scope, $name, $data): array
    {
        // get entityDefs
        $entityDefs = $this->getMetadata()->get('entityDefs')[$scope] ?? [];

        // check if entityDefs exists
        if (!empty($entityDefs)) {
            // get fields for entity
            $fields = array_keys($entityDefs['fields']);
            $fields[] = 'id';

            // remove fields from layout if this fields not exist in metadata
            switch ($name) {
                case 'filters':
                case 'massUpdate':
                    $data = array_values(array_intersect($data, $fields));

                    break;
                case 'detail':
                case 'detailSmall':
                    for ($key = 0; $key < count($data[0]['rows']); $key++) {
                        foreach ($data[0]['rows'][$key] as $fieldKey => $fieldData) {
                            if (isset($fieldData['name']) && !in_array($fieldData['name'], $fields)) {
                                $data[0]['rows'][$key][$fieldKey] = false;

                                if (empty(array_diff($data[0]['rows'][$key], [false]))) {
                                    array_splice($data[0]['rows'], $key, 1);
                                    $key--;
                                    continue 2;
                                }
                            }
                        }
                    }

                    break;
                case 'list':
                case 'listSmall':
                    foreach ($data as $key => $row) {
                        if (isset($row['name']) && !in_array($row['name'], $fields)) {
                            array_splice($data, $key, 1);
                        }
                    }

                    break;
            }
        }

        return $data;
    }

    protected function sanitizeInput(string $name): string
    {
        return preg_replace("([\.]{2,})", '', $name);
    }

    protected function isPortal(): bool
    {
        return !empty($this->getContainer()->get('portal'));
    }

    protected function getContainer(): Container
    {
        return $this->container;
    }

    protected function getFileManager(): File\Manager
    {
        return $this->getContainer()->get('fileManager');
    }

    protected function getMetadata(): Metadata
    {
        return $this->getContainer()->get('metadata');
    }

    protected function getUser(): \Espo\Entities\User
    {
        return $this->getContainer()->get('user');
    }
}

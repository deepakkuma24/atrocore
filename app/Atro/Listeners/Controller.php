<?php
/**
 * AtroCore Software
 *
 * This source file is available under GNU General Public License version 3 (GPLv3).
 * Full copyright and license information is available in LICENSE.md, located in the root directory.
 *
 * @copyright  Copyright (c) AtroCore UG (https://www.atrocore.com)
 * @license    GPLv3 (https://www.gnu.org/licenses/)
 */

declare(strict_types=1);

namespace Atro\Listeners;

use Atro\Core\EventManager\Event;

/**
 * Class Controller
 */
class Controller extends AbstractListener
{
    /**
     * @param Event $event
     */
    public function beforeAction(Event $event)
    {
        $this
            ->getContainer()
            ->get('eventManager')
            ->dispatch($event->getArgument('controller') . 'Controller', $event->getArgument('action'), $event);
    }

    /**
     * @param Event $event
     */
    public function afterAction(Event $event)
    {
        $this
            ->getContainer()
            ->get('eventManager')
            ->dispatch($event->getArgument('controller') . 'Controller', $event->getArgument('action'), $event);
    }
}
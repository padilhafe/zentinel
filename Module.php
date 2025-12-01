<?php declare(strict_types = 1);

namespace Modules\Zentinel;

use APP;
use CController as CAction;
use CMenuItem;

class Module extends \Zabbix\Core\CModule {

    /**
     * Inicializa o módulo.
     */
    public function init(): void {
        $menu = APP::Component()->get('menu.main');

        if ($menu !== null) {
            $menu->findOrAdd(_('Monitoring'))
                ->getSubmenu()
                ->insertAfter('hosts', (new CMenuItem(_('Zentinel')))
                    ->setAction('zentinel.view')
                );
        }
    }

    /**
     * Manipulador de evento, disparado antes da ação.
     */
    public function onBeforeAction(CAction $action): void {
    }

    /**
     * Manipulador de evento, disparado ao terminar.
     */
    public function onTerminate(CAction $action): void {
    }
}
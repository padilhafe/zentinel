<?php declare(strict_types = 1);
 
namespace Modules\Zentinel;
 
use APP;
use CController as CAction;
 
class Module extends \Zabbix\Core\CModule {

    public function init(): void {
        APP::Component()->get('menu.main')
            ->findOrAdd(_('Monitoring'))
                ->getSubmenu()
                    ->insertAfter('hosts', (new \CMenuItem(_('Zentinel')))
                        ->setAction('zentinel.view')
                    );
    }
 
    public function onBeforeAction(CAction $action): void {
    }
 
    public function onTerminate(CAction $action): void {
    }
}
?>
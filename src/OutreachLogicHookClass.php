<?php

/**
 * Author: Muhammad Tariq Ibrar
 * Email: tibrar@brainsell.com, engrtariqibrar@gmail.com
 * Linkedin: https://www.linkedin.com/in/engrtariqibrar
 */
class OutreachLogicHookClass {

    private $outreachSynchronizer = null;

    public function __construct() {
        $this->outreachSynchronizer = new \Outreach\OutreachSynchronizer();
    }

    public function beforeSave(&$bean) {
        $bean->or_has_synced = false;
        $bean->cstm_fetch_row = $bean->fetched_row;
    }

    public function afterSave($bean, $event, $args) {
        if ($bean->sync_with_or) {
            if (!isset($bean->fetched_row['id'])) {
                $dataChanges = array();
                $source = 'api';
            } else {
                $dataChanges = $args;
                $source = null;
            }
            if ($this->outreachSynchronizer) {
                $this->outreachSynchronizer->triggerLogic($bean, $source, $dataChanges);
            } else {
                $GLOBALS['log']->fatal("Outreach to Sugar Error: OutreachLogicHookClass afterSave outreachSynchronizer boject empty");
            }
        }
    }

    public function beforeDelete(&$bean) {
        $bean->or_deleted = true;
        if ($bean->sync_with_or) {
            if ($this->outreachSynchronizer) {
                $this->outreachSynchronizer->triggerLogic($bean, null, null);
            } else {
                $GLOBALS['log']->fatal("Outreach to Sugar Error: OutreachLogicHookClass beforeDelete outreachSynchronizer boject empty");
            }
        }
    }

    /*
     * If a lead or contact is added to an account
     */

    public function afterRelationshipAdd($bean, $event, $args) {
        if ($bean->sync_with_or) {
            $GLOBALS['log']->info('afterRelationshipAdd $args', $args);
            if (($args['link'] == 'contacts' || $args['link'] == 'leads') &&
                    !empty($args['related_module']) && !empty($args['related_id'])) {
                $BeantoSync = BeanFactory::getBean($args['related_module'], $args['related_id']);
                if ($bean->sync_with_or && isset($BeantoSync->fetched_row['id'])) {
                    $this->outreachSynchronizer->triggerLogic($BeantoSync, 'api', array());
                }
            }
        }
    }

    /*
     * If a lead or contact unlinked from an account
     */

    public function afterRelationshipDelete($bean, $event, $args) {
        if (!$bean->or_deleted && $bean->sync_with_or) {
            $outreachApiHelper = new \Outreach\OutreachApiHelper;
            /*
             * If a contact is unlink from account, unlink this relationship from salesloft
             */
            if ($args['link'] == 'accounts' && !empty($bean->or_id) && $bean->module_dir == 'Contacts') {
                $relationships = [];
                $relationships['account'] = ['data' => null];
                $data = [
                    'type' => 'prospect',
                    'id' => intval($bean->or_id),
                    'relationships' => $relationships
                ];
                $outreachApiHelper->updateObject(json_encode(['data' => $data]), 'prospects', $bean->or_id);
            }

            if ($bean->module_dir == 'Opportunities') {
                $this->deleteProspectOppRole($bean, $args);
            }
        }
    }

    public function deleteProspectOppRole(&$bean, $args) {
        $GLOBALS['log']->info('deleteProspectOppRole $bean', $bean->id);
        $GLOBALS['log']->info('deleteProspectOppRole $args', $args);
        $oppCache = unserialize($bean->or_relationship_cache);
        $GLOBALS['log']->info('deleteProspectOppRole $oppCache', $oppCache);
        if (empty($oppCache['opportunityProspectRoles'][$args['related_module']][$args['related_id']])) {
            return;
        }

        $outReachApiHelper = new OutReachApiHelper();
        $response = $outReachApiHelper->deleteObject('opportunityProspectRoles', intval($oppCache['opportunityProspectRoles'][$args['related_module']][$args['related_id']]));

        $GLOBALS['log']->info('deleteProspectOppRole $response', $response);
        $relatedBean = BeanFactory::getBean($args['related_module'], $args['related_id']);

        unset($oppCache['opportunityProspectRoles'][$args['related_module']][$args['related_id']]);
        $GLOBALS['log']->info('deleteProspectOppRole $oppCache now', $oppCache);
        $oppCache = serialize($oppCache);
        $GLOBALS['db']->query("update {$bean->table_name} set or_relationship_cache='$oppCache' where id='{$bean->id}' ");

        $relatedBeanCache = unserialize($relatedBean->or_relationship_cache);
        unset($relatedBeanCache['opportunityProspectRoles'][$bean->module_dir][$bean->id]);
        $GLOBALS['log']->info('deleteProspectOppRole $relatedBeanCache', $relatedBeanCache);
        $relatedBeanCache = serialize($relatedBeanCache);
        $GLOBALS['db']->query("update {$relatedBean->table_name} set or_relationship_cache='$relatedBeanCache' where id='{$relatedBean->id}' ");
    }
}

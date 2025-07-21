<?php

/**
 * Author: Muhammad Tariq Ibrar
 * Email: engrtariqibrar@gmail.com
 * Linkedin: https://www.linkedin.com/in/engrtariqibrar
 */

namespace Outreach;

use BeanFactory;
use stdClass;
use User;

class OutreachSynchronizer {

    private $field_mapping = array();
    private $syncFields = array();
    private $outreachApiHelper = null;
    private $syncLogsCache = array();
    private $dataChanges = array();
    private $source = null;
    private $pageOffset = 0;
    private $mapping = array();
    private $or_module = null;
    private $sugar_module = null;
    private $count_sync = 0;
    private $lead_count_sync = 0;
    private $syncDate = null;
    private $sugar_to_or_synced = false;

    const DIRECTION_SUGAR_TO_OUTREACH = 'sugar_to_outreach';
    const DIRECTION_OUTREACH_TO_SUGAR = 'outreach_to_sugar';
    const DIRECTION_BIDIRECTIONAL = 'bidirectional';
    const ACTION_CREATED = 'created';
    const ACTION_UPDATED = 'updated';
    const ACTION_DESTROYED = 'destroyed';

    public function __construct() {
        $this->outreachApiHelper = new \Outreach\OutreachApiHelper;
    }

    /*
     * Scheduler Job Outreach Scheduled Sync
     */

    public function outreachScheduledSync() {
        try {
            if (!$this->isValidLicense()) {
                $GLOBALS['log']->fatal('Outreach Scheduled Sync Error: Invalid Outreach License key');
                return;
            }
            $modules = array('Accounts', 'Contacts', 'Leads', 'Opportunities', 'Calls', 'Tasks');
            foreach ($modules as $key => $sugar_module) {
                $mapping = $this->outreachApiHelper->retrieveSettings($sugar_module);
                if (!isset($mapping['fields_config'])) {
                    $GLOBALS['log']->fatal("Outreach Scheduled Sync Error: Mapping for $sugar_module is not set", $mapping);
                    continue;
                }
                if (!isset($mapping['is_enable']) || $mapping['is_enable'] == 'No') {
                    $GLOBALS['log']->fatal("Outreach Scheduled Sync Error: The $sugar_module module is not enabled for sync");
                    continue;
                }
                $succ_count = 0;
                $failed_count = 0;
                $table = strtolower($sugar_module);
                $query = "SELECT id,or_id FROM $table WHERE or_schedule_sync = 1 AND deleted = 0";
                $rows = $GLOBALS['db']->query($query);
                while ($row = $GLOBALS['db']->fetchByAssoc($rows)) {
                    $this->sugar_to_or_synced = false;
                    $action = empty($row['or_id']) ? 'created' : 'updated';
                    $this->syncFromSugar([
                        'module' => $sugar_module,
                        'action' => $action,
                        'sugarId' => $row['id'],
                        'source' => 'api'
                    ]);
                    if ($this->sugar_to_or_synced) {
                        $succ_count++;
                        $GLOBALS['db']->query("UPDATE $table SET or_schedule_sync = 0 WHERE id = '{$row['id']}'");
                    } else {
                        $failed_count++;
                        $GLOBALS['log']->fatal("Outreach Scheduled Sync Error: $sugar_module {$row['id']} sync failed", $this->syncLogsCache);
                    }
                }
                if ($succ_count) {
                    $GLOBALS['log']->fatal("Outreach Scheduled Sync Info: $succ_count $sugar_module records were syned successfully");
                }
                if ($failed_count) {
                    $GLOBALS['log']->fatal("Outreach Scheduled Sync Info: $failed_count $sugar_module records were failed to sync");
                }
            }
        } catch (Exception $exc) {
            $GLOBALS['log']->fatal('Outreach Scheduled Sync Error: An error occured while syncing data from Outreach to Sugar', $exc->getTraceAsString());
            return false;
        }
    }

    /*
     * Scheduler Job Outreach To Sugar Sync
     */

    public function outreachToSugarSync() {
        try {
            if (!$this->isValidLicense()) {
                $GLOBALS['log']->fatal('Outreach to Sugar Error: Invalid Outreach License key');
                return;
            } else {
                $GLOBALS['log']->fatal('Outreach to Sugar Info: Outreach License validated');
            }
            $this->syncDate = date('Y-m-d'); //Y-m-d\T00:00:00\Z
            $modules = array(
                'accounts' => 'Accounts',
                'prospects' => 'Contacts',
                'opportunities' => 'Opportunities',
                'calls' => 'Calls',
                'tasks' => 'Tasks',
                'mailings' => 'Emails',
            );
            foreach ($modules as $or_module => $sugar_module) {
                $GLOBALS['log']->fatal("Outreach to Sugar Info: Syncing Outreach $or_module to Sugarcrm $sugar_module ...");
                $this->count_sync = 0;
                $this->lead_count_sync = 0;
                $this->pageOffset = 0;
                $this->or_module = $or_module;
                $this->sugar_module = $sugar_module;
                $this->mapping = $this->outreachApiHelper->retrieveSettings($this->sugar_module);
                $GLOBALS['log']->info('Outreach to Sugar Info $this->mapping', $this->mapping);
                if (!isset($this->mapping['fields_config'])) {
                    $GLOBALS['log']->fatal("Outreach to Sugar Error: Mapping for $sugar_module is not set", $this->mapping);
                    continue;
                }
                if (!isset($this->mapping['is_enable']) || $this->mapping['is_enable'] == 'No') {
                    $GLOBALS['log']->fatal("Outreach to Sugar Error: The $sugar_module module is not enabled for sync");
                    continue;
                }
                $this->mapping['module'] = $sugar_module;
                $this->setSyncFields($this->mapping, 'fromApi');
                $GLOBALS['log']->info('Outreach to Sugar Info $this->syncFields', $this->syncFields);
                if (!is_array($this->syncFields) || count($this->syncFields) == 0) {
                    continue;
                }
                $this->syncRecursively();
                if ($this->lead_count_sync) {
                    $GLOBALS['log']->fatal("Outreach to Sugar Info: $this->lead_count_sync leads records were synced");
                }
                $GLOBALS['log']->fatal("Outreach to Sugar Info: $this->count_sync $sugar_module records were synced");
            }
        } catch (Exception $exc) {
            $GLOBALS['log']->fatal('Outreach to Sugar Error: An error occured while syncing data from Outreach to Sugar', $exc->getTraceAsString());
            return false;
        }
    }

    public function syncRecursively($url = null) {
        if (empty($url)) {
            $cursorPoller = \Outreach\OutreachConfig::CURSOR_POLLER;
            if (empty($cursorPoller)) {
                $GLOBALS['log']->fatal("Outreach to Sugar Error: cursorPoller config value not found.");
                return;
            }
            $filter = '';
            if ($this->sugar_module == 'Emails') {
                $filter = "&filter[state]=delivered,bounced";
            }
            $url = \Outreach\OutreachConfig::BASE_URL . "{$this->or_module}" . $cursorPoller . $this->syncDate . "..inf&page[size]=100" . $filter;
        }
        $GLOBALS['log']->info("Outreach to Sugar Info: syncRecursively url = $url");
        $response = $this->outreachApiHelper->fetchObject($url, true);
        $GLOBALS['log']->info("Outreach to Sugar Info: syncRecursively response", $response);
        if ($response && isset($response->data) && is_array($response->data)) {
            if (count($response->data) > 0) {
                $this->syncToSugar($response);
                if (isset($response->links->next) && $this->pageOffset < 10) {
                    $this->pageOffset++;
                    $this->syncRecursively($response->links->next);
                }
            } else {
                $GLOBALS['log']->fatal("Outreach to Sugar Info: No more records found for $this->or_module");
            }
        } else {
            $GLOBALS['log']->fatal("Outreach to Sugar Error: An error occured while fetching date for $this->or_module", $response->errors);
        }
    }

    public function syncToSugar($response) {
        foreach ($response->data as $data) {
            if ($this->or_module == 'prospects') {
                $this->sugar_module = 'Contacts';
            }
            $GLOBALS['log']->info("Outreach to Sugar Info: syncToSugar data", $data);
           
            $beanToSync = $this->getSugarBeanByOrId($this->sugar_module, $data);
            $GLOBALS['log']->info("Outreach to Sugar Info: beanToSync->id", $beanToSync->id);
            /*
             * If contact not found against prospects in SugarCRM look for Lead record
             */
            if ($this->or_module == 'prospects' && empty($beanToSync->id)) {
                $leadBean = $this->getSugarBeanByOrId('Leads', $data);
                $GLOBALS['log']->info("Outreach to Sugar Info: leadBean->id", $leadBean->id);
                if (!empty($leadBean->id)) {
                    $mapping = $this->outreachApiHelper->retrieveSettings('Leads');
                    if (isset($mapping['is_enable']) && $mapping['is_enable'] == 'Yes') {
                        $this->sugar_module = 'Leads';
                        $beanToSync = $leadBean;
                    } else {
                        /*
                         * If the leads module is set to not sync then updates to a lead record should not be synchronized 
                         * and a new contact should not be created
                         */
                        continue;
                    }
                }
            }

            $GLOBALS['log']->info("Outreach to Sugar Info: beanToSync->module_dir final", $beanToSync->module_dir);
            $go = $this->notBlankUpdate($beanToSync, $data);
            $GLOBALS['log']->info("Outreach to Sugar Info: go", $go);
            if ($go) {
                $this->relForCallsTasksEmails($beanToSync, $data);
                $GLOBALS['log']->info("Outreach to Sugar Info: after", $data);
                $this->prospectRelWithORActivities($beanToSync, $data);
                $logicObje = new \Outreach\OutreachApiToSugarFieldLogic();
                $logicObje->sugarBean = &$beanToSync;
                $feilds_data = array();
                $GLOBALS['log']->info("Outreach to Sugar Info: this->syncFields", $this->syncFields);
                foreach ($this->syncFields as $apiField => $sugarField) {
                    $val = isset($data->attributes->$apiField) ? $data->attributes->$apiField : NULL;
                    if ($val) {
                        $prepareParams = array(
                            'sugarField' => $sugarField,
                            'apiField' => $apiField,
                            'apiFieldValue' => $val,
                            'apiObject' => $this->or_module,
                        );
                        $GLOBALS['log']->info("prepareParams", $prepareParams);
                        $response = $logicObje->execute($prepareParams);
                        $GLOBALS['log']->info("response", $response);
                        if (isset($response['error'])) {
                            $this->syncLogsCache[] = $response['error'];
                        } else if (isset($response['multi_field_sync']) && is_array($response['dataToSync'])) {
                            foreach ($response['dataToSync'] as $field => $value) {
                                if (!empty($field)) {
                                    $beanToSync->$field = $value;
                                    $feilds_data[$field] = $value;
                                }
                            }
                        } else if (isset($response['sugarField'])) {
                            $beanToSync->{$response['sugarField']} = $response['dataToSync'][$response['sugarField']];
                            $feilds_data[$response['sugarField']] = $response['dataToSync'][$response['sugarField']];
                        } else if (!empty($response['dataToSync'][$sugarField]) && !is_array($response['dataToSync'][$sugarField])) {
                            $beanToSync->$sugarField = $response['dataToSync'][$sugarField];
                            $feilds_data[$sugarField] = $response['dataToSync'][$sugarField];
                        }
                    }
                }
                $GLOBALS['log']->info("Outreach to Sugar Info: Fields with Data", $feilds_data);
                if (count($feilds_data) > 0) {
                    $this->afterSyncToSugar($beanToSync, $data);
                }
            }
        }
    }

    public function getSugarBeanByOrId($module, &$data) {
        $bean = (object) [];
        if (!empty($data->id) && !empty($module)) {
            $table = strtolower($module);
            $result = $this->queryBean($table, 'id', " or_id='$data->id' AND deleted=0 ");
            if (isset($result[0]['id']) && !empty($result[0]['id'])) {
                $bean = BeanFactory::getBean($module, $result[0]['id'], array('disable_row_level_security' => true));
            } else if (isset($data->attributes->emails) && is_array($data->attributes->emails) && count($data->attributes->emails) > 0) {
                $result = $this->getSugarRecordByEmail($module, $data->attributes->emails);
                if (isset($result['id']) && !empty($result['id'])) {
                    $bean = BeanFactory::getBean($module, $result['id'], array('disable_row_level_security' => true));
                } else {
                    $bean = BeanFactory::newBean($module);
                }
            } else {
                $bean = BeanFactory::newBean($module);
            }
        }
        return $bean;
    }

    public function getSugarRecordByEmail($module, $emails = array()) {
        $res = array();
        $emails = implode("','", $emails);
        if (!empty($emails)) {
            $table = strtolower($module);
            $query = "SELECT t.id FROM $table t JOIN email_addr_bean_rel eabr ON t.id = eabr.bean_id
                          JOIN email_addresses ea ON eabr.email_address_id = ea.id WHERE ea.email_address IN ('$emails') AND t.deleted = 0";
            $rows = $GLOBALS['db']->query($query);
            while ($row = $GLOBALS['db']->fetchByAssoc($rows)) {
                $res['module'] = $module;
                $res['id'] = $row['id'];
            }
        }
        return $res;
    }

    public function notBlankUpdate(&$bean, &$data) {
        if (empty($bean->id) || $bean->or_last_sync_time_raw != $data->attributes->updatedAt) {
            return true;
        }
        return false;
    }

    public function relForCallsTasksEmails(&$bean, &$data) {
        if (!in_array($bean->module_dir, ["Calls", "Tasks", "Leads", "Opportunities"])) {
            return;
        }
        $GLOBALS['log']->info('relForCallsTasksEmails $module', $bean->module_dir);
        $GLOBALS['log']->info('relForCallsTasksEmails $data', $data);
        $dropdownToObject = \Outreach\OutreachConfig::DROPDOWN_TO_OBJECT_MAPPING[$bean->module_dir];
        if (!is_array($dropdownToObject) && count($dropdownToObject) == 0) {
            return;
        }
        $GLOBALS['log']->info('relForCallsTasksEmails $dropdownToObject', $dropdownToObject);
        foreach ($data->relationships as $type => $relationship) {
            $GLOBALS['log']->info('relForCallsTasksEmails $type', $type);
            $GLOBALS['log']->info('relForCallsTasksEmails $relationship', $relationship);
            if (!in_array($type, $dropdownToObject)) {
                $GLOBALS['log']->info('not in array............');
                continue;
            }
            if (empty($relationship->data->id)) {
                $GLOBALS['log']->info('no id...........');
                continue;
            }
            $name = $this->getOrRelNameByID($type, $relationship->data->id);

            $GLOBALS['log']->info('getOrRelNameByID $name', $name);
            if (!empty($name)) {
                $dropdownToObjectFliped = array_flip($dropdownToObject);
                $GLOBALS['log']->info('$dropdownToObject fliped', $dropdownToObjectFliped);
                $field_name = $dropdownToObjectFliped[$type];
                $GLOBALS['log']->info('$field_name', $field_name);
                $this->syncFields[$type] = $field_name;
                $data->attributes->$type = $name;
            }
        }
    }

    public function getOrRelNameByID($orObj, $id) {
        if (empty($id))
            return;
        $GLOBALS['log']->info('getOrRelNameByID $id', $id);
        $name = null;
        $or_rel_mod = \Outreach\OutreachConfig::OUTREACH_PLURAL_MAPPING[$orObj];
        $GLOBALS['log']->info('getOrRelNameByID $orObj', $orObj);
        $GLOBALS['log']->info('getOrRelNameByID $or_rel_mod', $or_rel_mod);
        $result = $this->outreachApiHelper->retrieveSettings($or_rel_mod);
        $GLOBALS['log']->info('getOrRelNameByID $result 1', $result);
        if (empty($result) || !isset($result[$id])) {
            $result = array();
            $response = $this->outreachApiHelper->fetchObject($or_rel_mod);
            $GLOBALS['log']->info('getOrRelNameByID $response', $response);
            if (isset($response->data)) {
                foreach ($response->data as $key => $val) {
                    $result[$val->id] = $val->attributes->name;
                }
                $this->outreachApiHelper->saveSettings($or_rel_mod, $result);
            }
            $GLOBALS['log']->info('getOrRelNameByID $result feteched', $result);
        }
        if (isset($result[$id])) {
            $name = $result[$id];
        }
        $GLOBALS['log']->info('getOrRelNameByID $result $name found', $name);
        return $name;
    }

    private function prospectRelWithORActivities(&$bean, &$data) {
        if (in_array($bean->module_dir, ['Calls', 'Tasks', 'Emails'])) {

            /*
             * Link Task with Account
             */
            $account_id = null;
            if ($bean->module_dir == 'Tasks') {
                if (isset($data->relationships->account) && isset($data->relationships->account->data) && isset($data->relationships->account->data->id)) {
                    $account_id = $data->relationships->account->data->id;
                    $GLOBALS['log']->info('prospectRelWithORActivities account $account_id', $account_id);
                    if ($account_id) {
                        $result = $this->queryBean('accounts', 'id', " or_id='$account_id' AND deleted=0 ");
                        $GLOBALS['log']->info('prospectRelWithORActivities accounts $result', $result);
                        if (isset($result[0]['id']) && !empty($result[0]['id'])) {
                            $bean->parent_id = $result[0]['id'];
                            $bean->parent_type = 'Accounts';
                        }
                    }
                }
            }

            /*
             * Link with Contact
             */
            if (isset($data->relationships->prospect)) {
                $id = $data->relationships->prospect->data->id;
                $GLOBALS['log']->info('prospectRelWithORActivities prospect $id', $id);
                if ($id) {
                    $res = $this->getBeanForProspect($id);
                    $GLOBALS['log']->info('prospectRelWithORActivities prospect $res', $res);
                    if (isset($res['id'])) {
                        if ($bean->module_dir == 'Tasks') {
                            $bean->contact_id = $res['id'];
                            if (empty($account_id)) {
                                $bean->parent_id = $res['id'];
                                $bean->parent_type = $res['module'];
                            }
                        } else {
                            $bean->parent_id = $res['id'];
                            $bean->parent_type = $res['module'];
                        }
                    }
                }
            }
        }
    }

    public function getBeanForProspect($or_id) {
        global $db;
        $res = array();
        if (!empty($or_id)) {
            $modules = array('Contacts', 'Leads');
            foreach ($modules as $key => $module) {
                $table = strtolower($module);
                $result = $this->queryBean($table, 'id', " or_id='$or_id' AND deleted=0 ");
                if (isset($result[0]['id']) && !empty($result[0]['id'])) {
                    $res['id'] = $result[0]['id'];
                    $res['module'] = $module;
                    break;
                }
            }
        }
        return $res;
    }

    public function afterSyncToSugar(&$bean, &$data) {
        global $timedate;
        $this->syncLogsCache[] = \Outreach\OutreachLanguage::parseLangauge('LBL_SYNC_SUCCESS', [
                    '@user_time' => $GLOBALS['timedate']->asUser($timedate->getNow()),
        ]);
        if (empty($bean->id)) {
            $bean->team_id = '1';
            $bean->new_with_id = true;
            $bean->assigned_user_id = $this->bindOwner($data);
            $bean->id = create_guid();
            $this->syncSugarURL($bean);
        }
        if ($bean->module_dir == 'Calls') {
            $this->bindCallsWithAccount($bean, $data);
        }
        $this->handleOneToManyRelationToSugar($bean, $data);

        if (!empty($data->id)) {
            $bean->or_id = $data->id;
        }
        $bean->or_sync_logs = $this->getSyncLogs();
        $bean->or_last_sync_time = $GLOBALS['timedate']->asUser($timedate->getNow());
        $bean->or_last_sync_time_raw = $data->attributes->updatedAt;
        $bean->or_has_synced = true;
        $orId = empty($data->id) ? $bean->or_id : $data->id;
        $bean->or_url = $this->getOutreachUrl($bean->module_dir, $orId);

        if ($bean->module_dir == 'Calls') {
            if (empty($bean->date_start) || !strtotime($bean->date_start)) {
                $GLOBALS['log']->fatal("Outreach to Sugar Info: Unble to sync the call due to incorrect start/end date. Call id= $bean->id, date_start = $bean->date_start, duration = $bean->duration");
                return;
            }
            //5 mints dummy, because there is no duration in api response
            $bean->duration_hours = 0;
            $bean->duration_minutes = 5;
        }
        $bean->sync_with_or = 1;
        $bean->disable_row_level_security = true;
        $bean->processed = true;
        $GLOBALS['log']->info("Saving $bean->module_dir $bean->id");
        try {
            $bean->save();
        } catch (Exception $exc) {
            $GLOBALS['log']->fatal("Outreach to Sugar Error: Unable to save the $bean->module_dir bean $bean->id");
            return;
        }
        $GLOBALS['log']->info("Saved $bean->module_dir $bean->id");
        if ($bean->module_dir == 'Leads') {
            $this->lead_count_sync++;
        } else {
            $this->count_sync++;
        }

        /*
         * Adding email addresses to bean
         */
        if (isset($data->attributes->emails) && count($data->attributes->emails)) {
            $this->addEmailAddressessToBean($bean, $data);
        }
        if ($bean->module_dir == 'Emails') {
            $this->linkTasksWithEmail($bean, $data);
        }
        if ($bean->module_dir == 'Opportunities') {
            $this->handleOppProspectRole($bean, $data);
        }

        $GLOBALS['log']->info('Outreach to Sugar Info: $this->syncLogsCache', $this->syncLogsCache);
        $this->syncLogsCache = [];
    }

    public function bindOwner(&$data) {
        $GLOBALS['log']->info("bindOwner: data = ", $data);
        $user_id = null;
        $orOwnerId = null;

        if (isset($data->relationships->owner) && isset($data->relationships->owner->data->id)) {
            $orOwnerId = $data->relationships->owner->data->id;
        }
        $GLOBALS['log']->info("bindOwner: orOwnerId0 = " . $orOwnerId);
        if (empty($orOwnerId) && isset($data->relationships->user) && isset($data->relationships->user->data) && isset($data->relationships->user->data->id)) {
            $orOwnerId = $data->relationships->user->data->id;
        }
        $GLOBALS['log']->info("bindOwner: orOwnerId1 = " . $orOwnerId);
        if (empty($orOwnerId) && isset($data->relationships->assignedUsers) && isset($data->relationships->assignedUsers->data) && isset($data->relationships->assignedUsers->data[0]->id)) {
            $orOwnerId = $data->relationships->assignedUsers->data[0]->id;
        }
        $GLOBALS['log']->info("bindOwner: orOwnerId2 = " . $orOwnerId);
        if (empty($orOwnerId) && isset($data->relationships->updater) && isset($data->relationships->updater->data) && isset($data->relationships->updater->data->id)) {
            $orOwnerId = $data->relationships->updater->data->id;
        }
        $GLOBALS['log']->info("bindOwner: orOwnerId3 = " . $orOwnerId);
        $outreachApiHelper = new \Outreach\OutreachApiHelper;
        $users = $outreachApiHelper->retrieveSettings("users");
        if (is_array($users) && count($users) > 0) {
            $default = $users[0];
            unset($users[0]);
            if (is_array($users)) {
                foreach ($users as $key => $user) {
                    if ($user['or_id'] == $orOwnerId) {
                        $user_id = $user['sugar_id'];
                        break;
                    }
                }
            }
        }
        if (empty($user_id)) {
            $user_id = $default['sugar_id'];
        }
        $GLOBALS['log']->info("bindOwner: user_id = " . $user_id);
        return $user_id;
    }

    public function bindCallsWithAccount(&$bean, &$data) {
        if (empty($bean->parent_type) || empty($bean->parent_id)) {
            return;
        }
        $result = $this->queryBean('accounts_contacts', 'account_id', " contact_id='$bean->parent_id' AND deleted=0 AND primary_account=1 ");
        if (!empty($result[0])) {
            /*
             * This fucntion is used to send invites to the contacts.
             * We have disabled this, we may enable it acc to requirements
             * $bean->setContactInvitees([$bean->parent_id], null);
             */
            $bean->parent_type = 'Accounts';
            $bean->parent_id = $result[0]['account_id'];
        }
    }

    public function handleOneToManyRelationToSugar(&$bean, &$data) {
        $rels = \Outreach\OutreachConfig::ONE_TO_MANY_RELATIONSHIP_OUTREACH;
        if (!isset($rels[$bean->module_dir])) {
            return;
        }
        $rel = $rels[$bean->module_dir]['relationships'];
        $GLOBALS['log']->info("handleOneToManyRelationToSugar rel", $rel);
        foreach ($rel as $key => $value) {
            $GLOBALS['log']->info("handleOneToManyRelationToSugar key: $key");
            $GLOBALS['log']->info("handleOneToManyRelationToSugar value:", $value);
            if (isset($data->relationships->{$key}) && isset($data->relationships->{$key}->data) && isset($data->relationships->{$key}->data->id)) {
                $orId = $data->relationships->{$key}->data->id;
                $GLOBALS['log']->info("handleOneToManyRelationToSugar orId: $orId");
                $beanId = $this->queryBean(strtolower($value['module']), " id ", " or_id='$orId' ");
                $GLOBALS['log']->info("handleOneToManyRelationToSugar beanId", $beanId);
                if (isset($beanId[0])) {
                    $beanId = $beanId[0]['id'];
                    $bean->{$value['sugarField']} = $beanId;
                }
            }
        }
    }

    public function addEmailAddressessToBean(&$bean, &$data) {
        $bean_emails = array();
        foreach ($bean->emailAddress->addresses as $emailId) {
            $bean_emails[] = $emailId['email_address'];
        }
        $save = false;
        foreach ($data->attributes->emails as $key => $email_address) {
            $GLOBALS['log']->info("email_address: $email_address");
            if (!in_array($email_address, $bean_emails)) {
                $primary = $key == 0 && empty($bean->email1) ? true : false;
                $bean->emailAddress->addAddress($email_address, $primary);
                $save = true;
            } else {
                $GLOBALS['log']->info("email_address: $email_address already exist");
            }
        }
        if ($save) {
            $bean->emailAddress->save($bean->id, $bean->module_dir);
        }
    }

    public function linkTasksWithEmail(&$bean, &$data) {
        if (isset($data->relationships->task->data->id)) {
            $tasks = $data->relationships->tasks->data;
            $GLOBALS['log']->info('prospectRelWithORActivities $tasks', $tasks);
            if (is_array($tasks) && count($tasks)) {
                foreach ($tasks as $key => $task) {
                    $GLOBALS['log']->info('prospectRelWithORActivities $task', $task);
                    if (!empty($task->id)) {
                        $result = $this->queryBean('tasks', 'id', " or_id='$task->id' AND deleted=0 ");
                        $GLOBALS['log']->info('prospectRelWithORActivities task $result', $result);
                        if (!empty($result[0]['id'])) {
                            if ($bean->load_relationship('tasks')) {
                                $bean->tasks->add($result[0]['id']);
                            }
                        }
                    }
                }
            }
        }
    }

    public function handleOppProspectRole(&$sugarBean, &$data) {
        $GLOBALS['log']->info('handleOppProspectRole ..........................');
        if (empty($data->id) || empty($sugarBean->id)) {
            $GLOBALS['log']->info('handleOppProspectRole .........empty data returning.................');
            return;
        }
        /*
         * Get prospect related to the Opp
         */
        $url = \Outreach\OutreachConfig::BASE_URL . "opportunities/$data->id?include=prospects"; //?include=opportunityStage,prospects
        $res = $this->outreachApiHelper->fetchObject($url, true);
        $GLOBALS['log']->info('$url is', $url);
        $GLOBALS['log']->info('$res is', $res);
        if (!isset($res->data) || !isset($res->included) || empty($res->data->id) || !isset($res->included[0])) {
            $GLOBALS['log']->info('handleOppProspectRole .........empty data returning 2.................');
            return;
        }

        $prospect = $res->included[0];
        $GLOBALS['log']->info('$prospect is', $prospect);
        if (empty($prospect->id)) {
            return;
        }
        $res = $this->getBeanForProspect($prospect->id);
        $GLOBALS['log']->info('getBeanForProspect $res is', $res);
        if (isset($res['id'])) {
            $prospectBean = BeanFactory::getBean($res['module'], $res['id'], array('disable_row_level_security' => true));
        } else {
            return;
        }
        $GLOBALS['log']->info('$prospectBean id is', $prospectBean->id);
        if (empty($prospectBean->id)) {
            return;
        }
        $prospectRelation = strtolower($prospectBean->module_dir); //leads or contacts
        if ($sugarBean->load_relationship($prospectRelation)) {
            $filter = "?filter[opportunity][id]={$data->id}&filter[prospect][id]={$prospect->id}";
            $url = \Outreach\OutreachConfig::BASE_URL . "opportunityProspectRoles" . $filter;
            $url = str_replace(' ', '%20', $url);
            $response = $this->outreachApiHelper->fetchObject($url, true);

            $GLOBALS['log']->info('$url is', $url);
            $GLOBALS['log']->info('$response is', $response);
            if (!isset($response->meta)) {
                return;
            }
            if ($response->meta->count == 1) {
                $roleId = $response->data[0]->id;
            } else if ($response->meta->count > 1) {
                $GLOBALS['log']->info('handleOppProspectRole:Interal outreach error, duplicate Prospect Roles found');
                return;
            }

            $GLOBALS['log']->info('$sugarBean->or_relationship_cache was', $sugarBean->or_relationship_cache);
            $GLOBALS['log']->info('$prospectBean->or_relationship_cache was', $prospectBean->or_relationship_cache);
            $relationshipCacheOppSide = unserialize($sugarBean->or_relationship_cache);
            $relationshipCacheProspectSide = unserialize($prospectBean->or_relationship_cache);
            $GLOBALS['log']->info('$relationshipCacheOppSide was', $relationshipCacheOppSide);
            $GLOBALS['log']->info('$relationshipCacheProspectSide was', $relationshipCacheProspectSide);
            $GLOBALS['log']->info('$relationshipCacheProspectSide was', unserialize($sugarBean->or_relationship_cache));
            if (empty($relationshipCacheOppSide['opportunityProspectRoles'][$prospectBean->module_dir][$prospectBean->id])) {
                $relationshipCacheOppSide['opportunityProspectRoles'][$prospectBean->module_dir][$prospectBean->id] = $roleId;
                $relationshipCacheProspectSide['opportunityProspectRoles'][$sugarBean->module_dir][$sugarBean->id] = $roleId;
                $GLOBALS['log']->info('$relationshipCacheOppSide is 1', $relationshipCacheOppSide);
                $GLOBALS['log']->info('$relationshipCacheProspectSide is 1', $relationshipCacheProspectSide);
                $relationshipCacheOppSide = serialize($relationshipCacheOppSide);
                $relationshipCacheProspectSide = serialize($relationshipCacheProspectSide);

                $sugarBean->or_relationship_cache = $relationshipCacheOppSide;
                $this->updateSelectedRecords(strtolower($sugarBean->table_name), " or_relationship_cache='$relationshipCacheOppSide' ", $sugarBean->id);
                $this->updateSelectedRecords(strtolower($prospectBean->table_name), " or_relationship_cache='$relationshipCacheProspectSide' ", $prospectBean->id);

                $sugarBean->$prospectRelation->add($prospectBean->id);
                $GLOBALS['log']->info('Adding rel with contact', $prospectBean->id);
            } else if (empty($roleId) && !empty($relationshipCacheOppSide['opportunityProspectRoles'][$prospectBean->module_dir][$prospectBean->id])) {
                unset($relationshipCacheOppSide['opportunityProspectRoles'][$prospectBean->module_dir][$prospectBean->id]);
                unset($relationshipCacheProspectSide['opportunityProspectRoles'][$sugarBean->module_dir][$sugarBean->id]);
                $GLOBALS['log']->info('$relationshipCacheOppSide is 2', $relationshipCacheOppSide);
                $GLOBALS['log']->info('$relationshipCacheProspectSide is 2', $relationshipCacheProspectSide);
                $relationshipCacheOppSide = serialize($relationshipCacheOppSide);
                $relationshipCacheProspectSide = serialize($relationshipCacheProspectSide);

                $this->updateSelectedRecords(strtolower($sugarBean->table_name), " or_relationship_cache='$relationshipCacheOppSide' ", $sugarBean->id);
                $this->updateSelectedRecords(strtolower($prospectBean->table_name), " or_relationship_cache='$relationshipCacheProspectSide' ", $prospectBean->id);

                $sugarBean->$prospectRelation->remove($prospectBean->id);
                $GLOBALS['log']->info('Deleting rel with contact', $prospectBean->id);
            }
        }
    }

    /*
     * Logic to sync records from Sugarcrm to Outreach
     */

    public function triggerLogic(&$bean, $source, $dataChanges, $is_recursive = false) {
        $GLOBALS['log']->info('triggerLogic $dataChanges', $dataChanges);
        $GLOBALS['log']->info('triggerLogic $bean->sync_with_or', $bean->sync_with_or);
        $this->sugar_to_or_synced = false;
        $this->syncLogsCache = [];
        if (!$is_recursive) {
            $this->dataChanges = $dataChanges;
            $this->source = $source;
        }

        if (!$this->isValidLicense()) {
            $returnArray['message'] = 'Invalid Outreach License key.';
            $returnArray['has_synced'] = false;
            $returnArray['errorCode'] = 'expired_key';
            $this->updateSelectedRecords(
                    strtolower($bean->module_dir),
                    " or_sync_logs ='{$returnArray['message']}' ",
                    $bean->id
            );
            return;
        }
        if ($bean->or_deleted && !empty($bean->or_id)) {
            $mapping = $this->outreachApiHelper->retrieveSettings($bean->module_dir);
            if (isset($mapping['allow_delete']) && $mapping['allow_delete'] == 'Yes') {
                $GLOBALS['log']->fatal("Outreach: Allow Delete is enable for this module so the $bean->module_dir $bean->id has been deleted from Outreach");
                $or_module = \Outreach\OutreachConfig::SUGAR_OUTREACH_OBJECT_MAPPING_PLURAL[$bean->module_dir];
                $this->outreachApiHelper->deleteObject($or_module, $bean->or_id);
            } else {
                $GLOBALS['log']->fatal("Outreach: Allow Delete is disable for this module so the $bean->module_dir $bean->id will not be deleted from Outreach");
            }
            return;
        }

        /*
         * In case of Opportunity, lets sync related account and contacts
         */
        if ($bean->module_dir == 'Opportunities') {
            if (!empty($bean->account_id)) {
                $linkedAccBean = BeanFactory::getBean('Accounts', $bean->account_id, array('disable_row_level_security' => true));
                $GLOBALS['log']->info('triggerLogic $linkedAccBean id', $linkedAccBean->id);
                $GLOBALS['log']->info('triggerLogic $linkedAccBean or_id', $linkedAccBean->or_id);
                if (empty($linkedAccBean->or_id)) {
                    $linkedAccBean->sync_with_or = true;
                    $GLOBALS['log']->info('triggerLogic syncing this acount first..................', $linkedAccBean->id);
                    $this->triggerLogic($linkedAccBean, 'api', array(), true);
                }
            }

            $sugarRelation = 'contacts';
            if ($bean->load_relationship($sugarRelation)) {
                $linkedContacts = $bean->$sugarRelation->getBeans();
                foreach ($linkedContacts as $linkedContact) {
                    if (empty($linkedContact->or_id)) {
                        $linkedContact->sync_with_or = true;
                        $GLOBALS['log']->info('triggerLogic syncing this $linkedContact first..................', $linkedContact->id);
                        $this->triggerLogic($linkedContact, 'api', array(), true);
                    }
                }
            }
        }

        $action = empty($bean->or_id) ? 'created' : 'updated';
        if ($action) {
            $this->syncFromSugar([
                'module' => $bean->module_dir,
                'action' => $action,
                'sugarId' => $bean->id,
                'source' => $source
            ]);
        }
        if (!empty($this->syncLogsCache)) {
            $returnArray['message'] = $this->syncLogsCache;
            $GLOBALS['log']->info('Logssss $returnArray', $returnArray);
        }
    }

    public function syncFromSugar($params) {
        $GLOBALS['log']->info('syncFromSugar $params', $params);
        if (empty($params['module']) || empty($params['action']) || empty($params['sugarId'])) {
            $SQL = " or_sync_logs = 'An unknown error occured' ";
            $this->updateSelectedRecords(strtolower($params['module']), $SQL, $params['sugarId']);
            return;
        }

        $mapping = $this->outreachApiHelper->retrieveSettings($params['module']);
        if (!isset($mapping['is_enable']) || $mapping['is_enable'] == 'No') {
            $SQL = " or_sync_logs = 'This module is not enable for sync' ";
            $this->updateSelectedRecords(strtolower($params['module']), $SQL, $params['sugarId']);
            return;
        }
        $bean = \BeanFactory::getBean($params['module'], $params['sugarId'], array('disable_row_level_security' => true));

        if (in_array($params['module'], \Outreach\OutreachConfig::UPDATE_DISABLED_MODULES) && !empty($bean->or_id)) {
            $this->syncLogsCache[] = \Outreach\OutreachLanguage::parseLangauge('LBL_ERR_UPDATING', []);
            $logs = $this->getSyncLogs();
            $SQL = " or_sync_logs = '{$logs}'";
            $this->updateSelectedRecords($bean->table_name, $SQL, $bean->id);
            return;
        }

        if (in_array($params['module'], \Outreach\OutreachConfig::CREATE_DISABLED_MODULES) && empty($bean->or_id)) {
            $this->syncLogsCache[] = \Outreach\OutreachLanguage::parseLangauge('LBL_ERR_CREATE', []);
            $logs = $this->getSyncLogs();
            $SQL = " or_sync_logs = '{$logs}'";
            $this->updateSelectedRecords($bean->table_name, $SQL, $bean->id);
            return;
        }

        $outreachId = $this->getApiUserIdFromSugar($bean->assigned_user_id);
        $GLOBALS['log']->info('syncFromSugar $outreachId', $outreachId);
        if (!$outreachId) {
            $SQL = " or_sync_logs = 'User mapping is not added for the current user' ";
            $this->updateSelectedRecords(strtolower($params['module']), $SQL, $params['sugarId']);
            return;
        }
        $GLOBALS['log']->info('syncFromSugar $mapping', $mapping);
        $this->setSyncFields($mapping, 'toApi');
        $GLOBALS['log']->info('syncFromSugar $this->syncFields', $this->syncFields);
        if (count($this->syncFields) == 0) {
            $GLOBALS['log']->fatal("Sugar to Outreach Info: Nothing to update");
            return;
        }
        if (!empty($bean)) {
            $this->syncLogsCache = [];
            if ($params['source'] == 'api') {
                $isQualified = true;
            } else {
                $isQualified = $this->qualifyBeanRecord($bean);
            }
            $GLOBALS['log']->info('syncFromSugar $isQualified', $isQualified);
            $logs = $this->getSyncLogs();
            $GLOBALS['log']->info('syncFromSugar $logs', $logs);
            if (!$isQualified && !empty($logs)) {
                $SQL = " or_sync_logs = '{$logs}'";
                $this->updateSelectedRecords($bean->table_name, $SQL, $bean->id);
                return;
            } else if (!$isQualified) {
                return;
            }
            $this->syncSugarURL($bean);
            $relationships = [];
            $dataToSync = [];
            $logicObje = new \Outreach\OutreachSugarToApiFieldLogic();
            $logicObje->sugarBean = &$bean;
            foreach ($this->syncFields as $outreachField => $sugarField) {
                $GLOBALS['log']->info('syncFromSugar $outreachField', $outreachField);
                $GLOBALS['log']->info('syncFromSugar $sugarField', $sugarField);
                $response = $logicObje->execute([
                    'dataToSync' => $dataToSync,
                    'sugarField' => $sugarField,
                    'apiField' => $outreachField,
                ]);
                $GLOBALS['log']->info('syncFromSugar $response', $response);
                if (isset($response['dataToSync'])) {
                    if (isset($response['dataToSync']['relationship'])) {
                        if (isset($response['dataToSync']['relationship'][$outreachField]))
                            $relationships[$outreachField] = $response['dataToSync']['relationship'][$outreachField];
                    } else {
                        $dataToSync = $response['dataToSync'];
                    }
                }
                $GLOBALS['log']->info('$this->syncFields $dataToSync', $dataToSync);
                $GLOBALS['log']->info('$this->syncFields $relationships', $relationships);
                if (isset($response['error'])) {
                    $this->syncLogsCache[] = $response['error'];
                }
            }
            if (in_array($bean->module_dir, ['Leads', 'Contacts'])) {
                $this->setEmailAddresses($bean, $dataToSync);
            }
            $GLOBALS['log']->info('syncFromSugar final $dataToSync', $dataToSync);
            $params['tableName'] = $bean->table_name;
            $GLOBALS['log']->info('syncFromSugar $relationships before', $relationships);
            $owner = \Outreach\OutreachConfig::OUTREACH_OWNER_FIELD_NAME[$params['module']];
            $relationships[$owner] = array(
                "data" => array(
                    "id" => $outreachId,
                    "type" => "user"
                )
            );

            if ($bean->module_dir == 'Opportunities') {
                if (!empty($bean->sales_stage)) {
                    $GLOBALS['log']->info('syncFromSugar $bean->sales_stage', $bean->sales_stage);
                    $id = $this->getOrRelIdByName('opportunityStage', $bean->sales_stage);
                    $GLOBALS['log']->info('syncFromSugar $id', $id);
                    if (!empty($id)) {
                        $relationships["opportunityStage"] = array(
                            "data" => array(
                                "id" => $id,
                                "type" => "opportunityStage"
                            )
                        );
                    }
                }
                if (!empty($bean->account_id)) {
                    $linkedAccBean = BeanFactory::getBean('Accounts', $bean->account_id, array('disable_row_level_security' => true));
                    if (!empty($linkedAccBean->or_id)) {
                        $relationships["account"] = array(
                            "data" => array(
                                "id" => $linkedAccBean->or_id,
                                "type" => "account"
                            )
                        );
                    }
                }
            }

            $this->setFlexRelationships($relationships, $bean);
            $this->setOneToManyRelationship($relationships, $bean);
            $GLOBALS['log']->info('syncFromSugar $relationships final', $relationships);
            /*
             * Seting Opportunity Record Id
             */
            if ($params['action'] == $this::ACTION_CREATED && !in_array($bean->module_dir, ['Calls', 'Tasks', 'Emails'])) {
                $dataToSync['custom35'] = $bean->id;
            }

            if (!isset($dataToSync['emails']) && in_array($bean->module_dir, ['Contacts', 'Leads'])) {
                foreach ($bean->emailAddress->addresses as $emailId) {
                    $dataToSync['emails'][] = $emailId['email_address'];
                }
            }
            $GLOBALS['log']->info('$dataToSync', $dataToSync);
            if (is_array($dataToSync) && count($dataToSync)) {
                $outreachType = \Outreach\OutreachConfig::SUGAR_OUTREACH_OBJECT_MAPPING_SINGULAR[$params['module']];
                $data = array(
                    'type' => $outreachType,
                    'attributes' => $dataToSync,
                );
                if ($bean->or_id) {
                    $data['id'] = (int) $bean->or_id;
                }
                if (is_array($relationships) && count($relationships) > 0) {
                    $data['relationships'] = $relationships;
                }
                $GLOBALS['log']->info('pushToApi final $data', $data);
                $or_id = $this->pushToApi($data, $params, $bean);
                $bean->or_id = $or_id;
                $this->setManyToManyRelationship($bean);
            }
        }
    }

    public function setEmailAddresses(&$bean, &$dataToSync) {
        if (isset($bean->email) && is_array($bean->email) && count($bean->email)) {
            $emails = array();
            foreach ($bean->email as $key => $email) {
                $emails[] = $email['email_address'];
            }
            $dataToSync['emails'] = $emails;
        }
    }

    public function getOrRelIdByName($orObj, $name) {
        $GLOBALS['log']->info('getOrRelIdByName $orObj', $orObj);
        $GLOBALS['log']->info('getOrRelIdByName $name', $name);
        $id = null;
        if (!empty($orObj) && !empty($name)) {
            $or_rel_mod = \Outreach\OutreachConfig::OUTREACH_PLURAL_MAPPING[$orObj];
            $stages = $this->outreachApiHelper->retrieveSettings($or_rel_mod);
            $GLOBALS['log']->info('getOrRelIdByName $stages 1', $stages);
            $stages = empty($stages) ? array() : $stages;
            $flipstages = array_flip($stages);
            $GLOBALS['log']->info('getOrRelIdByName $flipstages 1', $stages);
            if (isset($flipstages[$name])) {
                $id = $flipstages[$name];
                $GLOBALS['log']->info('getOrRelIdByName $stages id found', $id);
            } else {
                $stages = array();
                $response = $this->outreachApiHelper->fetchObject($or_rel_mod);
                $GLOBALS['log']->info('getOrRelIdByName $response', $response);
                if (isset($response->data)) {
                    foreach ($response->data as $key => $stage) {
                        $stages[$stage->id] = $stage->attributes->name;
                        if ($stage->attributes->name == $name) {
                            $id = $stage->id;
                        }
                    }
                    $this->outreachApiHelper->saveSettings($or_rel_mod, $stages);
                }
                $GLOBALS['log']->info('getOrRelIdByName $stages feteched', $stages);
            }
            if (empty($id)) {
                $data = array("attributes" => array("name" => $name), "type" => $orObj);
                $response = $this->outreachApiHelper->createObject(json_encode(['data' => $data]), $or_rel_mod);
                $GLOBALS['log']->info('getOrRelIdByName create $response', $response);
                if (isset($response->data)) {
                    $stages[$response->data->id] = $name;
                    $this->outreachApiHelper->saveSettings($or_rel_mod, $stages);
                    $id = $stages[$name];
                }
            }
        }
        return $id;
    }

    private function setFlexRelationships(&$relationships, &$sugarRecord) {
        if (in_array($sugarRecord->module_dir, ['Calls', 'Tasks', 'Emails']) &&
                (in_array($sugarRecord->parent_type, ['Contacts', 'Leads']) || !empty($sugarRecord->contact_id))
        ) {
            $additionalRelations = \Outreach\OutreachConfig::ADDITIONAL_RELATIONSHIPS[$sugarRecord->module_dir];
            $GLOBALS['log']->info('$additionalRelations', $additionalRelations);
            foreach ($additionalRelations as $outreachObj => $sugarField) {
                $GLOBALS['log']->info('$outreachObj', $outreachObj);
                $GLOBALS['log']->info('$sugarField', $sugarField);
                if (is_array($sugarField)) {
                    $type = array_keys($sugarField)[0];
                    $GLOBALS['log']->info('$type 1', $type);
                    $sugarField = $sugarField[$type];
                    $GLOBALS['log']->info('$sugarField 1', $sugarField);
                } else {
                    $type = $outreachObj;
                    $GLOBALS['log']->info('$type 2', $type);
                }
                $GLOBALS['log']->info('$sugarField final', $sugarField);
                $id = null;
                if ($sugarRecord->parent_type != 'Contacts' && !empty($sugarRecord->contact_id)) {
                    $id = $this->getParentOutreachIdFromSugar('Contacts', $sugarRecord->contact_id);
                } else if (in_array($sugarRecord->parent_type, ['Contacts', 'Leads'])) {
                    $id = $this->getParentOutreachIdFromSugar($sugarRecord->parent_type, $sugarRecord->$sugarField);
                }
                $GLOBALS['log']->info('parent outreach $id', $id);

                if (!empty($id)) {
                    $relationships[$outreachObj] = [
                        "data" => [
                            "type" => $type,
                            'id' => $id
                        ]
                    ];
                }
            }
        }
    }

    private function getParentOutreachIdFromSugar($module, $sugarId) {
        $table = strtolower($module);
        $result = $this->queryBean($table, 'or_id', "id = '{$sugarId}' AND deleted = 0");
        return $result[0]['or_id'];
    }

    public function setOneToManyRelationship(&$relationships, &$sugarBean) {
        $GLOBALS['log']->info('setOneToManyRelationship $sugarBean->module_dir', $sugarBean->module_dir);
        if (in_array($sugarBean->module_dir, ['Leads', 'Contacts'])) {
            $sugarLinks = \Outreach\OutreachConfig::ONE_TO_MANY_RELATIONSHIP[$sugarBean->module_dir];
            $GLOBALS['log']->info('setOneToManyRelationship $sugarLinks', $sugarLinks);
            foreach ($sugarLinks as $sugarLink => $value) {
                $GLOBALS['log']->info('setOneToManyRelationship $sugarLink', $sugarLink);
                $GLOBALS['log']->info('setOneToManyRelationship $value', $value);
                if ($sugarBean->load_relationship($sugarLink)) {
                    $linkedBeans = $sugarBean->$sugarLink->getBeans();
                    foreach ($linkedBeans as $linkedBean) {
                        $GLOBALS['log']->info('setOneToManyRelationship $linkedBean', $linkedBean->module_dir);
                        $this->setProspectAccountRelation($sugarBean, $linkedBean, $value, $relationships);
                    }
                }
            }
        }
    }

    public function setProspectAccountRelation(&$sugarBean, &$linkedBean, $value, &$relationships) {
        if (!empty($linkedBean->or_id)) {
            if ($value['is_array']) {
                $relationships[$value['outreachField']] = [
                    'data' => [
                        [
                            'type' => $value['type'],
                            'id' => (int) $linkedBean->or_id,
                        ]
                    ]
                ];
            } else {
                $relationships[$value['outreachField']] = [
                    'data' => [
                        'type' => $value['type'],
                        'id' => (int) $linkedBean->or_id,
                    ]
                ];
            }
            $GLOBALS['log']->info('setProspectAccountRelation add to $relationships', $relationships);
        }
    }

    public function pushToApi($data, $params, &$bean) {
        $GLOBALS['log']->info('pushToApi $data', $data);
        $GLOBALS['log']->info('pushToApi $params', $params);
        $GLOBALS['log']->info('pushToApi $bean', $bean->id);

        $or_module = \Outreach\OutreachConfig::SUGAR_OUTREACH_OBJECT_MAPPING_PLURAL[$params['module']];

        if ($params['action'] == $this::ACTION_CREATED) {
            $response = $this->outreachApiHelper->createObject(json_encode(['data' => $data]), $or_module);
        } else if ($params['action'] == $this::ACTION_UPDATED) {
            $response = $this->outreachApiHelper->updateObject(json_encode(['data' => $data]), $or_module, $bean->or_id);
        }
        $this->afterSyncFromSugar($response, $params, $bean);
    }

    public function afterSyncFromSugar($response, $params, &$bean) {
        $GLOBALS['log']->info('afterSyncFromSugar $response', $response);
        global $timedate;
        $user_time = $timedate->asUser($timedate->getNow());
        $syncFlag = 0;
        $url = null;
        $orId = $bean->or_id;
        if (isset($response->errors)) {
            $this->parseErrorsFromApi($response->errors);
        } else if (isset($response->error)) {
            $this->parseErrorsFromApi($response->error);
        } else if (empty($response->data->id)) {
            $this->syncLogsCache[] = \Outreach\OutreachLanguage::parseLangauge('LBL_ERR_CON', []);
        } else {
            $orId = $response->data->id;
            $syncFlag = 1;
        }
        $success_lbl = $syncFlag ? 'LBL_SYNC_SUCCESS' : 'LBL_ATTEMPTED_SUCCESS';
        $this->syncLogsCache[] = \Outreach\OutreachLanguage::parseLangauge($success_lbl, ['@user_time' => $user_time]);
        $logs = addslashes($this->getSyncLogs());
        $SQL = " or_sync_logs = '$logs', or_has_synced = '$syncFlag',or_last_sync_time = '{$user_time}' ";
        if (!empty($orId)) {
            $this->sugar_to_or_synced = true;
            $url = $this->getOutreachUrl($bean->module_dir, $orId);
            $SQL .= ", sync_with_or = 1, or_id = '$orId', or_last_sync_time_raw = '{$response->data->attributes->updatedAt}', or_url='$url' ";
        }
        $this->updateSelectedRecords($params['tableName'], $SQL, $params['sugarId']);
        return $orId;
    }

    public function setManyToManyRelationship(&$sugarBean) {
        $obj = \Outreach\OutreachConfig::MANY_TO_MANY_RELATIONSHIP;
        if (isset($obj[$sugarBean->module_dir])) {
            $sugarLinks = $obj[$sugarBean->module_dir];
            foreach ($sugarLinks as $sugarLink => $value) {
                if ($sugarBean->load_relationship($sugarLink)) {
                    $linkedBeans = $sugarBean->$sugarLink->getBeans();
                    foreach ($linkedBeans as $linkedBean) {
                        $this->setProspectOpportunityRelation($sugarBean, $linkedBean, $value);
                    }
                }
            }
        }
    }

    public function setProspectOpportunityRelation(&$sugarBean, &$linkedBean, $arg) {
        $GLOBALS['log']->info('setProspectOpportunityRelation $linkedBean', $linkedBean->id);
        $GLOBALS['log']->info('setProspectOpportunityRelation $arg', $arg);
        if ($arg['function'] != 'setProspectOpportunityRelation') {
            return;
        }
        $relationshipCacheOppSide = unserialize($linkedBean->or_relationship_cache);
        $GLOBALS['log']->info('setProspectOpportunityRelation $relationshipCacheOppSide', $relationshipCacheOppSide);
        $relationshipCacheProspectSide = unserialize($sugarBean->or_relationship_cache);
        $GLOBALS['log']->info('setProspectOpportunityRelation $relationshipCacheProspectSide', $relationshipCacheProspectSide);
        if (!empty($relationshipCacheProspectSide['opportunityProspectRoles'][$linkedBean->module_dir][$linkedBean->id])) {
            /**
             * TODO instead of preventing the udpate, we can send the updated Opp-Prospect Role
             */
            return;
        }

        if (empty($sugarBean->or_id) || empty($linkedBean->or_id)) {
            return;
        }

        $rel = [
            'prospect' => [
                'data' => [
                    'type' => 'prospect',
                    'id' => $arg['link'] == 'contacts' || $arg['link'] == 'leads' ? (int) $linkedBean->or_id : (int) $sugarBean->or_id,
                ]
            ],
            'opportunity' => [
                'data' => [
                    'type' => 'opportunity',
                    'id' => ($arg['link'] == 'opportunities' || $arg['link'] == 'opportunity') ? (int) $linkedBean->or_id : (int) $sugarBean->or_id,
                ]
            ],
        ];
        $GLOBALS['log']->info('setProspectOpportunityRelation $rel', $rel);
        $outReachObjectPlural = 'opportunityProspectRoles';
        $filter = "?filter[opportunity][id]={$rel['opportunity']['data']['id']}&filter[prospect][id]={$rel['prospect']['data']['id']}";
        $url = $outReachObjectPlural . $filter;
        $url = str_replace(' ', '%20', $url);
        $GLOBALS['log']->info('setProspectOpportunityRelation $url', $url);
        $response = $this->outreachApiHelper->fetchObject($url);
        $GLOBALS['log']->info('setProspectOpportunityRelation $response', $response);
        if ($response->meta->count > 0) {
            $GLOBALS['log']->info('Role already exist');
            return;
        }

        $data = array(
            'type' => 'opportunityProspectRole',
            'attributes' => array()
        );
        $data['relationships'] = $rel;
        $GLOBALS['log']->info('setProspectOpportunityRelation opportunityProspectRoles $data', $data);
        $response = $this->outreachApiHelper->createObject(json_encode([
            'data' => $data
                ]), 'opportunityProspectRoles');
        $GLOBALS['log']->info('setProspectOpportunityRelation opportunityProspectRoles $response', $response);
        if (!empty($response->data->id)) {
            $relationshipCacheOppSide['opportunityProspectRoles'][$sugarBean->module_dir][$sugarBean->id] = $response->data->id;
            $relationshipCacheProspectSide['opportunityProspectRoles'][$linkedBean->module_dir][$linkedBean->id] = $response->data->id;
            $relationshipCacheOppSide = serialize($relationshipCacheOppSide);
            $GLOBALS['log']->info('setProspectOpportunityRelation $relationshipCacheOppSide', $relationshipCacheOppSide);
            $relationshipCacheProspectSide = serialize($relationshipCacheProspectSide);
            $GLOBALS['log']->info('setProspectOpportunityRelation $relationshipCacheProspectSide', $relationshipCacheProspectSide);
            $this->updateSelectedRecords(strtolower($linkedBean->table_name), " or_relationship_cache='$relationshipCacheOppSide' ", $linkedBean->id);
            $this->updateSelectedRecords(strtolower($sugarBean->table_name), " or_relationship_cache='$relationshipCacheProspectSide' ", $sugarBean->id);
        }
    }

    public function addCustomFieldsToSync($module) {
        if (!empty($module)) {
            foreach (\Outreach\OutreachConfig::CUSTOM_SYNC_FIELDS as $key => $value) {
                if ($key != $module) {
                    continue;
                }
                foreach ($value as $a => $b) {
                    $this->syncFields[$a] = $b;
                }
            }
        }
    }

    public function getApiUserIdFromSugar($sugarUserId) {
        $or_user_id = null;
        $users = $this->outreachApiHelper->retrieveSettings("users");
        if (is_array($users) && count($users) > 0) {
            $default = $users[0];
            unset($users[0]);
            if (is_array($users)) {
                foreach ($users as $key => $user) {
                    if (is_array($user) && $user['sugar_id'] == $sugarUserId) {
                        $or_user_id = $user['or_id'];
                        break;
                    }
                }
            }
            if (empty($or_user_id)) {
                $or_user_id = $default['or_id'];
            }
        }
        return $or_user_id;
    }

    public function setSyncFields($mapping, $mode) {
        if (is_array($mapping) && isset($mapping['fields_config']) && !empty($mapping['module']) && is_array($mapping['fields_config'])) {
            $this->field_mapping = $mapping['fields_config'];
            if ($mode == 'toApi') {
                $this->syncFields = array_merge(
                        $this->getFieldsUsingDirectionFilter(self::DIRECTION_BIDIRECTIONAL),
                        $this->getFieldsUsingDirectionFilter(self::DIRECTION_SUGAR_TO_OUTREACH)
                );
            } else if ($mode == 'fromApi') {
                $this->syncFields = array_merge(
                        $this->getFieldsUsingDirectionFilter(self::DIRECTION_BIDIRECTIONAL),
                        $this->getFieldsUsingDirectionFilter(self::DIRECTION_OUTREACH_TO_SUGAR)
                );
            }
        }
        $this->addCustomFieldsToSync($mapping['module']);
    }

    private function getFieldsUsingDirectionFilter($direction_filter) {
        $return_array = array();
        foreach ($this->field_mapping as $key => $arr) {
            if ($arr['direction'] == $direction_filter) {
                if (!empty($arr['or_field']) && !empty($arr['sugar_field'])) {
                    $return_array[$arr['or_field']] = $arr['sugar_field'];
                }
            }
        }
        return $return_array;
    }

    public function queryBean($table, $select, $where) {
        global $db;
        $query = ' SELECT ' . $select;
        $query .= ' FROM ' . $table;
        $query .= ' WHERE ' . $where;
        $result = [];
        $rows = $db->query($query);
        if ($row = $db->fetchByAssoc($rows)) {
            array_push($result, $row);
        }
        return $result;
    }

    public function qualifyBeanRecord(&$sugarBean) {
        if (isset($this->dataChanges["dataChanges"]["sync_with_or"]) && $this->dataChanges["dataChanges"]["sync_with_or"]["after"] == 1) {
            return true;
        }
        if ($sugarBean->module_dir == 'Calls' && isset($this->dataChanges["dataChanges"]["parent_id"])) {
            return true;
        }

        $syncFields = array_values($this->syncFields);
        $syncFields[] = 'assigned_user_id';
        if (!is_array($this->dataChanges["dataChanges"])) {
            return false;
        }
        $changedFields = array_keys($this->dataChanges["dataChanges"]);
        $intersection = array_intersect($changedFields, $syncFields);
        if (count($intersection) == 0) {
            return false;
        }
        return true;
    }

    public function getOutreachUrl($module, $OutreachId) {
        if (!empty($module) && !empty($OutreachId)) {
            $object = \Outreach\OutreachConfig::SUGAR_OUTREACH_OBJECT_MAPPING_PLURAL[$module];
            if (!empty($object)) {
                return \Outreach\OutreachConfig::RECORD_URL . $object . "/" . $OutreachId;
            }
        }
        return 'N/A';
    }

    public function syncSugarURL(&$bean) {
        global $sugar_config;
        if ($bean->module_dir != 'Contacts' && $bean->module_dir != 'Accounts' && $bean->module_dir != 'Leads') {
            return;
        }
        $url = $sugar_config['site_url'] . "#$bean->module_dir/{$bean->id}";

        $syncFields = array_flip($this->syncFields);

        if (empty($syncFields['or_sugar_url'])) {
            return;
        }

        $dataToSync[$syncFields['or_sugar_url']] = $url;

        $response = $this->outreachApiHelper
                ->updateObject($dataToSync, $this
                ->getApiUrl(\Outreach\OutreachConfig::getActivitesModuleURL($bean->module_dir) . "/" . $bean->or_id));

        $GLOBALS['log']->info('$response syncSugarURL', $response);
    }

    public function getApiUrl($object) {
        return empty($object) ? false : \Outreach\OutreachConfig::BASE_URL . $object;
    }

    public function getSyncLogs() {
        $logsToReturn = '';
        $count = 1;
        foreach ($this->syncLogsCache as $logs) {
            $logsToReturn .= $count . ") " . $logs . "\n";
            $count++;
        }
        return $logsToReturn;
    }

    public function parseErrorsFromApi($errors) {
        if (is_object($errors) || is_array($errors)) {
            foreach ($errors as $key => $value) {
                if (is_object($value)) {
                    $this->syncLogsCache[] = "Fatal: " . $value->title . ": " . $value->detail;
                }
            }
        } else {
            $this->syncLogsCache[] = $errors;
        }
    }

    public function isValidLicense() {
        $licenseObj = new \Outreach\OutreachOutfittersLicense();
        $license = $this->outreachApiHelper->retrieveSettings('license');
        if ($license['checked_date'] != date('Y-m-d')) {
            $licenseObj->validate($license['license_key']);
            $license = $this->outreachApiHelper->retrieveSettings('license');
        }
        return $license['is_valid'] == 1 ? true : false;
    }

    public function updateSelectedRecords($table_name, $sql_cstm, $ids_string) {
        global $db;
        if (!empty($table_name) && !empty($ids_string) && !empty($sql_cstm)) {
            $ids_string = rtrim(trim($ids_string, "'"), ", '");
            $ids_string = "'$ids_string'";
            $GLOBALS['log']->info(".............. update $table_name set $sql_cstm where id IN ($ids_string)");
            $db->query("update $table_name set $sql_cstm where id IN ($ids_string)");
        } else {
            $GLOBALS['log']->fatal("Outreach: Could not update the record due to missing parameters table_name = $table_name, sql_cstm = $sql_cstm, ids_string = $ids_string");
        }
    }
}

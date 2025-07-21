<?php

/**
 * Organization: Brainsell
 * Author: Muhammad Tariq Ibrar
 * Email: tibrar@brainsell.com, engrtariqibrar@gmail.com
 * Linkedin: https://www.linkedin.com/in/engrtariqibrar
 */

namespace Outreach;

class OutreachConfig {
    const MAP_ENUM_WITH_VALUE = true;
    const BASE_URL = 'https://api.outreach.io/api/v2/';
    const OPPORTUNITY_PROSPECT = 'opportunityProspectRole';
    const RECORD_URL = 'https://web.outreach.io/';

    public static $fieldsWithNoWebHookResponse = array(
        'prospects' => array(
            'emails',
            'tags',
            'workPhones', // 
            'homePhones',
            'otherPhones',
            'mobilePhones',
            'voipPhones'
        )
    );
    public static $outReachFieldMeta = array(
        'Leads' => array(
            'dateOfBirth' => array(
                'type' => 'date'
            )
        )
    );
    public static $sugarFieldAsOutReachRelationships = array(
        'Leads' => array(
            'workPhones' => 'phone_work',
            'homePhones' => 'phone_home',
            'otherPhones' => 'phone_other',
            'mobilePhones' => 'phone_mobile',
            'voipPhones' => '',
        ),
        'Contacts' => array(
            'workPhones' => 'phone_work',
            'homePhones' => 'phone_home',
            'otherPhones' => 'phone_other',
            'mobilePhones' => 'phone_mobile',
            'voipPhones' => '',
        ),
    );
    public static $outReachPhonesTypes = array(
        'phone_work' => 'work',
        'phone_home' => 'home',
        'phone_mobile' => 'mobile',
        'phone_other' => 'other'
    );
    public static $outReachRelationships = array(
        'phoneNumbers' => array(
            'name' => 'phoneNumber'
        )
    );
    public static $commonExcludedFields = array(
        'addedAt'
        //, 'tags'
        , 'timeZone', 'timeZoneInferred', 'timeZoneIana', 'updatedAt', 'touchedAt', 'updater', 'prospectingRepId', 'activeSequenceStates', 'persona', 'creator' //This will not be mapped by user but will be mapped internally
        , 'owner' //This will not be mapped by user but will be mapped internally
        , 'defaultPluginMapping', 'batches', 'externalCreatedAt', 'sequenceStates'
        //, 'mailings'
        , 'favorites', 'graduationDate', 'jobStartDate', 'bodyHtml', 'bouncedAt', 'clickCount', 'clickedAt',
        'deliveredAt', 'errorBacktrace', 'errorReason', 'followUpTaskScheduledAt', 'followUpTaskType',
        'mailboxAddress', 'mailingType', 'markedAsSpam', 'markedAsSpamAt', 'messageId',
        'notifyThreadCondition', 'notifyThreadScheduledAt', 'notifyThreadStatus',
        'openCount', 'openedAt', 'overrideSafetySettings', 'references', 'repliedAt', 'retryAt', 'retryCount', 'retryInterval',
        'trackLinks', 'trackOpens', 'unsubscribedAt', 'calendar', 'mailbox', 'tasks'
        //Calls
        , 'tags', 'sequence', 'sequenceState', 'sequenceStep', 'opportunity', 'voicemailRecordingUrl', 'userCallType', 'to', 'stateChangedAt', 'state', 'sequenceAction', 'returnedAt', 'recordingUrl', 'from', 'user', 'completedAt', 'answeredAt', 'prospect', 'task', 'createdAt'
        // Tasks
        , 'template', 'taskTheme', 'subject', 'mailings', 'mailing', 'completer', 'calls', 'call',
        'taskType', 'scheduledAt', 'compiledSequenceTemplateHtml', 'autoskipAt',
        'probability', 'prospects'
    );
    public static $oauth_token_url = 'https://api.outreach.io/oauth/token';
    public static $outreachAuthUrl = 'https://api.outreach.io/oauth/authorize';
    public static $redirect_url = 'https://www.brainsell.com/oauth/outreach';
    public static $base_url = 'https://api.outreach.io/api/v2/';
    public static $moduleMapping = array(
        'account' => 'Accounts',
        'opportunity' => 'Opportunities',
        'call' => 'Calls',
        'task' => 'Tasks',
        'mailing' => 'Emails'
    );

    const SUGAR_OUTREACH_OBJECT_MAPPING_PLURAL = array(
        'Accounts' => 'accounts',
        'Leads' => 'prospects',
        'Contacts' => 'prospects',
        'Opportunities' => 'opportunities',
        'Calls' => 'calls',
        'Tasks' => 'tasks',
        'Emails' => 'mailings',
    );
    const SUGAR_OUTREACH_OBJECT_MAPPING_SINGULAR = array(
        'Accounts' => 'account',
        'Leads' => 'prospect',
        'Contacts' => 'prospect',
        'Opportunities' => 'opportunity',
        'Calls' => 'call',
        'Tasks' => 'task',
        'Emails' => 'mailing'
    );
    const OUTREACH_PLURAL_MAPPING = array(
        'opportunity' => 'opportunities',
        'prospect' => 'prospects',
        'account' => 'accounts',
        'call' => 'calls',
        'task' => 'tasks',
        'mailing' => 'mailings',
        'taskPriority' => 'taskPriorities',
        'callPurpose' => 'callPurposes',
        'callDisposition' => 'callDispositions',
        'stage' => 'stages',
        'opportunityStage' => 'opportunityStages'
    );
    const SPECIAL_ARRAY_FIELDS = array(
        'Leads' => array(
            'emails'
        ),
        'Calls' => array(),
    );
    const WORKFLOW_ENABLED_MODULES = array(
        'Leads',
        'Contacts',
        'Accounts',
        'Opportunities',
        'Calls',
        'Tasks',
        'Emails',
    );
    const SYNC_ENABLED_MODULES = array(
        'Leads'
    );
    const UPDATE_ROUTE_UNAVAILABLE = array(
        'Calls',
        'Mailings'
    );
    const SIMPLE_DROPDOWN_KEY_MAPPING = array(
        'Calls' => array(
            'direction' => array(
                'inbound' => 'Inbound',
                'outbound' => 'Outbound'
            ),
            'outcome' => array(
                'completed' => 'Completed',
                'no_answer' => 'No Answer'
            )
        ),
    );
    const DROPDOWN_TO_OBJECT_MAPPING = array(
        'Calls' => array(
            'or_disposition' => 'callDisposition',
            'or_purpose' => 'callPurpose'
        ),
        'Tasks' => array(
            'priority' => 'taskPriority'
        ),
        'Leads' => array(
            'status' => 'stage'
        ),
        'Opportunities' => array(
            'sales_stage' => 'opportunityStage'
        ),
    );
    const ADDITIONAL_RELATIONSHIPS = array(
        'Calls' => array(
            'prospect' => 'parent_id'
        ),
        'Tasks' => array(
            'subject' => array(
                'prospect' => 'parent_id'
            )
        ),
        'Emails' => array(
            'prospect' => 'parent_id'
        ),
    );
    const OUTREACH_OWNER_FIELD_NAME = array(
        "Calls" => "user",
        "Accounts" => "owner",
        "Leads" => "owner",
        "Contacts" => "owner",
        "Opportunities" => "owner",
        "Tasks" => "owner"
    );
    const CUSTOM_SYNC_FIELDS = array(
        'Accounts' => array(
            'tags' => 'tag'
        ),
        'Leads' => array(
            'tags' => 'tag'
        ),
        'Contacts' => array(
            'tags' => 'tag'
        ),
        'Opportunities' => array(
            'closeDate' => 'date_closed',
            'tags' => 'tag'
        ),
        'Calls' => array(
            'tags' => 'tag',
            'to' => 'or_to',
            'from' => 'or_from',
            'note' => 'name',
            'completedAt' => 'date_start'
        ),
        /**
         * Email fields are filled using a single non-db field.
         */
        'Emails' => array(
            'autofill' => 'sl_autofill_email_fields',
            'state' => 'or_state',
            'openCount' => 'or_open_count',
            'openedAt' => 'or_opened_at',
            'errorReason' => 'or_error_reason',
            'deliveredAt' => 'or_delivered_at',
            'bouncedAt' => 'or_bounced_at',
            'clickCount' => 'or_click_count',
            'clickedAt' => 'or_clicked_at',
            'subject' => 'name',
            'bodyHtml' => 'description_html',
            'bodyText' => 'description'
        )
    );
    const EMAILS_SYNCING_FIELDS = array(
        'state' => 'or_state',
        'openCount' => 'or_open_count',
        'openedAt' => 'or_opened_at',
        'errorReason' => 'or_error_reason',
        'deliveredAt' => 'or_delivered_at',
        'bouncedAt' => 'or_bounced_at',
        'clickCount' => 'or_click_count',
        'clickedAt' => 'or_clicked_at',
        'subject' => 'name',
        'bodyHtml' => 'description_html',
        'bodyText' => 'description'
    );
    const CALLS_OUTCOME = array(
        'no_answer' => 'Not Held',
        'answered' => 'Planned',
        'no_answer' => 'Held',
    );
    const ENUM_KEY_MAP = array(
        'Tasks' => array(
            'action' => ''
        )
    );
    const ONE_TO_MANY_RELATIONSHIP_OUTREACH = array(
        'Leads' => array(
            'relationships' => array(
                'account' => array(
                    'sugarField' => 'account_id',
                    'module' => 'Accounts'
                )
            )
        ),
        'Contacts' => array(
            'relationships' => array(
                'account' => array(
                    'sugarField' => 'account_id',
                    'module' => 'Accounts'
                )
            )
        ),
        'Opportunities' => array(
            'relationships' => array(
                'account' => array(
                    'sugarField' => 'account_id',
                    'module' => 'Accounts'
                )
            )
        )
    );
    const ONE_TO_MANY_RELATIONSHIP = array(
        'Leads' => array(
            'accounts' => array(
                'type' => 'prospectAccountRelation',
                'function' => 'setProspectAccountRelation',
                'link' => 'accounts',
                'id' => 'account_id',
                'type' => 'account',
                'outreachField' => 'account',
                'is_array' => false,
            ),
            'opportunities' => array(
                'type' => 'prospectOpportunityRelation',
                'function' => 'setProspectOpportunityRelation',
                'link' => 'opportunities',
                'id' => 'opportunity_id',
                'type' => 'opportunity',
                'outreachField' => 'opportunities',
                'is_array' => true,
            ),
        ),
        'Contacts' => array(
            'accounts' => array(
                'type' => 'prospectAccountRelation',
                'function' => 'setProspectAccountRelation',
                'link' => 'accounts',
                'id' => 'account_id',
                'type' => 'account',
                'outreachField' => 'account',
                'is_array' => false,
            ),
            'opportunities' => array(
                'type' => 'prospectOpportunityRelation',
                'function' => 'setProspectOpportunityRelation',
                'link' => 'opportunities',
                'id' => 'opportunity_id',
                'type' => 'opportunity',
                'outreachField' => 'opportunities',
                'is_array' => true,
            ),
        )
    );
    const MANY_TO_MANY_RELATIONSHIP = array(
        'Opportunities' => array(
            'contacts' => array(
                'type' => 'opportunityProspectRole',
                'function' => 'setProspectOpportunityRelation',
                'link' => 'contacts',
            ),
            'leads' => array(
                'type' => 'opportunityProspectRole',
                'function' => 'setProspectOpportunityRelation',
                'link' => 'leads',
            )
        ),
        'Contacts' => array(
            'opportunities' => array(
                'type' => 'opportunityProspectRole',
                'function' => 'setProspectOpportunityRelation',
                'link' => 'opportunities',
            )
        ),
        'Leads' => array(
            'opportunity' => array(
                'type' => 'opportunityProspectRole',
                'function' => 'setProspectOpportunityRelation',
                'link' => 'opportunity',
            ),
            'accounts' => array(
                'type' => 'prospectAccountRelation',
                'function' => 'setProspectAccountRelation',
                'link' => 'accounts',
            ),
        )
    );
    const UPDATE_DISABLED_MODULES = array('Calls', 'Mailings');
    const CREATE_DISABLED_MODULES = array('Meetings');
    const CURSOR_POLLER = '?sort=updatedAt&filter[updatedAt]=';

    public static function getManyToManyRelation($type, $idA, $idB) {
        switch ($type) {
            case self::OPPORTUNITY_PROSPECT:
                return array(
                    'prospect' => array(
                        'data' => array(
                            'type' => 'prospect',
                            'id' => $idA
                        )
                    ),
                    'opportunity' => array(
                        'data' => array(
                            'type' => 'opportunity',
                            'id' => $idB
                        )
                    ),
                );
            default:
                return array();
        }
    }

    public static function getActivitesModuleURL($sugarModule) {
        return self::SUGAR_OUTREACH_OBJECT_MAPPING_PLURAL[$sugarModule];
    }
}

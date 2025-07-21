<?php

/**
 * Author: Muhammad Tariq Ibrar
 * Email: engrtariqibrar@gmail.com
 * Linkedin: https://www.linkedin.com/in/engrtariqibrar
 */

namespace Outreach;

class OutreachLanguage {

    const OUTREACH_LANGUAGE = [
        'LBL_AUTOMAP_REQ_FIELD_WARN' => 'Warning: Outreach field @key is required and has been automapped. Please map field @key from mapping section to sync real data.',
        'LBL_SYNC_SUCCESS' => 'Synced successfully at @user_time.',
        'LBL_ATTEMPTED_SUCCESS' => 'Sync unsuccessful at @user_time.',
        'LBL_UNABLE_TO_FIND_REL_OPTION' => 'Warning: Sugar Field @sugarField: @key with Outreach field: @apiField will be ignored. Valid options are @options.',
        'LBL_UNABLE_TO_SYNC_REL' => 'Warning: Sugar Field @sugarField will not be synced with Outreach field: @apiField. Please make sure that the field mapping is made correctly.',
        'LBL_FIELDS_MAPPING_NOT_CORRECT' => 'Warning: Sugar Field @sugarField will not be synced with Outreach field: @apiField. Please make sure that selected record is synced with Outreach.',
        'LBL_ENUM_NOT_IN_SUGAR' => 'Warning: Sugar Field @sugarField will not be synced with Outreach field: @apiField. Please Add *@enumValue* option in @sugarField to sync it.',
        'LBL_ENUM_NOT_IN_OR' => 'Warning: Sugar Field @sugarField will not be synced with Outreach field: @apiField. Please Add *@enumValue* option in @apiField to sync it.',
        'LBL_NO_RELATE_IN_SUGAR' => 'Warning: Sugar Field @sugarField will not be synced with Outreach field: @apiField. Record *@href* does not exist in sugar.',
        'LBL_PARENT_TYPE_ERR' => 'Warning: Unsupported flex relate field: @sugarField.',
        'LBL_PARENT_ID_NAME_ERR' => 'Warning: Sugar Field @sugarField will not be synced with Outreach field: @apiField. Either Module type or Record not found.',
        'LBL_CALLS_ALL_MOD' => 'Warning: Sugar Field @sugarField will not be synced with Outreach field: @apiField. Allowed related module must be a Lead or Contact.',
        'LBL_CALLS_REL_NOT_SYN' => 'Warning: Sugar Field @sugarField will not be synced with Outreach field: @apiField. Selected Lead/Contact is not synced with Outreach, Please sync it first and try again.',
        'LBL_ERR_UPDATING' => 'Updates to this module is unsupported by Outreach.',
        'LBL_ERR_CREATE' => 'Cannot create this record in Outreach. Unsupported by outreach API.',
        'LBL_ASSIGNED_USER_ERR' => 'Warning: Sugar assigned user @sugaruser not found in Outreach. In order to sync assigned user with outreach make sure the user spelling in sugarcrm exactly matches with Outreach user.',
        'LBL_OWNER_USER_ERR' => 'Warning: Unable to find outreach user id against logged in user.',
        'LBL_DUP_ERROR' => 'Warning: Outreach record @or_url will not be synced because of the presence of more than one duplicates. Sugar duplicate records with Id @sugarIds found. Merge or delete the mentioned sugar records such that only one record exist.',
        'LBL_ERR_CON' => 'Unable to get response from outreach server, please try again.',
    ];

    public static function parseLangauge($key, $options) {
        if (isset($options)) {
            $label = self::OUTREACH_LANGUAGE[$key];
            foreach ($options as $key => $value) {
                $label = str_replace($key, $value, $label);
            }
            return $label;
        }
        return self::OUTREACH_LANGUAGE[$key];
    }
}

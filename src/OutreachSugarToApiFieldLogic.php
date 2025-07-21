<?php

/**
 * Author: Muhammad Tariq Ibrar
 * Email: tibrar@brainsell.com, engrtariqibrar@gmail.com
 * Linkedin: https://www.linkedin.com/in/engrtariqibrar
 */

namespace Outreach;

use BeanFactory;

class OutreachSugarToApiFieldLogic {

    const SUGAR_ENUM = 'enum';
    const SUGAR_DATE = 'date';
    const SUGAR_DATETIME = 'datetime';
    const SUGAR_DATETIMECOMBO = 'datetimecombo';
    const SUGAR_VARCHAR = 'varchar';
    const SUGAR_PHONE = 'phone';
    const SUGAR_EMAIL = 'email';
    const SUGAR_RELATE = 'relate';
    const SUGAR_TAG = 'tag';
    const SUGAR_PARENT = 'parent_type';

    static $dataToSync = [];
    static $returnArr = [];
    public $sugarBean = null;
    public $outreachApiHelper = null;
    public $outreachSynchronizer = null;

    public function execute($params) {
        $this->outreachApiHelper = new \Outreach\OutreachApiHelper;
        $this->outreachSynchronizer = new \Outreach\OutreachSynchronizer;
        if (!empty($this->sugarBean) && !empty($this->sugarBean->id)) {
            $type = 'varchar';
            if (isset($this->sugarBean->field_defs[$params['sugarField']]['type'])) {
                $type = $this->sugarBean->field_defs[$params['sugarField']]['type'];
            }
            $GLOBALS['log']->info('execute field type:', $type);
            switch ($type) {
                case self::SUGAR_ENUM:
                    $this->executeEnum($params);
                    break;
                case self::SUGAR_DATE:
                    $this->executeTypeDate($params);
                    break;
                case self::SUGAR_DATETIME:
                    $this->executeTypeDate($params);
                    break;
                case self::SUGAR_DATETIMECOMBO:
                    $this->executeTypeDate($params);
                    break;
                case self::SUGAR_PHONE:
                    $this->executeTypePhone($params);
                    break;
                case self::SUGAR_VARCHAR:
                    $this->executeTypeDefault($params);
                    break;
                case self::SUGAR_RELATE:
                    $this->executeTypeRelate($params);
                    break;
                case self::SUGAR_TAG:
                    $this->executeTypeTag($params);
                    break;
                case self::SUGAR_PARENT:
                    $this->executeTypeParent($params);
                    break;
                default:
                    $this->executeTypeDefault($params);
                    break;
            }
            $GLOBALS['log']->info("execute self::returnArr", self::$returnArr);
            return self::$returnArr;
        } else {
            $GLOBALS['log']->fatal('Sugar to Outreach Error: OutreachSugarToApiFieldLogic sugarBean is NULL');
        }
    }

    public function getSugarFieldValue($sugarField, $outReachField) {
        $value = '';
        if ($this->sugarBean->field_defs[$sugarField]['type'] == 'enum') {
            global $app_list_strings;

            if (isset(OutreachConfig::ENUM_KEY_MAP[$this->sugarBean->module_dir][$outReachField])) {
                $value = $this->sugarBean->$sugarField;
            } else if (OutreachConfig::MAP_ENUM_WITH_VALUE) {
                $options = $this->sugarBean->field_defs[$sugarField]['options'];
                if (is_array($options)) {
                    $dropdown = $options;
                } else {
                    $dropdown = $app_list_strings[$options];
                }
                if (isset($dropdown[$this->sugarBean->$sugarField]))
                    $value = $dropdown[$this->sugarBean->$sugarField];
            } else {
                $value = $this->sugarBean->$sugarField;
            }
        }
        return $value;
    }

    public function executeEnum($params) {
        $GLOBALS['log']->info('getOutReachObjectId executeEnum', $params);
        global $app_list_strings;

        self::$dataToSync = $params['dataToSync'];
        $simpleDropdownMapping = array();
        $dropdownToRelationMapping = array();
        if (isset(\Outreach\OutreachConfig::SIMPLE_DROPDOWN_KEY_MAPPING[$this->sugarBean->module_dir]))
            $simpleDropdownMapping = \Outreach\OutreachConfig::SIMPLE_DROPDOWN_KEY_MAPPING[$this->sugarBean->module_dir];
        if (isset(\Outreach\OutreachConfig::DROPDOWN_TO_OBJECT_MAPPING[$this->sugarBean->module_dir]))
            $dropdownToRelationMapping = \Outreach\OutreachConfig::DROPDOWN_TO_OBJECT_MAPPING[$this->sugarBean->module_dir];
        $GLOBALS['log']->info('getOutReachObjectId $simpleDropdownMapping', $simpleDropdownMapping);
        $GLOBALS['log']->info('getOutReachObjectId $dropdownToRelationMapping', $dropdownToRelationMapping);
        if (array_key_exists($params['sugarField'], $dropdownToRelationMapping) && $dropdownToRelationMapping[$params['sugarField']] == $params['apiField']) {
            $fieldValue = $this->getSugarFieldValue($params['sugarField'], $params['apiField']);
            if ($fieldValue) {
                $GLOBALS['log']->info('getOutReachObjectId $fieldValue', $fieldValue);
                $id = $this->outreachSynchronizer->getOrRelIdByName($dropdownToRelationMapping[$params['sugarField']], $fieldValue);
                $GLOBALS['log']->info('outreachSynchronizer getOrRelIdByName $id', $id);
                if ($id) {
                    self::$dataToSync['relationship'][$dropdownToRelationMapping[$params['sugarField']]] = [
                        'data' => [
                            'type' => $dropdownToRelationMapping[$params['sugarField']],
                            'id' => $id,
                        ]
                    ];
                } else {
                    self::$returnArr = [
                        'dataToSync' => self::$dataToSync,
                        'error' => \Outreach\OutreachLanguage::parseLangauge('LBL_ENUM_NOT_IN_OR', [
                            '@apiField' => $params['apiField'],
                            '@sugarField' => $params['sugarField'],
                            '@enumValue' => $fieldValue,
                        ])
                    ];
                    return;
                }
            }
        } else if (isset($simpleDropdownMapping[$params['apiField']])) {
            $options = $simpleDropdownMapping[$params['apiField']];
            $options = array_flip($options);
            if (isset($options[$this->sugarBean->{$params['sugarField']}])) {
                $value = $options[$this->sugarBean->{$params['sugarField']}];
                self::$dataToSync[$params['apiField']] = $value;
            }
        } else {
            $value = $this->getSugarFieldValue($params['sugarField'], $params['apiField']);
            if ($value)
                self::$dataToSync[$params['apiField']] = $value;
        }

        self::$returnArr = [
            'dataToSync' => self::$dataToSync,
        ];
    }

    public function executeTypeRelate($params) {
        $module = $this->sugarBean->field_defs[$params['sugarField']]['module'];
        $link = $this->sugarBean->field_defs[$params['sugarField']]['link'];

        $GLOBALS['log']->info('executeTypeRelate $module', $module);
        $GLOBALS['log']->info('executeTypeRelate $link', $link);
        if (isset($link) && $this->sugarBean->load_relationship($link)) {
            $relatedBean = $this->sugarBean->$link->getBeans();
            foreach ($relatedBean as $relateFieldBean) {
                $GLOBALS['log']->info('$relateFieldBean $relateFieldBean->or_id', $relateFieldBean->or_id);
                if (empty($relateFieldBean->or_id)) {
                    self::$returnArr = [
                        'dataToSync' => self::$dataToSync,
                        'error' => \Outreach\OutreachLanguage::parseLangauge('LBL_UNABLE_TO_SYNC_REL', [
                            '@apiField' => $params['apiField'],
                            '@sugarField' => $params['sugarField'],
                        ])
                    ];
                    return;
                } else {
                    self::$dataToSync['relationship'] = [
                        'data' => [
                            'type' => $params['apiField'],
                            'id' => $relateFieldBean->or_id,
                        ]
                    ];
                    self::$returnArr = [
                        'dataToSync' => self::$dataToSync,
                    ];
                }
            }
            if (empty($relatedBean)) {
                self::$returnArr = [
                    'dataToSync' => self::$dataToSync,
                ];
            }
        }
    }

    public function executeTypeTag($params) {
        self::$dataToSync = $params['dataToSync'];

        $tagBean = \BeanFactory::newBean('Tags');
        $tags = $tagBean->getRelatedModuleRecords($this->sugarBean, [$this->sugarBean->id]);
        if (is_array($tags) && isset($tags[$this->sugarBean->id])) {
            $tags = $tags[$this->sugarBean->id];
            $beanTags = [];
            foreach ($tags as $tag) {
                if (!empty($tag['name']))
                    $beanTags[] = $tag['name'];
            }
            if (count($beanTags) > 0) {
                self::$dataToSync[$params['apiField']] = $beanTags;
                self::$returnArr = [
                    'dataToSync' => self::$dataToSync,
                ];
            }
        }
    }

    public function executeTypePhone($params) {
        self::$dataToSync = $params['dataToSync'];
        if (!empty($this->sugarBean->{$params['sugarField']})) {
            self::$dataToSync[$params['apiField']] = array($this->sugarBean->{$params['sugarField']});
        }

        self::$returnArr = [
            'dataToSync' => self::$dataToSync,
        ];
    }

    public function executeTypeDefault($params) {
        self::$dataToSync = $params['dataToSync'];
        if (!empty($this->sugarBean->{$params['sugarField']})) {
            self::$dataToSync[$params['apiField']] = $this->sugarBean->{$params['sugarField']};
        }

        self::$returnArr = [
            'dataToSync' => self::$dataToSync,
        ];
    }

    public function executeTypeDate($params, $is_time = false) {
        self::$dataToSync = $params['dataToSync'];
        if (strtotime($this->sugarBean->{$params['sugarField']})) {
            $date = new \DateTime($this->sugarBean->{$params['sugarField']});
            $formated_date = $date->format('Y-m-d\TH:i:s\Z');
            if (!empty($this->sugarBean->{$params['sugarField']})) {
                self::$dataToSync[$params['apiField']] = $formated_date;
            }
            self::$returnArr = array(
                'dataToSync' => self::$dataToSync,
            );
        }
    }

    public function executeTypeParent($params) {
        $parent_name = $this->sugarBean->field_defs[$params['sugarField']]['group'];
        $parent_id_name = $this->sugarBean->field_defs[$parent_name]['id_name'];
        $parent_type = $this->sugarBean->{$params['sugarField']};
        $parent_id = $this->sugarBean->{$parent_id_name};

        if (empty($parent_id_name) || empty($parent_id)) {
            self::$returnArr = [
                'error' => \Outreach\OutreachLanguage::parseLangauge('LBL_PARENT_TYPE_ERR', [
                    '@sugarField' => $params['sugarField'],
                ])
            ];
            return;
        }

        if (empty($parent_name) || empty($parent_type)) {
            self::$returnArr = [
                'error' => \Outreach\OutreachLanguage::parseLangauge('LBL_PARENT_ID_NAME_ERR', [
                    '@apiField' => $params['apiField'],
                    '@sugarField' => $params['sugarField'],
                ])
            ];
            return;
        }

        if (!in_array($this->sugarBean->{$params['sugarField']},
                        \Outreach\OutreachConfig::ALL_REL_MOD_FOR_ACT[$this->sugarBean->module_dir]
                )) {
            self::$returnArr = [
                'error' => \Outreach\OutreachLanguage::parseLangauge('LBL_CALLS_ALL_MOD', [
                    '@apiField' => $params['apiField'],
                    '@sugarField' => $params['sugarField'],
                ])
            ];
            return;
        }
        $relatedBean = BeanFactory::getBean($parent_type, $parent_id);
        if (empty($relatedBean->or_id)) {
            self::$returnArr = [
                'error' => \Outreach\OutreachLanguage::parseLangauge('LBL_CALLS_REL_NOT_SYN', [
                    '@apiField' => $params['apiField'],
                    '@sugarField' => $params['sugarField'],
                ])
            ];
            return;
        } else {
            $apiObject = \Outreach\OutreachConfig::SUGAR_OUTREACH_OBJECT_MAPPING_SINGULAR[$this->sugarBean->module_dir];

            self::$dataToSync[$fieldToUpdate] = $relatedBean->or_id;
            self::$returnArr = [
                'dataToSync' => self::$dataToSync,
            ];
        }
    }
}

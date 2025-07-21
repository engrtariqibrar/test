<?php

/**
 * Author: Muhammad Tariq Ibrar
 * Email: tibrar@brainsell.com, engrtariqibrar@gmail.com
 * Linkedin: https://www.linkedin.com/in/engrtariqibrar
 */

namespace Outreach;

use BeanFactory;

class OutreachApiToSugarFieldLogic {

    const SUGAR_ENUM = 'enum';
    const SUGAR_DATE = 'date';
    const SUGAR_PHONE = 'phone';
    const SUGAR_VARCHAR = 'varchar';
    const SUGAR_EMAIL = 'email';
    const SUGAR_DATETIME = 'datetime';
    const SUGAR_DATETIMECOMBO = 'datetimecombo';
    const SUGAR_RELATE = 'relate';
    const SUGAR_TAG = 'tag';
    const SUGAR_PARENT = 'parent_type';
    const OR_EMAIL_AUTOFILL = 'email:autofill';

    static $dataToSync = [];
    static $returnArr = [
        'dataTosync' => '',
    ];
    public $sugarBean = null;

    public function execute($params) {
        if (!empty($this->sugarBean)) {
            self::$returnArr = [
                'dataTosync' => '',
            ];
            if (isset($this->sugarBean->field_defs[$params['sugarField']]['type'])) {
                $type = $this->sugarBean->field_defs[$params['sugarField']]['type'];
                if (isset($this->sugarBean->field_defs[$params['sugarField']]['dbType'])) {
                    $dbType = $this->sugarBean->field_defs[$params['sugarField']]['dbType'];
                    if ($type == self::SUGAR_ENUM && $dbType == 'int') {
                        $type = self::SUGAR_VARCHAR;
                    }
                }
                $GLOBALS['log']->info("type", $type);
                switch ($type) {
                    case self::SUGAR_ENUM:
                        $this->executeEnum($params);
                        break;
                    case self::SUGAR_DATE:
                        $this->executeTypeDate($params);
                        break;
                    case self::SUGAR_DATETIME:
                        $this->executeTypeDate($params, true);
                        break;
                    case self::SUGAR_DATETIMECOMBO:
                        $this->executeTypeDate($params, true);
                        break;
                    case self::SUGAR_RELATE:
                        $this->executeTypeRelate($params);
                        break;
                    case self::SUGAR_PHONE:
                        $this->executeTypePhone($params);
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
            }
            return self::$returnArr;
        } else {
            $GLOBALS['log']->fatal('Sugar to Outreach Error: OutreachApiToSugarFieldLogic sugarBean is NULL');
        }
    }

    public function executeEnum($params) {
        if (!is_object($params['apiFieldValue'])) {
            $enumValue = $params['apiFieldValue'];
            $GLOBALS['log']->info('executeEnum options list name', $this->sugarBean->field_defs[$params['sugarField']]['options']);
            if (isset($this->sugarBean->field_defs[$params['sugarField']]['options'])) {
                $options = $this->sugarBean->field_defs[$params['sugarField']]['options'];
                $GLOBALS['log']->info('executeEnum $options 1', $options);
                if (is_array($options)) {
                    $flippedOptions = array_flip($options);
                    $GLOBALS['log']->info('executeEnum $flippedOptions 1', $flippedOptions);
                    $this->setEnumValue($params['sugarField'], $flippedOptions, $enumValue, $options, $params['apiField']);
                } else {
                    $options = $GLOBALS['app_list_strings'][$options];
                    $GLOBALS['log']->info('executeEnum $options 2', $options);
                    if (is_array($options)) {
                        $flippedOptions = array_flip($options);
                        $GLOBALS['log']->info('executeEnum $flippedOptions 2', $flippedOptions);
                        $this->setEnumValue($params['sugarField'], $flippedOptions, $enumValue, $options, $params['apiField']);
                    }
                }
            }
        }
    }

    private static function setEnumValue($sugarField, $flippedOptions, $enumValue, $options, $apiField) {
        if (!empty($options[$enumValue])) {
            self::$returnArr = [
                'dataToSync' => [
                    $sugarField => $enumValue
                ]
            ];
        } else if (!empty($flippedOptions[$enumValue])) {
            self::$returnArr = [
                'dataToSync' => [
                    $sugarField => $flippedOptions[$enumValue]
                ]
            ];
        } else if (!empty($enumValue)) {
            self::$returnArr = [
                'error' => \Outreach\OutreachLanguage::parseLangauge('LBL_ENUM_NOT_IN_SUGAR', [
                    '@sugarField' => $sugarField,
                    '@apiField' => $apiField,
                    '@enumValue' => $enumValue,
                ])
            ];
        }
    }

    public function executeTypeDate($params, $is_time = false) {
        if (empty($params['apiFieldValue'])) {
            self::$returnArr = [
                'dataToSync' => []
            ];
        }
        if (strtotime($params['apiFieldValue'])) {
            $date = new \DateTime($params['apiFieldValue']);
            self::$returnArr = [
                'dataToSync' => [
                    $params['sugarField'] => $is_time ? $date->format('Y-m-d H:i:s') : $date->format('Y-m-d'),
                ]
            ];
        }
    }

    public function executeTypeRelate($params) {
        $rel_arr = \Outreach\OutreachConfig::ADDITIONAL_RELATIONSHIPS;
        if (isset($rel_arr[$params['apiObject']]) && isset($rel_arr[$params['apiObject']][$params['apiField']])) {
            $apiFieldDef = $rel_arr[$params['apiObject']][$params['apiField']];
        } else {
            $apiFieldDef = null;
        }
        if (empty($apiFieldDef) || empty($params['apiFieldValue']->id)) {
            return;
        }

        $module = $this->sugarBean->field_defs[$params['sugarField']]['module'];
        $id_name = $this->sugarBean->field_defs[$params['sugarField']]['id_name'];
        $link = $this->sugarBean->field_defs[$params['sugarField']]['link'];
        if (!empty($module) && !empty($id_name) && !empty($link)) {
            $relateBean = BeanFactory::getBean($module);
            $relateBean->retrieve_by_string_fields([
                'or_id' => $params['apiFieldValue']->id,
            ]);
            if (empty($relateBean->id)) {
                self::$returnArr = [
                    'error' => \Outreach\OutreachLanguage::parseLangauge('LBL_NO_RELATE_IN_SUGAR', [
                        '@sugarField' => $params['sugarField'],
                        '@apiField' => $params['apiField'],
                        '@href' => $params['apiFieldValue']->_href,
                    ])
                ];
                return;
            } else {
                self::$returnArr = [
                    'dataToSync' => [
                        $id_name => $relateBean->id,
                    ],
                    'forceSync' => true,
                    'sugarField' => $id_name
                ];
            }
        }
    }

    public function executeTypeTag($params) {
        if (is_array($params['apiFieldValue'])) {
            $bean = $this->sugarBean;
            /*
             * Getting existing bean Tags
             */
            $tagBean = BeanFactory::newBean('Tags');
            $tags = $tagBean->getRelatedModuleRecords($bean, [$bean->id]);
            $bean_tags = array();
            if (is_array($tags) && isset($tags[$bean->id])) {
                foreach ($tags[$bean->id] as $key => $tag) {
                    $bean_tags[] = $tag['name'];
                }
            }
            if (count($bean_tags)) {
                if (!is_array($params['apiFieldValue'])) {
                    $params['apiFieldValue'] = $bean_tags;
                } else {
                    $params['apiFieldValue'] = array_merge($params['apiFieldValue'], $bean_tags);
                }
            }
            $tagField = $bean->getTagField();
            $tagFieldProperties = $bean->field_defs[$tagField];
            $tags = [
                "tag" => $params['apiFieldValue'],
            ];
            $SugarFieldTag = new \SugarFieldTag('tag');
            $SugarFieldTag->apiSave($bean, $tags, $tagField, $tagFieldProperties);
        }
    }

    public function executeTypePhone($params) {
        if (is_array($params['apiFieldValue'])) {
            $value = $params['apiFieldValue'][0];
        } else {
            $value = $params['apiFieldValue'];
        }
        self::$returnArr = [
            'dataToSync' => [
                $params['sugarField'] => $value
            ],
        ];
    }

    public function executeTypeDefault($params) {
        self::$returnArr = [
            'dataToSync' => [
                $params['sugarField'] => is_object($params['apiFieldValue']) ? null : $params['apiFieldValue']
            ],
        ];
    }

    public function executeTypeParent($params) {
        if (!is_object($params['apiFieldValue'])) {
            return;
        } else if (isset($params['apiFieldValue']->parent_type) && $params['apiFieldValue']->parent_type != null && isset($params['apiFieldValue']->parent_id) && $params['apiFieldValue']->parent_id != null) {
            self::$returnArr = [
                'dataToSync' => [
                    'parent_type' => $params['apiFieldValue']->parent_type,
                    'parent_id' => $params['apiFieldValue']->parent_id,
                ],
                'multi_field_sync' => true,
            ];
        } else if ($params['apiFieldValue']->id != null) {
            $or_id = $params['apiFieldValue']->id;
            $obj = new \Outreach\OutreachSynchronizer();
            $res = $obj->getBeanForPeople($or_id);
            if (isset($res['id']) && !empty($res['module'])) {
                self::$returnArr = [
                    'dataToSync' => [
                        'parent_type' => $res['module'],
                        'parent_id' => $res['id'],
                    ],
                    'multi_field_sync' => true,
                ];
            }
        }
    }
}

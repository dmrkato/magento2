<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\SalesRule\Model\ResourceModel\Rule;

use Magento\Framework\DB\Select;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote\Address;
use Magento\SalesRule\Api\Data\CouponInterface;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\Rule;

/**
 * Sales Rules resource collection model.
 *
 * @api
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @since 100.0.2
 */
class Collection extends \Magento\Rule\Model\ResourceModel\Rule\Collection\AbstractCollection
{
    /**
     * Store associated with rule entities information map
     *
     * @var array
     */
    protected $_associatedEntitiesMap;

    /**
     * SaleRule Event prefix
     *
     * @var string
     */
    protected $_eventPrefix = 'salesrule_rule_collection';

    /**
     * SaleRule Event object
     *
     * @var string
     */
    protected $_eventObject = 'rule_collection';

    /**
     * @var \Magento\SalesRule\Model\ResourceModel\Rule\DateApplier
     * @since 100.1.0
     */
    protected $dateApplier;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\TimezoneInterface
     */
    protected $_date;

    /**
     * @var Json $serializer
     */
    private $serializer;

    /**
     * @param \Magento\Framework\Data\Collection\EntityFactory $entityFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Framework\Data\Collection\Db\FetchStrategyInterface $fetchStrategy
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $date
     * @param mixed $connection
     * @param \Magento\Framework\Model\ResourceModel\Db\AbstractDb $resource
     * @param Json $serializer Optional parameter for backward compatibility
     */
    public function __construct(
        \Magento\Framework\Data\Collection\EntityFactory $entityFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Data\Collection\Db\FetchStrategyInterface $fetchStrategy,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $date,
        ?\Magento\Framework\DB\Adapter\AdapterInterface $connection = null,
        ?\Magento\Framework\Model\ResourceModel\Db\AbstractDb $resource = null,
        ?Json $serializer = null
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $connection, $resource);
        $this->_date = $date;
        $this->serializer = $serializer ?: \Magento\Framework\App\ObjectManager::getInstance()->get(Json::class);
        $this->_associatedEntitiesMap = $this->getAssociatedEntitiesMap();
    }

    /**
     * Set resource model and determine field mapping
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(\Magento\SalesRule\Model\Rule::class, \Magento\SalesRule\Model\ResourceModel\Rule::class);
        $this->_map['fields']['rule_id'] = 'main_table.rule_id';
    }

    /**
     * Map data for associated entities
     *
     * @param string $entityType
     * @param string $objectField
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return void
     * @since 100.1.0
     */
    protected function mapAssociatedEntities($entityType, $objectField)
    {
        if (!$this->_items) {
            return;
        }

        $entityInfo = $this->_getAssociatedEntityInfo($entityType);
        $ruleIdField = $entityInfo['rule_id_field'];

        $items = [];
        foreach ($this->getItems() as $item) {
            $items[$item->getData($ruleIdField)] = $item;
        }

        $select = $this->getConnection()->select()->from(
            $this->getTable($entityInfo['associations_table'])
        )->where(
            $ruleIdField . ' IN (?)',
            array_keys($items)
        );

        $associatedEntities = $this->getConnection()->fetchAll($select);

        $dataToAdd = [];
        foreach ($associatedEntities as $associatedEntity) {
            //group data
            $dataToAdd[$associatedEntity[$ruleIdField]][] = $associatedEntity[$entityInfo['entity_id_field']];
        }
        foreach ($dataToAdd as $id => $value) {
            $items[$id]->setData($objectField, $value);
        }
    }

    /**
     *  Add website ids and customer group ids to rules data
     *
     * @return $this
     * @throws \Exception
     * @since 100.1.0
     */
    protected function _afterLoad()
    {
        $this->mapAssociatedEntities('website', 'website_ids');
        $this->mapAssociatedEntities('customer_group', 'customer_group_ids');

        $this->setFlag('add_websites_to_result', false);
        return parent::_afterLoad();
    }

    /**
     * Filter collection by specified website, customer group, coupon code, date.
     * Filter collection to use only active rules.
     * Involved sorting by sort_order column.
     *
     * @param int $websiteId
     * @param int $customerGroupId
     * @param string $couponCode
     * @param string|null $now
     * @param Address $address allow extensions to further filter out rules based on quote address
     * @param string[] $couponCodes
     * @throws \Zend_Db_Select_Exception
     * @use $this->addWebsiteGroupDateFilter()
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @return $this
     */
    public function setValidationFilter(
        $websiteId,
        $customerGroupId,
        $couponCode = '',
        $now = null,
        ?Address $address = null,
        array $couponCodes = []
    ) {
        if ($this->getFlag('validation_filter')) {
            return $this;
        }

        $this->prepareSelect($websiteId, $customerGroupId, $now);

        $noCouponRules = $this->getNoCouponCodeSelect();

        if ($couponCode && !in_array($couponCode, $couponCodes)) {
            $couponCodes[] = $couponCode;
        }

        if (!empty($couponCodes)) {
            $couponRules = $this->getCouponCodeSelect($couponCodes);

            $allAllowedRules = $this->getConnection()->select();
            $allAllowedRules->union([$noCouponRules, $couponRules], Select::SQL_UNION_ALL);

            $wrapper = $this->getConnection()->select();
            $wrapper->from($allAllowedRules);

            $this->_select = $wrapper;
        } else {
            $this->_select = $noCouponRules;
        }

        $this->setOrder('sort_order', self::SORT_ORDER_ASC);
        $this->setFlag('validation_filter', true);

        return $this;
    }

    /**
     * Recreate the default select object for specific needs of salesrule evaluation with coupon codes.
     *
     * @param int $websiteId
     * @param int $customerGroupId
     * @param string $now
     */
    private function prepareSelect($websiteId, $customerGroupId, $now)
    {
        $this->getSelect()->reset();
        parent::_initSelect();

        $this->addWebsiteGroupDateFilter($websiteId, $customerGroupId, $now);
    }

    /**
     * Return select object to determine all active rules not needing a coupon code.
     *
     * @return Select
     */
    private function getNoCouponCodeSelect()
    {
        $noCouponSelect = clone $this->getSelect();

        $noCouponSelect->where(
            'main_table.coupon_type = ?',
            Rule::COUPON_TYPE_NO_COUPON
        );

        $noCouponSelect->columns([Coupon::KEY_CODE => new \Zend_Db_Expr('NULL')]);

        return $noCouponSelect;
    }

    /**
     * Determine all active rules that are valid for the given coupon code.
     *
     * @param string[] $couponCodes
     * @return Select
     */
    private function getCouponCodeSelect(array $couponCodes)
    {
        $couponSelect = clone $this->getSelect();

        $this->joinCouponTable($couponCodes, $couponSelect);

        $isAutogenerated =
            $this->getConnection()->quoteInto('main_table.coupon_type = ?', Rule::COUPON_TYPE_AUTO)
            . ' AND ' .
            $this->getConnection()->quoteInto('rule_coupons.type = ?', CouponInterface::TYPE_GENERATED);

        $isValidSpecific =
            $this->getConnection()->quoteInto('(main_table.coupon_type = ?)', Rule::COUPON_TYPE_SPECIFIC)
            . ' AND (' .
            '(main_table.use_auto_generation = 1 AND rule_coupons.type = 1)'
            . ' OR ' .
            '(main_table.use_auto_generation = 0 AND rule_coupons.type = 0)'
            . ')';

        $couponSelect->where(
            "$isAutogenerated OR $isValidSpecific",
            null,
            Select::TYPE_CONDITION
        );

        return $couponSelect;
    }

    /**
     * Join coupon table to select.
     *
     * @param string[] $couponCodes
     * @param Select $couponSelect
     */
    private function joinCouponTable(array $couponCodes, Select $couponSelect)
    {
        $couponJoinCondition =
            'main_table.rule_id = rule_coupons.rule_id'
            . ' AND ' .
            $this->getConnection()->quoteInto('main_table.coupon_type <> ?', Rule::COUPON_TYPE_NO_COUPON)
            . ' AND ' .
            $this->getConnection()->quoteInto('rule_coupons.code IN(?)', $couponCodes);

        $couponSelect->joinInner(
            ['rule_coupons' => $this->getTable('salesrule_coupon')],
            $couponJoinCondition,
            [Coupon::KEY_CODE]
        );
    }

    /**
     * Filter collection by website(s), customer group(s) and date.
     * Filter collection to only active rules.
     * Sorting is not involved
     *
     * @param int $websiteId
     * @param int $customerGroupId
     * @param string|null $now
     * @use $this->addWebsiteFilter()
     * @return $this
     */
    public function addWebsiteGroupDateFilter($websiteId, $customerGroupId, $now = null)
    {
        if (!$this->getFlag('website_group_date_filter')) {
            if ($now === null) {
                $now = $this->_date->date()->format('Y-m-d');
            }

            $this->addWebsiteFilter($websiteId);

            $entityInfo = $this->_getAssociatedEntityInfo('customer_group');
            $connection = $this->getConnection();
            $this->getSelect()->joinInner(
                ['customer_group_ids' => $this->getTable($entityInfo['associations_table'])],
                $connection->quoteInto(
                    'main_table.' .
                    $entityInfo['rule_id_field'] .
                    ' = customer_group_ids.' .
                    $entityInfo['rule_id_field'] .
                    ' AND customer_group_ids.' .
                    $entityInfo['entity_id_field'] .
                    ' = ?',
                    (int)$customerGroupId
                ),
                []
            );

            // exclude websites that are limited for customer group
            $this->getSelect()->joinLeft(
                ['cgw' => $this->getTable('customer_group_excluded_website')],
                'customer_group_ids.' .
                $entityInfo['entity_id_field'] .
                ' = cgw.' .
                $entityInfo['entity_id_field'] . ' AND ' . $websiteId . ' = cgw.website_id',
                []
            )->where(
                'cgw.website_id IS NULL',
                $websiteId,
                \Zend_Db::INT_TYPE
            );

            $this->getDateApplier()->applyDate($this->getSelect(), $now);

            $this->addIsActiveFilter();

            $this->setFlag('website_group_date_filter', true);
        }

        return $this;
    }

    /**
     * Add primary coupon to collection
     *
     * @return $this
     */
    public function _initSelect()
    {
        parent::_initSelect();
        $this->getSelect()->joinLeft(
            ['rule_coupons' => $this->getTable('salesrule_coupon')],
            'main_table.rule_id = rule_coupons.rule_id AND rule_coupons.is_primary = 1',
            ['code']
        );
        return $this;
    }

    /**
     * Find product attribute in conditions or actions
     *
     * @param string $attributeCode
     * @return $this
     */
    public function addAttributeInConditionFilter($attributeCode)
    {
        $match = sprintf('%%%s%%', substr($this->serializer->serialize(['attribute' => $attributeCode]), 1, -1));
        /**
         * Information about conditions and actions stored in table as JSON encoded array
         * in fields conditions_serialized and actions_serialized.
         * If you want to find rules that contains some particular attribute, the easiest way to do so is serialize
         * attribute code in the same way as it stored in the serialized columns and execute SQL search
         * with like condition.
         * Table
         * +-------------------------------------------------------------------+
         * |     conditions_serialized       |         actions_serialized      |
         * +-------------------------------------------------------------------+
         * | {..."attribute":"attr_name"...} | {..."attribute":"attr_name"...} |
         * +---------------------------------|---------------------------------+
         * From attribute code "attr_code", will be generated such SQL:
         * `condition_serialized` LIKE '%"attribute":"attr_name"%'
         *      OR `actions_serialized` LIKE '%"attribute":"attr_name"%'
         */
        $field = $this->_getMappedField('conditions_serialized');
        $cCond = $this->_getConditionSql($field, ['like' => $match]);
        $field = $this->_getMappedField('actions_serialized');
        $aCond = $this->_getConditionSql($field, ['like' => $match]);

        $this->getSelect()->where(
            sprintf('(%s OR %s)', $cCond, $aCond),
            null,
            Select::TYPE_CONDITION
        );

        return $this;
    }

    /**
     * Excludes price rules with generated specific coupon codes from collection
     *
     * @return $this
     */
    public function addAllowedSalesRulesFilter()
    {
        $this->addFieldToFilter('main_table.use_auto_generation', ['neq' => 1]);

        return $this;
    }

    /**
     * Limit rules collection by specific customer group
     *
     * @param int $customerGroupId
     * @return $this
     * @since 100.1.0
     */
    public function addCustomerGroupFilter($customerGroupId)
    {
        $entityInfo = $this->_getAssociatedEntityInfo('customer_group');
        if (!$this->getFlag('is_customer_group_joined')) {
            $this->setFlag('is_customer_group_joined', true);
            $this->getSelect()->join(
                ['customer_group' => $this->getTable($entityInfo['associations_table'])],
                $this->getConnection()
                    ->quoteInto('customer_group.' . $entityInfo['entity_id_field'] . ' = ?', $customerGroupId)
                . ' AND main_table.' . $entityInfo['rule_id_field'] . ' = customer_group.'
                . $entityInfo['rule_id_field'],
                []
            );
        }
        return $this;
    }

    // phpcs:disable
    /**
     * Getter for _associatedEntitiesMap property
     *
     * @return array
     * @deprecated 100.1.0
     */
    private function getAssociatedEntitiesMap()
    {
        if (!$this->_associatedEntitiesMap) {
            $this->_associatedEntitiesMap = \Magento\Framework\App\ObjectManager::getInstance()
                // @phpstan-ignore-next-line - this is a virtual type defined in di.xml
                ->get(\Magento\SalesRule\Model\ResourceModel\Rule\AssociatedEntityMap::class)
                ->getData();
        }
        return $this->_associatedEntitiesMap;
    }

    /**
     * Getter for dateApplier property
     *
     * @return DateApplier
     * @deprecated 100.1.0
     */
    private function getDateApplier()
    {
        if (null === $this->dateApplier) {
            $this->dateApplier = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\SalesRule\Model\ResourceModel\Rule\DateApplier::class);
        }

        return $this->dateApplier;
    }
    // phpcs:enable
}

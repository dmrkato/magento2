<?php
/**
 * Copyright 2011 Adobe
 * All Rights Reserved.
 */
namespace Magento\Catalog\Model\Product\Attribute;

use Magento\Framework\Api\AttributeValueFactory;

class Group extends \Magento\Eav\Model\Entity\Attribute\Group
{
    /**
     * Attribute collection factory for Product
     *
     * @var \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory
     */
    protected $_attributeCollectionFactory;

    /**
     * Group constructor.
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param \Magento\Framework\Filter\Translit $translitFilter
     * @param \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory $attributeCollectionFactory
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        \Magento\Framework\Filter\Translit $translitFilter,
        \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory $attributeCollectionFactory,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->_attributeCollectionFactory = $attributeCollectionFactory;
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $translitFilter,
            $resource,
            $resourceCollection,
            $data
        );
    }

    /**
     * Check if group contains system attributes
     *
     * @return bool
     */
    public function hasSystemAttributes()
    {
        $result = false;
        /** @var $attributesCollection \Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection */
        $attributesCollection = $this->_attributeCollectionFactory->create();
        $attributesCollection->setAttributeGroupFilter($this->getId());
        foreach ($attributesCollection as $attribute) {
            if (!$attribute->getIsUserDefined()) {
                $result = true;
                break;
            }
        }
        return $result;
    }
}

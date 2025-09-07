<?php
namespace Bonlineco\FlashSale\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute as EavAttribute;
use Magento\Catalog\Model\Product\Attribute\Backend\Price as BackendPrice;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;

class AddFlashSaleAttributes implements DataPatchInterface
{
    /** @var ModuleDataSetupInterface */
    private $moduleDataSetup;

    /** @var EavSetupFactory */
    private $eavSetupFactory;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EavSetupFactory $eavSetupFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        // Flash Sale From Date (datetime)
        if (!$eavSetup->getAttributeId(Product::ENTITY, 'flash_sale_from_date')) {
            $eavSetup->addAttribute(
                Product::ENTITY,
                'flash_sale_from_date',
                [
                    'type' => 'datetime',
                    'label' => 'Flash Sale From',
                    'input' => 'date',
                    'backend' => 'Magento\\Eav\\Model\\Entity\\Attribute\\Backend\\Datetime',
                    'required' => false,
                    'sort_order' => 26,
                    'global' => ScopedAttributeInterface::SCOPE_WEBSITE,
                    'visible' => true,
                    'user_defined' => true,
                    'group' => 'Advanced Pricing',
                    'apply_to' => null,
                    'used_in_product_listing' => true,
                    'visible_on_front' => false,
                ]
            );
        }

        // Flash Sale To Date (datetime)
        if (!$eavSetup->getAttributeId(Product::ENTITY, 'flash_sale_to_date')) {
            $eavSetup->addAttribute(
                Product::ENTITY,
                'flash_sale_to_date',
                [
                    'type' => 'datetime',
                    'label' => 'Flash Sale To',
                    'input' => 'date',
                    'backend' => 'Magento\\Eav\\Model\\Entity\\Attribute\\Backend\\Datetime',
                    'required' => false,
                    'sort_order' => 27,
                    'global' => ScopedAttributeInterface::SCOPE_WEBSITE,
                    'visible' => true,
                    'user_defined' => true,
                    'group' => 'Advanced Pricing',
                    'apply_to' => null,
                    'used_in_product_listing' => true,
                    'visible_on_front' => false,
                ]
            );
        }

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    public static function getDependencies()
    {
        return [];
    }

    public function getAliases()
    {
        return [];
    }
}

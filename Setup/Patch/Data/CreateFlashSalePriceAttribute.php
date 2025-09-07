<?php
namespace Bonlineco\FlashSale\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Catalog\Model\Product\Attribute\Backend\Price as BackendPrice;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;

class CreateFlashSalePriceAttribute implements DataPatchInterface
{
    private $moduleDataSetup;
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
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        // Create flash_sale_price attribute following core pattern
        $eavSetup->addAttribute(
            Product::ENTITY,
            'flash_sale_price',
            [
                'type' => 'decimal',
                'label' => 'Flash Sale Price',
                'input' => 'price',
                'backend' => BackendPrice::class,
                'required' => false,
                'sort_order' => 25,
                'global' => ScopedAttributeInterface::SCOPE_WEBSITE,
                'used_in_product_listing' => true,
                'apply_to' => 'simple,virtual',
                'group' => 'Prices',
                'is_used_in_grid' => true,
                'is_visible_in_grid' => false,
                'is_filterable_in_grid' => true,
            ]
        );
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
